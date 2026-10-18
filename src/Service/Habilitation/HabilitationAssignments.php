<?php

declare(strict_types=1);
namespace App\Service\Habilitation;

use App\Entity\{Entite, Inscription, HabilitationDossier};
use App\Enum\{StatusInscription, StatusSession};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;

final class HabilitationAssignments
{
    public function __construct(private EntityManagerInterface $em, private HabilitationWorkflow $workflow) {}

    public static function due(Inscription $i, ?\DateTimeImmutable $now = null): bool
    {
        $s = $i->getSession();
        $zone = new \DateTimeZone('Europe/Paris');
        return $s && $s->getStatus() !== StatusSession::CANCELED
            && !in_array($i->getStatus(), [StatusInscription::ANNULE, StatusInscription::ABSENT], true)
            && $s->getDateDebut() && $s->getDateDebut()->setTimezone($zone)->format('Y-m-d') <= ($now ?? new \DateTimeImmutable())->setTimezone($zone)->format('Y-m-d');
    }

    public function prepare(Inscription $i): ?HabilitationDossier
    {
        if (!self::due($i)) return null;
        $s = $i->getSession(); $e = $i->getEntite();
        $t = $s->getFormation()?->getHabilitationTemplate();
        $company = $i->getEntreprise() ?? $i->getStagiaire()?->getEntreprise();
        $trainers = $s->getFormateursEffectifs();
        if (!$e || !$t?->getActive() || !$company || !$trainers || !$i->getCreateur()) return null;
        if ($s->getEntite() !== $e || $t->getEntite() !== $e || $company->getEntite() !== $e) return null;
        $users = [];
        foreach ($trainers as $trainer) {
            if ($trainer->getEntite() !== $e || !$trainer->getUtilisateur()) return null;
            $users[] = $trainer->getUtilisateur();
        }
        return (new HabilitationDossier())->setEntite($e)->setInscription($i)->setTemplate($t)
            ->setSchema($t->getSchema())->setEntreprise($company)->setFormateurs($users);
    }

    /** Includes past sessions; existing and archived assignments are never overwritten. */
    public function synchronize(Entite $entite): array
    {
        $result = ['created'=>0, 'incomplete'=>0];
        $rows = $this->em->createQueryBuilder()->select('i')->from(Inscription::class,'i')
            ->join('i.session','s')->join('s.formation','f')->join('f.habilitationTemplate','t')
            ->where('i.entite = :e AND s.entite = :e AND t.entite = :e AND t.active = true')
            ->andWhere('NOT EXISTS (SELECT existing.id FROM App\Entity\HabilitationDossier existing WHERE existing.inscription = i)')
            ->setParameter('e',$entite)->getQuery()->getResult();
        foreach ($rows as $i) {
            if (!self::due($i)) continue;
            $this->em->wrapInTransaction(function () use ($i, &$result): void {
                // Serialize automatic creation for the same registration (dashboard/cron overlap).
                $this->em->lock($i, LockMode::PESSIMISTIC_WRITE);
                if ($this->em->getRepository(HabilitationDossier::class)->findOneBy(['inscription'=>$i])) return;
                $d = $this->prepare($i);
                if (!$d) { ++$result['incomplete']; return; }
                $this->em->persist($d);
                $this->em->persist($this->workflow->record($d,$i->getCreateur(),'assign',['source'=>'formation','model'=>$d->getTemplate()->getTitre()]));
                ++$result['created'];
            });
        }
        return $result;
    }
}
