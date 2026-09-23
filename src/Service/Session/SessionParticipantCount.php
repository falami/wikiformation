<?php

namespace App\Service\Session;

use App\Entity\{ConventionContrat, Inscription, Session, Utilisateur};
use App\Enum\StatusInscription;

/** Effectif attendu, distinct du nombre de comptes déjà créés ou de présences constatées. */
final class SessionParticipantCount
{
    public function count(Session $session, ?Utilisateur $excludedParticipant = null): int
    {
        $total = 0;
        $coveredParticipants = [];
        foreach ($this->conventions($session) as $convention) {
            $participants = $this->participantKeys($convention->getInscriptions(), $session, $excludedParticipant);
            $declared = $convention->getEffectifPrevisionnel();
            // Le chiffre déclaré reste prioritaire, même si des données historiques contiennent plus de noms.
            $count = $declared !== null && $declared > 0
                ? $declared
                : count($participants) + count($convention->getParticipantsLibresListe());
            // Une liste historique plus longue que le chiffre déclaré ne permet pas de savoir
            // quels noms représentent les places retenues : ne pas deviner leurs recoupements.
            $identified = $count >= count($participants) ? $participants : [];
            $duplicates = count(array_intersect_key($identified, $coveredParticipants));
            $total += max(0, $count - $duplicates);
            $coveredParticipants += $identified;
        }

        // Les inscriptions ultérieures complètent les places déclarées, elles ne s'y ajoutent pas.
        return $total > 0 ? $total : count($this->participantKeys($session->getInscriptions(), $session, $excludedParticipant));
    }

    /** @return list<Inscription> */
    public function participants(Session $session, ?Utilisateur $excludedParticipant = null): array
    {
        return array_values($this->eligibleParticipants($session->getInscriptions(), $session, $excludedParticipant));
    }

    /** @return list<string> Les noms libres ne constituent pas une identité fiable pour dédoublonner. */
    public function freeParticipantNames(Session $session): array
    {
        $names = [];
        foreach ($this->conventions($session) as $convention) {
            array_push($names, ...$convention->getParticipantsLibresListe());
        }
        return $names;
    }

    /** @return iterable<ConventionContrat> */
    private function conventions(Session $session): iterable
    {
        foreach ($session->getConventionContrats() as $convention) {
            if ($this->sameEntity($convention->getSession(), $session)
                && $this->sameEntity($convention->getEntite(), $session->getEntite())) {
                yield $convention;
            }
        }
    }

    /** @param iterable<Inscription> $inscriptions @return array<string, true> */
    private function participantKeys(iterable $inscriptions, Session $session, ?Utilisateur $excludedParticipant): array
    {
        return array_fill_keys(array_keys($this->eligibleParticipants($inscriptions, $session, $excludedParticipant)), true);
    }

    /** @param iterable<Inscription> $inscriptions @return array<string, Inscription> */
    private function eligibleParticipants(iterable $inscriptions, Session $session, ?Utilisateur $excludedParticipant): array
    {
        $participants = [];
        foreach ($inscriptions as $inscription) {
            $participant = $inscription->getStagiaire();
            if ($participant === null || $inscription->getStatus() === StatusInscription::ANNULE
                || !$this->sameEntity($inscription->getSession(), $session)
                || !$this->sameEntity($inscription->getEntite(), $session->getEntite())
                || $this->sameEntity($participant, $excludedParticipant)) {
                continue;
            }
            $key = $participant->getId() !== null ? 'id:' . $participant->getId() : 'object:' . spl_object_id($participant);
            $participants[$key] = $inscription;
        }
        return $participants;
    }

    private function sameEntity(?object $left, ?object $right): bool
    {
        return $left !== null && $right !== null
            && ($left === $right || ($left->getId() !== null && $left->getId() === $right->getId()));
    }
}
