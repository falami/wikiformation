<?php
declare(strict_types=1);
namespace App\Command;

use App\Entity\{Entite, Session, Utilisateur, Emargement, RapportFormateur, FormateurSatisfactionAssignment};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:sessions:diagnose-formateurs', description: 'Lire les intervenants, signatures, appréciations et rapports des sessions, sans modification.')]
final class DiagnoseTrainerSessionCommand extends Command
{
    public function __construct(private EntityManagerInterface $em) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addArgument('entite', InputArgument::REQUIRED, 'Organisme')
            ->addArgument('sessions', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Codes exacts des sessions');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = filter_var($input->getArgument('entite'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) { $io->error('Identifiant organisme invalide.'); return self::INVALID; }
        $entite = $this->em->find(Entite::class, $id);
        if (!$entite) { $io->error('Organisme introuvable.'); return self::INVALID; }
        $io->note('Lecture seule. Aucun changement d’auteur, d’affectation ou de signature. Dates affichées en heure de Paris.');
        $name = static fn(?Utilisateur $u): string => $u ? '#'.$u->getId().' '.trim($u->getPrenom().' '.$u->getNom()) : 'absent';
        $date = static fn(?\DateTimeInterface $d): string => $d ? \DateTimeImmutable::createFromInterface($d)->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y H:i') : '—';
        $missing = false;
        foreach (array_unique($input->getArgument('sessions')) as $code) {
            $session = $this->em->getRepository(Session::class)->findOneBy(['entite' => $entite, 'code' => $code]);
            if (!$session) { $io->warning('Session introuvable dans cet organisme : '.$code); $missing = true; continue; }
            $io->section($session->getCode());
            $io->table(['Session', 'Valeur'], [
                ['Identifiant', $session->getId()], ['Statut', $session->getStatus()->value],
                ['Formateur principal', $name($session->getFormateur()?->getUtilisateur())],
                ['Intervenants effectifs', implode(', ', array_map(fn($f) => $name($f->getUtilisateur()), $session->getFormateursEffectifs())) ?: 'aucun'],
            ]);
            $rows = [];
            foreach ($session->getJours() as $jour) $rows[] = [$date($jour->getDateDebut()), $date($jour->getDateFin()), $name(($jour->getFormateur() ?? $session->getFormateur())?->getUtilisateur()), $jour->getFormateur() ? 'créneau' : 'principal par défaut'];
            $io->table(['Début', 'Fin', 'Intervenant', 'Affectation'], $rows);
            $rows = [];
            foreach ($this->em->getRepository(FormateurSatisfactionAssignment::class)->findBy(['session' => $session, 'entite' => $entite]) as $a) {
                $t = $a->getAttempt();
                $rows[] = [$a->getId(), $name($a->getFormateur()), $t?->getId() ?? '—', $name($t?->getCreateur()), $date($t?->getStartedAt()), $date($t?->getSubmittedAt())];
            }
            $io->table(['Appréciation', 'Affectée à', 'Tentative', 'Créée par', 'Commencée', 'Soumise'], $rows);
            $rows = [];
            foreach ($this->em->getRepository(RapportFormateur::class)->findBy(['session' => $session, 'entite' => $entite]) as $r) {
                $rows[] = [$r->getId(), $name($r->getFormateur()?->getUtilisateur()), $name($r->getCreateur()), $date($r->getSubmittedAt()), $r->getStatutTraitement(), $r->getImportance()];
            }
            $io->table(['Rapport', 'Formateur', 'Créateur', 'Soumis', 'Traitement', 'Importance'], $rows);
            $rows = [];
            foreach ($this->em->getRepository(Emargement::class)->findBy(['session' => $session, 'role' => ['trainer', 'formateur']]) as $e) {
                $rows[] = [$e->getId(), $name($e->getUtilisateur()), $e->getDateJour()?->format('d/m/Y'), $e->getPeriode()->value, $date($e->getSignedAt()), $e->getSignaturePath() ? 'oui' : 'non'];
            }
            $io->table(['Émargement', 'Signataire', 'Journée', 'Période', 'Signé le', 'Fichier référencé'], $rows);
        }
        return $missing ? self::FAILURE : self::SUCCESS;
    }
}
