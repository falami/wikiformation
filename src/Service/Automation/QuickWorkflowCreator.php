<?php

namespace App\Service\Automation;

use App\Entity\{Devis, Entite, Formation, Entreprise, Formateur, LigneDevis, Qcm, Session, SessionJour, Site, Utilisateur};
use App\Entity\Automation\TrainingWorkflow;
use App\Enum\DevisStatus;
use App\Service\Convention\DevisConventionCreator;
use App\Service\Sequence\DevisNumberGenerator;
use Doctrine\ORM\EntityManagerInterface;

final class QuickWorkflowCreator
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly DevisConventionCreator $creator, private readonly DevisNumberGenerator $numbers) {}

    public function create(Entite $entite, Utilisateur $actor, array $data): TrainingWorkflow
    {
        foreach (['formation' => Formation::class, 'entreprise' => Entreprise::class, 'formateur' => Formateur::class, 'site' => Site::class] as $field => $class) {
            if (!$data[$field] instanceof $class || $data[$field]->getEntite()?->getId() !== $entite->getId()) throw new \DomainException('Choisissez les éléments de cet organisme.');
        }
        $postQcm = $data['postQcm'] ?? null;
        if ($postQcm !== null && (!$postQcm instanceof Qcm || !$postQcm->getId() || !$postQcm->isActive() || $postQcm->getEntite()?->getId() !== $entite->getId())) {
            throw new \DomainException('Choisissez un QCM actif de cet organisme pour l’évaluation de fin.');
        }
        $dates = array_values(array_unique(array_filter(array_map('trim', explode(',', $data['dates'])))));
        if (!$dates || count($dates) > 60) throw new \DomainException('Sélectionnez de 1 à 60 dates.');
        $hours = (float) $data['hours'];
        $minutes = (int) round($hours * 60);
        if ($minutes < 60 || $minutes > 720) throw new \DomainException('La durée quotidienne doit être comprise entre 1 et 12 heures.');
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $data['startTime'])) throw new \DomainException('L’heure de début est invalide.');
        $pause = $minutes > 240 ? 90 : 0;
        $slots = [];
        foreach ($dates as $value) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value . ' ' . $data['startTime'], new \DateTimeZone('Europe/Paris'));
            if (!$date || $date->format('Y-m-d') !== $value) throw new \DomainException('Une date est invalide.');
            $finish = $date->modify('+' . ($minutes + $pause) . ' minutes');
            if ($finish->format('Y-m-d') !== $value) throw new \DomainException('Le créneau doit se terminer le même jour.');
            $slots[] = (new SessionJour())->setDateDebut($date)->setDateFin($finish)->setPauseMinutes($pause)->setFormateur($data['formateur'])->setEntite($entite)->setCreateur($actor);
        }
        usort($slots, static fn($a, $b) => $a->getDateDebut() <=> $b->getDateDebut());
        $amount = (int) round((float) $data['price'] * 100);
        $effectif = (int) $data['participants'];
        if ($amount < 0 || $amount > 100000000 || $effectif < 1 || $effectif > 1000) throw new \DomainException('Vérifiez le prix et l’effectif.');
        if (!filter_var($data['contactEmail'], FILTER_VALIDATE_EMAIL)) throw new \DomainException('Renseignez l’adresse du contact client.');
        return $this->em->wrapInTransaction(function () use ($entite, $actor, $data, $slots, $amount, $effectif, $postQcm) {
            $devis = (new Devis())->setEntite($entite)->setCreateur($actor)->setEntrepriseDestinataire($data['entreprise'])
                ->setFormation($data['formation'])->setStatus(DevisStatus::DRAFT)->setNumero($this->numbers->nextForEntite($entite->getId()));
            $line = (new LigneDevis())->setEntite($entite)->setCreateur($actor)->setLabel($data['formation']->getTitre())
                ->setQte(1)->setPuHtCents($amount)->setTva((float) $data['vat']);
            $devis->addLigne($line)->setMontantHtCents($amount)->setMontantTvaCents($line->getTotalTvaCents())->setMontantTtcCents($line->getTotalTtcCents());
            $this->em->persist($devis);
            $this->em->flush();
            $session = (new Session())->setEntite($entite)->setCreateur($actor)->setFormation($data['formation'])->setSite($data['site'])->setFormateur($data['formateur'])->setCapacite($effectif);
            // The commercial amount is the group price on the quote; never multiply it by the headcount.
            foreach ($slots as $slot) $session->addJour($slot);
            $convention = $this->creator->create($devis, $session, [], $actor, null, effectifPrevisionnel: $effectif);
            $workflow = (new TrainingWorkflow())->setEntite($entite)->setCreateur($actor)->setSession($session)->setConvention($convention)
                ->setContactEmail($data['contactEmail'])->setValidityMonths($data['validityMonths'] ?? null)->setOptions(['priceBasis' => 'group_ht', 'autoImportParticipants' => true, 'postQcmId' => $postQcm?->getId()]);
            $this->em->persist($workflow);
            return $workflow;
        });
    }
}
