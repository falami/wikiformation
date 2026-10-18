<?php
declare(strict_types=1);
namespace App\EventSubscriber;
use App\Entity\{NotificationEvent, Session, Inscription, PieceDossier, SessionPiece, ContratFormateur, Emargement, SatisfactionAttempt, FormateurSatisfactionAttempt, RapportFormateur, Facture, Utilisateur};
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

#[AsDoctrineListener(event: Events::onFlush)]
final class NotificationActivityListener
{
    public function __construct(private Security $security) {}
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager(); $uow = $em->getUnitOfWork();
        foreach (array_merge($uow->getScheduledEntityInsertions(), $uow->getScheduledEntityUpdates()) as $entity) {
            $changes = $uow->getEntityChangeSet($entity);
            $new = $uow->isScheduledForInsert($entity);
            $event = self::describe($entity, $changes, $new);
            if (!$event || !$event->entite) continue;
            if ($event->session && $event->session->getEntite() !== $event->entite) continue;
            $actor = $this->security->getUser();
            $event->actor = $actor instanceof Utilisateur ? trim($actor->getPrenom().' '.$actor->getNom()) : 'Traitement automatique ou accès personnel';
            $event->actor = mb_substr($event->actor, 0, 180);
            $em->persist($event);
            $uow->computeChangeSet($em->getClassMetadata(NotificationEvent::class), $event);
        }
    }
    public static function describe(object $e, array $changes, bool $new): ?NotificationEvent
    {
        $n = new NotificationEvent();
        if ($e instanceof \App\Entity\TrainerDiploma || $e instanceof \App\Entity\TrainerCharterSignature) {
            if ($e instanceof \App\Entity\TrainerDiploma) {
                if (!$new || $e->filename === '') return null;
                $trainer = $e->formateur;
                $n->title = 'Diplôme déposé · '.$e->title;
                $n->occurredAt = $e->uploadedAt;
                // The diploma ID is not yet allocated during onFlush; link to its trainer's documents.
                $n->sourceId = $trainer?->getId();
            } else {
                if (!$e->signedAt || (!$new && (!isset($changes['signedAt']) || $changes['signedAt'][0] !== null))) return null;
                $trainer = $e->trainer;
                if (!$trainer || $e->version?->charter?->entite !== $trainer->getEntite()) return null;
                $n->title = 'Charte formateur signée · '.$e->version->title.' · Version '.$e->version->number;
                $n->occurredAt = $e->signedAt;
                $n->sourceId = $e->version->id;
            }
            $n->entite = $trainer?->getEntite();
            $n->subject = $trainer?->getUtilisateur();
            $n->sourceType = (new \ReflectionClass($e))->getShortName();
            $n->title = mb_substr($n->title, 0, 255);
            return $n;
        }
        if ($e instanceof ContratFormateur) {
            if ($e->getStatus() === \App\Enum\ContratFormateurStatus::BROUILLON) return null;
            if (!$new && !array_intersect(array_keys($changes), ['status','signatureAt','versionNumero','pdfPath','montantPrevuCents','conditionsParticulieres','conditionsGenerales'])) return null;
            $n->category = 'contracts'; $n->session = $e->getSession(); $n->subject = $e->getFormateur()?->getUtilisateur();
            $n->title = $e->getSignatureAt() ? 'Contrat formateur signé' : ($new ? 'Nouveau contrat formateur' : 'Contrat formateur mis à jour');
            $n->title .= ' · '.$e->getNumero();
        } elseif ($e instanceof Emargement) {
            if (!$e->getSignedAt() || (!$new && !isset($changes['signedAt']))) return null;
            $n->category = 'attendance'; $n->session = $e->getSession(); $n->subject = $e->getUtilisateur();
            $n->title = 'Émargement signé · '.$e->getDateJour()?->format('d/m/Y').' · '.$e->getPeriode()->value;
            $n->occurredAt = $e->getSignedAt();
        } elseif ($e instanceof PieceDossier) {
            if (!$new && !array_intersect(array_keys($changes), ['valide','filename'])) return null;
            $i = $e->getDossier()?->getInscription(); $n->session = $i?->getSession(); $n->subject = $i?->getStagiaire(); $n->company = $i?->getEntreprise();
            $n->title = ($new ? 'Pièce déposée' : ($e->isValide() ? 'Pièce validée' : 'Pièce à contrôler')).' · '.$e->getType()->label();
        } elseif ($e instanceof SessionPiece) {
            if (!$new && !array_intersect(array_keys($changes), ['valide','filename'])) return null;
            $n->session = $e->getSession(); $n->title = ($new ? 'Document de session déposé' : 'Document de session mis à jour').' · '.$e->getType()?->label();
        } elseif ($e instanceof SatisfactionAttempt || $e instanceof FormateurSatisfactionAttempt) {
            if (!$e->getSubmittedAt() || (!$new && !isset($changes['submittedAt']))) return null;
            $a = $e->getAssignment(); $n->session = $a?->getSession();
            $n->subject = $e instanceof SatisfactionAttempt ? $a?->getStagiaire() : $a?->getFormateur();
            $n->category = 'feedback'; $n->title = $e instanceof SatisfactionAttempt ? 'Appréciation stagiaire complétée' : 'Appréciation formateur complétée';
            $n->occurredAt = $e->getSubmittedAt();
        } elseif ($e instanceof RapportFormateur) {
            if (!$new && !array_intersect(array_keys($changes), ['submittedAt','statutTraitement'])) return null;
            $n->session = $e->getSession(); $n->subject = $e->getFormateur()?->getUtilisateur(); $n->category = 'feedback';
            $n->title = $new ? 'Compte rendu formateur déposé' : 'Compte rendu formateur mis à jour';
        } elseif ($e instanceof Inscription) {
            if (!$new && !isset($changes['status'])) return null;
            $n->session = $e->getSession(); $n->subject = $e->getStagiaire(); $n->company = $e->getEntreprise(); $n->category = 'progress';
            $n->title = 'Dossier stagiaire · '.$e->getStatus()->label();
        } elseif ($e instanceof Session) {
            if (!$new && !isset($changes['status'])) return null;
            $n->session = $e; $n->category = 'progress'; $n->title = 'Session · '.$e->getStatus()->label();
        } elseif ($e instanceof Facture) {
            if (!$new && !isset($changes['status'])) return null;
            $n->category = 'invoices'; $n->company = $e->getEntrepriseDestinataire(); $n->subject = $e->getDestinataire();
            $n->title = 'Facture '.$e->getNumero().' · '.$e->getStatus()->label();
        } else return null;
        $n->entite = $e->getEntite(); $n->sourceType = (new \ReflectionClass($e))->getShortName(); $n->sourceId = $e->getId();
        $n->title = mb_substr($n->title, 0, 255);
        return $n;
    }
}
