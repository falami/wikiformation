<?php

declare(strict_types=1);

namespace App\Service\Automation;

use App\Entity\{Attestation, Inscription, Qcm, QcmAssignment, SatisfactionAssignment};
use App\Entity\Automation\{TrainingWorkflow, WorkflowParticipant, WorkflowTask};
use App\Enum\{QcmPhase, StatusInscription, StatusSession};
use App\Service\Email\MailerManager;
use App\Service\Pdf\PdfManager;
use App\Service\Qcm\QcmAssigner;
use App\Service\Sequence\AttestationNumberGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/** The runner must claim a task durably before calling this service. */
final class WorkflowActionHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailerManager $mailer,
        private readonly PdfManager $pdf,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
        private readonly WorkflowPortal $portal,
        private readonly AttestationNumberGenerator $numbers,
        private readonly WorkflowCertificateStorage $certificates,
        private readonly QcmAssigner $qcmAssigner,
        private readonly ?WorkflowParticipantImporter $participantImporter = null,
    ) {}

    public function handle(WorkflowTask $task): array
    {
        $w = $task->getWorkflow();
        $session = $w?->getSession();
        $convention = $w?->getConvention();
        $entiteId = $w?->getEntite()?->getId();
        if (!$w || !$session || !$convention || !$entiteId || !$w->getCreateur()
            || $session->getEntite()?->getId() !== $entiteId || $convention->getEntite()?->getId() !== $entiteId
            || $convention->getSession()?->getId() !== $session->getId()) {
            return $this->blocked('Le dossier et ses documents doivent appartenir au même organisme et à la même session.');
        }
        if ($session->getStatus() === StatusSession::CANCELED) return $this->skipped('Session annulée.');
        if (!$session->getFormation() || $session->getFormation()->getEntite()?->getId() !== $entiteId) return $this->blocked('Formation absente ou extérieure à cet organisme.');

        return match ($task->getAction()) {
            'convention' => $this->convention($w),
            'participants' => $this->participants($w),
            'participants_import' => $this->importParticipants($w),
            'trainer' => $this->trainer($w, $task->getTargetId()),
            'attendance' => $this->attendance($w),
            'account', 'convocation', 'satisfaction', 'evaluation', 'attestation', 'certificate' => $this->participantAction($w, $task),
            'renewal' => $this->renewal($w),
            default => $this->blocked('Cette étape n’est pas prise en charge.'),
        };
    }

    private function convention(TrainingWorkflow $w): array
    {
        if ($w->getConvention()->isSigned()) return $this->skipped('Convention déjà signée.');
        if (!$this->portal->canSign($w)) return $this->blocked('La signature du portail nécessite une convention entreprise et une session à venir.');
        return $this->send($w, $w->getContactEmail(), 'Votre convention de formation à signer',
            'Votre convention est prête. Vous pouvez la consulter, la télécharger et la signer depuis votre dossier sécurisé.',
            $this->portal->url($w), 'Consulter et signer la convention');
    }

    private function participants(TrainingWorkflow $w): array
    {
        return $this->send($w, $w->getContactEmail(), 'Préparons la liste de vos participants',
            'Merci de compléter ou vérifier les noms, prénoms et dates de naissance des participants. Les adresses e-mail peuvent être ajoutées plus tard. Votre dossier permet de transmettre la liste à l’organisme.',
            $this->portal->url($w), 'Compléter les participants');
    }

    private function trainer(TrainingWorkflow $w, ?int $id): array
    {
        foreach ($w->getSession()->getFormateursEffectifs() as $trainer) {
            if ($trainer->getId() !== $id) continue;
            if ($trainer->getEntite()?->getId() !== $w->getEntite()->getId()) return $this->blocked('Le formateur appartient à un autre organisme.');
            $dates = [];
            foreach ($w->getSession()->getJoursPourFormateur($trainer) as $day) {
                $dates[] = $day->getDateDebut()->format('d/m/Y H:i') . ' – ' . $day->getDateFin()->format('H:i');
            }
            $documents = $this->url('app_formateur_documents_index', ['entite' => $w->getEntite()->getId()]);
            $message = 'Votre intervention pour « ' . $w->getConvention()->getIntituleFormationEffectif() . ' » approche.'
                . "\n" . implode("\n", $dates)
                . "\nConsultez les participants, les horaires et les documents depuis votre espace."
                . "\nModèles à télécharger avant la formation : " . $documents;
            return $this->send($w, (string) $trainer->getUtilisateur()?->getEmail(), 'Votre prochaine intervention — ' . $w->getSession()->getCode(),
                $message, $this->url('app_formateur_session_show', ['entite' => $w->getEntite()->getId(), 'id' => $w->getSession()->getId()]), 'Préparer mon intervention');
        }
        return $this->skipped('Le formateur n’est plus affecté à cette session.');
    }

    private function importParticipants(TrainingWorkflow $w): array
    {
        if (($w->getOptions()['autoImportParticipants'] ?? false) !== true) return $this->blocked('Import automatique désactivé. Vérifiez la liste puis importez les participants depuis le dossier.');
        $rows = $this->em->getRepository(WorkflowParticipant::class)->findBy(['workflow' => $w, 'inscription' => null]);
        $canImport = array_filter($rows, static fn(WorkflowParticipant $row): bool => filter_var($row->getEmail(), FILTER_VALIDATE_EMAIL) !== false);
        if ($canImport && !$this->participantImporter) return $this->blocked('Le service d’import des participants doit être configuré.');
        $result = $canImport ? $this->participantImporter->import($w, $w->getCreateur()) : ['imported' => 0, 'pending' => count($rows)];
        $count = 0;
        foreach ($w->getConvention()->getInscriptions() as $i) {
            if ($i->getEntite()?->getId() === $w->getEntite()->getId() && $i->getSession()?->getId() === $w->getSession()->getId()
                && !in_array($i->getStatus(), [StatusInscription::ANNULE, StatusInscription::ABSENT], true)) ++$count;
        }
        $expected = $w->getConvention()->getEffectifTotal();
        if ($result['pending'] > 0 || $count < $expected || $count === 0) return ['status' => 'blocked',
            'detail' => sprintf('%d participant(s) inscrit(s) sur %d prévu(s). Complétez les e-mails ou vérifiez les correspondances de comptes pour terminer les inscriptions.', $count, $expected),
            'snapshot' => $result + ['enrolled' => $count, 'expected' => $expected]];
        return ['status' => 'done', 'detail' => 'Les participants collectés sont inscrits. Leurs convocations et invitations sont planifiées.', 'snapshot' => $result + ['enrolled' => $count]];
    }

    private function attendance(TrainingWorkflow $w): array
    {
        if (!$w->getSession()->isEmargementRequis()) return $this->skipped('Les émargements de cette session sont gérés par le donneur d’ordre.');
        try {
            $url = $this->url('app_workflow_attendance', ['entite' => $w->getEntite()->getId(), 'session' => $w->getSession()->getId()]);
        } catch (RouteNotFoundException) {
            return $this->blocked('L’accès QR aux émargements doit être configuré avant cette étape.');
        }
        return ['status' => 'done', 'detail' => 'Accès aux émargements prêt. Chaque stagiaire doit s’identifier avant de signer.', 'snapshot' => ['url' => $url]];
    }

    private function participantAction(TrainingWorkflow $w, WorkflowTask $task): array
    {
        $i = null;
        foreach ($w->getConvention()->getInscriptions() as $candidate) {
            if ($candidate->getId() === $task->getTargetId()) { $i = $candidate; break; }
        }
        if (!$i) return $this->skipped('Le participant n’est plus rattaché à cette convention.');
        if ($i->getEntite()?->getId() !== $w->getEntite()->getId() || $i->getSession()?->getId() !== $w->getSession()->getId() || !$i->getStagiaire()) return $this->blocked('L’inscription ne correspond pas à ce dossier.');
        if (in_array($i->getStatus(), [StatusInscription::ANNULE, StatusInscription::ABSENT], true)) return $this->skipped('Inscription annulée ou stagiaire absent.');
        $to = (string) $i->getStagiaire()->getEmail();
        if ($task->getAction() === 'account') return $this->account($w, $i, $to);
        if ($task->getAction() === 'convocation') {
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) $to = $w->getContactEmail();
            return $this->sendDocument($w, $i, $to, 'Votre convocation de formation', 'Vous trouverez votre convocation en pièce jointe. Merci de vérifier le lieu et les horaires. Si vous êtes le contact entreprise, merci de la remettre au participant concerné.', 'pdf/convocation.html.twig', 'convocation-' . $i->getId() . '.pdf');
        }
        if (in_array($task->getAction(), ['satisfaction', 'evaluation'], true) && !filter_var($to, FILTER_VALIDATE_EMAIL)) return $this->blocked('Ajoutez l’adresse e-mail du stagiaire pour lui donner accès à son questionnaire personnel.');
        if ($task->getAction() === 'satisfaction') return $this->satisfaction($w, $i, $to);
        if ($task->getAction() === 'evaluation') return $this->evaluation($w, $i, $to);
        if ($task->getAction() === 'attestation') return $this->attestation($w, $i, $to);
        return $this->certificate($w, $i, $to);
    }

    private function satisfaction(TrainingWorkflow $w, Inscription $i, string $to): array
    {
        $template = $w->getSession()->getFormation()->getSatisfactionTemplate();
        if (!$template || !$template->isActive() || $template->getEntite()?->getId() !== $w->getEntite()->getId()) return $this->blocked('Associez un questionnaire de satisfaction actif à cette formation.');
        // The participant page looks up a single assignment per session/person: do not create a competing one.
        $assignment = $this->em->getRepository(SatisfactionAssignment::class)->findOneBy(['session' => $w->getSession(), 'stagiaire' => $i->getStagiaire()]);
        if ($assignment && ($assignment->getEntite()?->getId() !== $w->getEntite()->getId() || $assignment->getTemplate()?->getId() !== $template->getId())) return $this->blocked('Vérifiez l’affectation du questionnaire de ce stagiaire avant l’envoi.');
        if ($assignment?->getAttempt()?->isSubmitted()) return $this->skipped('Questionnaire de satisfaction déjà complété.');
        if (!$assignment) {
            $assignment = (new SatisfactionAssignment())->setEntite($w->getEntite())->setCreateur($w->getCreateur())
                ->setSession($w->getSession())->setStagiaire($i->getStagiaire())->setInscription($i)->setTemplate($template);
            $this->em->persist($assignment);
            $this->em->flush();
        }
        return $this->send($w, $to, 'Votre avis sur la formation', 'Merci de prendre quelques minutes pour évaluer votre formation. Vos réponses nous aident à améliorer nos prestations.',
            $this->url('app_stagiaire_satisfaction_fill', ['entite' => $w->getEntite()->getId(), 'inscription' => $i->getId()]), 'Donner mon avis', ['assignmentId' => $assignment->getId()]);
    }

    private function account(TrainingWorkflow $w, Inscription $i, string $to): array
    {
        if (($i->getMeta()['workflowNewAccount'] ?? false) !== true || $i->getStagiaire()->isVerified()) return $this->skipped('Ce stagiaire dispose déjà de son compte.');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return $this->blocked('Renseignez une adresse e-mail valide pour inviter le stagiaire.');
        if ($i->getStagiaire()->getEntite()?->getId() !== $w->getEntite()->getId()) return $this->blocked('Le nouveau compte n’appartient pas à cet organisme.');
        $expires = new \DateTimeImmutable('+7 days');
        $token = bin2hex(random_bytes(32));
        $i->getStagiaire()->setResetToken($token)->setResetTokenExpiresAt($expires);
        $this->em->flush();
        return $this->send($w, $to, 'Bienvenue dans votre espace stagiaire',
            'Votre organisme vous invite à accéder à votre espace de formation. Définissez votre mot de passe avec ce lien personnel valable pendant 7 jours. Vous pourrez ensuite consulter vos documents, signer vos émargements et répondre aux questionnaires.',
            $this->url('app_reset_password', ['token' => $token]), 'Définir mon mot de passe', ['inscriptionId' => $i->getId(), 'expiresAt' => $expires->format(DATE_ATOM)]);
    }

    private function evaluation(TrainingWorkflow $w, Inscription $i, string $to): array
    {
        $selectedId = $w->getOptions()['postQcmId'] ?? null;
        $qcm = null;
        if ($selectedId !== null) {
            $qcm = $this->em->getRepository(Qcm::class)->findOneBy(['id' => $selectedId, 'entite' => $w->getEntite(), 'isActive' => true]);
            if (!$qcm) return $this->blocked('Le QCM choisi n’est plus disponible. Sélectionnez un modèle actif de cet organisme dans les réglages du dossier.');
        }
        $repository = $this->em->getRepository(QcmAssignment::class);
        $assignment = $repository->findOneBy(['inscription' => $i, 'phase' => QcmPhase::POST], ['id' => 'DESC']);
        if (!$assignment && $qcm) {
            // Several client dossiers can refer to one enrollment. Lock it while using the
            // existing assigner so concurrent workflows cannot create competing POST tests.
            $assignment = $this->em->wrapInTransaction(function () use ($w, $i, $qcm, $repository): ?QcmAssignment {
                $this->em->refresh($i, LockMode::PESSIMISTIC_WRITE);
                $existing = $repository->findOneBy(['inscription' => $i, 'phase' => QcmPhase::POST], ['id' => 'DESC']);
                if ($existing) return $existing;
                $this->qcmAssigner->assignForInscription($w->getSession(), $i, $qcm, QcmPhase::POST, $w->getCreateur(), $w->getEntite());
                $this->em->flush();
                return $repository->findOneBy(['inscription' => $i, 'phase' => QcmPhase::POST], ['id' => 'DESC']);
            });
        }
        if (!$assignment) return $this->blocked('Choisissez le QCM de fin de formation dans les réglages du dossier, ou affectez une évaluation à ce stagiaire.');
        if ($assignment->getEntite()?->getId() !== $w->getEntite()->getId() || $assignment->getSession()?->getId() !== $w->getSession()->getId()
            || $assignment->getQcm()?->getEntite()?->getId() !== $w->getEntite()->getId() || !$assignment->getQcm()->isActive()
            || ($qcm && $assignment->getQcm()->getId() !== $qcm->getId())) return $this->blocked('Une affectation différente ou inactive existe déjà. Vérifiez le QCM de ce stagiaire avant l’envoi.');
        if ($assignment->isSubmitted()) return $this->skipped('Évaluation de fin de formation déjà complétée.');
        return $this->send($w, $to, 'Votre évaluation de fin de formation', 'Votre évaluation de fin de formation est disponible dans votre espace personnel.',
            $this->url('app_stagiaire_qcm_show', ['entite' => $w->getEntite()->getId(), 'id' => $assignment->getId()]), 'Accéder à mon évaluation', ['assignmentId' => $assignment->getId()]);
    }

    private function attestation(TrainingWorkflow $w, Inscription $i, string $to): array
    {
        if ($i->getStatus() !== StatusInscription::TERMINE || $i->getTauxAssiduite() === null || $i->getTauxAssiduite() <= 0 || $i->getTauxAssiduite() > 100) return $this->blocked('Validez la fin de l’inscription et le taux d’assiduité avant de délivrer une attestation.');
        $attestation = $i->getAttestation();
        if ($attestation && $attestation->getEntite()?->getId() !== $w->getEntite()->getId()) return $this->blocked('L’attestation appartient à un autre organisme.');
        if (!$attestation) {
            $hours = $w->getSession()->getDureeFormationHeures() * $i->getTauxAssiduite() / 100;
            if ($hours <= 0) return $this->blocked('La durée pédagogique de la session doit être renseignée.');
            $attestation = (new Attestation())->setEntite($w->getEntite())->setCreateur($w->getCreateur())->setInscription($i)
                ->setNumero($this->numbers->nextForEntite($w->getEntite()->getId()))->setDureeHeures(round($hours, 2))
                ->setDateDelivrance(new \DateTimeImmutable())->setReussi($i->isReussi());
            $i->setAttestation($attestation);
            $this->em->persist($attestation);
            $this->em->flush();
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) $to = $w->getContactEmail();
        return $this->sendDocument($w, $i, $to, 'Votre attestation de réalisation', 'Vous trouverez votre attestation de réalisation en pièce jointe. Si vous êtes le contact entreprise, merci de la remettre au participant concerné.', 'pdf/attestation.html.twig', $attestation->getNumero() . '.pdf', ['attestation' => $attestation, 'tauxAssiduite' => $i->getTauxAssiduite()]);
    }

    private function certificate(TrainingWorkflow $w, Inscription $i, string $to): array
    {
        if ($i->getStatus() !== StatusInscription::TERMINE || !$i->isReussi()) return $this->blocked('Validez la réussite du stagiaire avant l’envoi de son certificat métier.');
        $certificate = $this->certificates->getValidated($i);
        if (!$certificate) return $this->blocked('Déposez le certificat métier délivré par la personne habilitée dans ce dossier.');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) $to = $w->getContactEmail();
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return $this->blocked('Adresse de destination invalide.');
        $subject = 'Votre certificat — ' . $w->getConvention()->getIntituleFormationEffectif();
        $message = 'Vous trouverez votre certificat en pièce jointe. Conservez-le pour préparer votre prochain recyclage. Si vous êtes le contact entreprise, merci de le remettre au participant concerné.';
        $html = $this->twig->render('automation/email.html.twig', ['entite'=>$w->getEntite(), 'heading'=>$subject, 'message'=>$message]);
        $this->mailer->sendHtmlWithFile($w->getEntite(), $to, $subject, $html, $message, $certificate['path'], 'certificat-' . $i->getId() . '.pdf');
        return ['status'=>'sent', 'detail'=>'Certificat validé remis au transport de messagerie.', 'snapshot'=>['to'=>$to,'subject'=>$subject,'inscriptionId'=>$i->getId(),'sha256'=>$certificate['hash'],'version'=>$certificate['version']]];
    }

    private function renewal(TrainingWorkflow $w): array
    {
        if (!$w->isRenewalEnabled() || $w->isRenewalOptOut()) return $this->skipped('Le rappel de recyclage est désactivé.');
        if (!$w->getValidityMonths()) return $this->blocked('Renseignez la durée de validité du certificat avant le rappel.');
        $now = WorkflowTime::date('today');
        $eligible = [];
        foreach ($w->getConvention()->getInscriptions() as $i) {
            $certificate = $this->certificates->getValidated($i);
            if ($i->getEntite()?->getId() !== $w->getEntite()->getId() || $i->getStatus() !== StatusInscription::TERMINE || !$i->isReussi() || !$certificate) continue;
            $issued = WorkflowTime::date($certificate['issuedOn']);
            $expires = WorkflowPlanner::addMonths($issued, $w->getValidityMonths());
            if ($expires < $now || WorkflowPlanner::addMonths($issued, $w->getValidityMonths() - 2) > $now) continue;
            $newer = false;
            $otherInscriptions = $this->em->createQueryBuilder()->select('i')->from(Inscription::class, 'i')->join('i.session','s')
                ->where('i.entite = :entity')->andWhere('s.entite = :entity')->andWhere('i.stagiaire = :trainee')
                ->andWhere('s.formation = :formation')->andWhere('i.status = :completed')->andWhere('i.reussi = true')
                ->setParameter('entity',$w->getEntite())->setParameter('trainee',$i->getStagiaire())->setParameter('formation',$w->getSession()->getFormation())
                ->setParameter('completed',StatusInscription::TERMINE)->getQuery()->getResult();
            foreach ($otherInscriptions as $other) {
                $newCertificate = $this->certificates->getValidated($other);
                if ($newCertificate && WorkflowTime::date($newCertificate['issuedOn']) > $issued) { $newer = true; break; }
            }
            if (!$newer) $eligible[$i->getStagiaire()->getId()] = $expires->format('d/m/Y');
        }
        if (!$eligible) return $this->skipped('Aucun certificat validé n’arrive à échéance dans les deux prochains mois, ou le recyclage a déjà été réalisé.');
        $message = count($eligible) . ' collaborateur(s) arrivent à échéance pour « ' . $w->getConvention()->getIntituleFormationEffectif()
            . ' ». Souhaitez-vous organiser leur recyclage ? Répondez à cet e-mail pour convenir des prochaines dates.'
            . "\nÉchéances : " . implode(', ', array_unique(array_values($eligible)))
            . "\nVous pouvez désactiver ces rappels avec le lien ci-dessous.";
        return $this->send($w, $w->getContactEmail(), 'Anticipez le recyclage de vos collaborateurs', $message,
            $this->portal->optOutUrl($w), 'Gérer mes rappels', ['participants' => array_keys($eligible), 'expiries' => array_values($eligible)]);
    }

    private function sendDocument(TrainingWorkflow $w, Inscription $i, string $to, string $subject, string $message, string $template, string $filename, array $extra = []): array
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return $this->blocked('Adresse de destination manquante ou invalide.');
        try {
            $bytes = $this->pdf->createPortraitBytes($this->twig->render($template, $extra + [
                'entite' => $w->getEntite(), 'session' => $w->getSession(), 'formation' => $w->getSession()->getFormation(),
                'inscription' => $i, 'stagiaire' => $i->getStagiaire(),
            ]));
            $html = $this->twig->render('automation/email.html.twig', ['entite' => $w->getEntite(), 'heading' => $subject, 'message' => $message]);
        } catch (\Throwable) {
            return $this->blocked('Le document n’a pas pu être préparé. Vérifiez son modèle avant de reprendre cette étape.');
        }
        // Deliberately outside the catch: an uncertain transport outcome must not be retried automatically.
        $this->mailer->sendHtmlWithAttachment($w->getEntite(), $to, $subject, $html, $message, $bytes, $filename);
        return ['status' => 'sent', 'detail' => 'Document remis au transport de messagerie.', 'snapshot' => [
            'to' => $to, 'subject' => $subject, 'inscriptionId' => $i->getId(), 'filename' => $filename, 'sha256' => hash('sha256', $bytes),
        ]];
    }

    private function send(TrainingWorkflow $w, string $to, string $subject, string $message, string $url, string $button, array $snapshot = []): array
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return $this->blocked('Adresse de destination manquante ou invalide.');
        try {
            $html = $this->twig->render('automation/email.html.twig', ['entite' => $w->getEntite(), 'heading' => $subject, 'message' => $message, 'url' => $url, 'button' => $button]);
        } catch (\Throwable) {
            return $this->blocked('Le message n’a pas pu être préparé. Vérifiez son modèle.');
        }
        $this->mailer->sendHtml($w->getEntite(), $to, $subject, $html, $message . "\n\n" . $url);
        // Private portal tokens must not be copied into the administration history.
        return ['status' => 'sent', 'detail' => 'Message remis au transport de messagerie.', 'snapshot' => $snapshot + ['to' => $to, 'subject' => $subject]];
    }

    private function url(string $route, array $parameters): string
    {
        return $this->router->generate($route, $parameters + ['_locale' => 'fr'], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function blocked(string $detail): array { return ['status' => 'blocked', 'detail' => $detail, 'snapshot' => []]; }
    private function skipped(string $detail): array { return ['status' => 'skipped', 'detail' => $detail, 'snapshot' => []]; }
}
