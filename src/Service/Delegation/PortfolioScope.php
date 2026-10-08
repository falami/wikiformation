<?php

declare (strict_types=1);
namespace App\Service\Delegation;

use App\Entity\{Entreprise, UtilisateurEntite, Utilisateur, Session, Inscription, Devis, Facture, ConventionContrat, ContratFormateur, Prospect};
use Doctrine\ORM\EntityManagerInterface;
/** Client ownership is resolved dynamically: reassignment also moves existing dossiers. */
final class PortfolioScope
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }
    /** @return list<Entreprise> */
    public function companies(object $record): array
    {
        $companies = match (true) {
            $record instanceof Entreprise => [$record],
            $record instanceof UtilisateurEntite => $this->userCompanies($record->getUtilisateur(), $record->getEntite()->getId()),
            $record instanceof Devis, $record instanceof Facture => $record->getEntrepriseDestinataire() ? [$record->getEntrepriseDestinataire()] : $this->userCompanies($record->getDestinataire(), $record->getEntite()->getId()),
            $record instanceof ConventionContrat => $record->getEntreprise() ? [$record->getEntreprise()] : $this->userCompanies($record->getStagiaire(), $record->getEntite()->getId()),
            $record instanceof Inscription => $record->getEntreprise() ? [$record->getEntreprise()] : $this->userCompanies($record->getStagiaire(), $record->getEntite()->getId()),
            $record instanceof Prospect => [$record->getLinkedEntreprise()],
            $record instanceof ContratFormateur => $this->companies($record->getSession()),
            $record instanceof Session => $this->sessionCompanies($record),
            default => [],
        };
        $result = [];
        foreach ($companies as $company) {
            if ($company && $company->getEntite()?->getId() === $record->getEntite()?->getId()) {
                $result[$company->getId()] = $company;
            }
        }
        return array_values($result);
    }
    private function userCompanies(?Utilisateur $user, int $tenant): array
    {
        if (!$user) {
            return [];
        }
        return array_values(array_filter([$user->getEntreprise(), ...$user->getEntreprisesAssociees()->toArray()], fn($c) => $c && $c->getEntite()?->getId() === $tenant));
    }
    private function sessionCompanies(Session $session): array
    {
        $companies = [$session->getEntrepriseCliente()];
        foreach ($session->getInscriptions() as $inscription) {
            $companies = [...$companies, ...$this->companies($inscription)];
        }
        foreach ($this->em->getRepository(ConventionContrat::class)->findBy(['session' => $session]) as $convention) {
            $companies = [...$companies, ...$this->companies($convention)];
        }
        return $companies;
    }
}
