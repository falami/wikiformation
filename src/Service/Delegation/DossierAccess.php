<?php

declare (strict_types=1);
namespace App\Service\Delegation;

use App\Entity\{DossierDelegation, Entite, Utilisateur, UtilisateurEntite};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\{AccessDeniedHttpException, NotFoundHttpException};
final class DossierAccess
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly DossierRegistry $registry, private readonly PortfolioScope $scope)
    {
    }
    public function membership(Utilisateur $user, Entite $entite, string $role): UtilisateurEntite
    {
        $membership = $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur' => $user, 'entite' => $entite]);
        // Administrators may open the portal, but retain the same explicit dossier scope.
        // Never manufacture a membership or grant access to another entity.
        if (!$membership || !$membership->isActive() || !$membership->hasRole($role) && !$membership->isTenantAdmin() && !$user->isSuperAdmin()) {
            throw new AccessDeniedHttpException();
        }
        return $membership;
    }
    public function record(Entite $entite, string $module, int $id): object
    {
        $record = $this->em->find($this->registry->config($module)[0], $id);
        if (!$record || $record->getEntite()?->getId() !== $entite->getId()) {
            throw new NotFoundHttpException();
        }
        return $record;
    }
    public function grant(UtilisateurEntite $membership, string $module, int $id): DossierDelegation
    {
        $record = $this->record($membership->getEntite(), $module, $id);
        return $this->effective($membership, $module, $record, $this->grants($membership)) ?? throw new NotFoundHttpException();
    }
    private function grants(UtilisateurEntite $membership): array
    {
        $result = [];
        foreach ($this->em->getRepository(DossierDelegation::class)->findBy(['membership' => $membership]) as $grant) {
            $result[$grant->getModule()][$grant->getRecordId()] = $grant;
        }
        return $result;
    }
    private function effective(UtilisateurEntite $membership, string $module, object $record, array $grants): ?DossierDelegation
    {
        $direct = $grants[$module][$record->getId()] ?? null;
        $commercial = $membership->hasRole(UtilisateurEntite::TENANT_COMMERCIAL) || $membership->isTenantAdmin() || $membership->getUtilisateur()->isSuperAdmin();
        if (!$commercial || $module === 'entreprises') {
            return $direct;
        }
        $companies = $this->scope->companies($record);
        if ($companies) {
            $inherited = [];
            foreach ($companies as $company) {
                if (isset($grants['entreprises'][$company->getId()])) {
                    $inherited[] = $grants['entreprises'][$company->getId()];
                }
            }
            // A client reassignment removes child access, including old creation grants.
            if (!$inherited) {
                return null;
            }
            $effective = clone $inherited[0];
            $allEditable = count($companies) === count($inherited) && !array_filter($inherited, fn($g) => $g->getAccessLevel() !== 'edit');
            // A trainer contract exposes the whole session: do not share it across portfolios.
            if ($module === 'contrats-formateurs' && count($companies) !== count($inherited)) {
                return null;
            }
            return $effective->setModule($module)->setRecordId($record->getId())->setAccessLevel($allEditable ? 'edit' : 'read');
        }
        if ($direct) {
            return $direct;
        }
        // Shared operational catalogue: selection and search, never implicit editing rights.
        if (in_array($module, ['formations', 'sites', 'formateurs'], true)) {
            return (new DossierDelegation())->setMembership($membership)->setModule($module)->setRecordId($record->getId())->setAccessLevel('read');
        }
        return null;
    }
    public function rows(UtilisateurEntite $membership, string $module): array
    {
        $grants = $this->grants($membership);
        $records = $this->em->getRepository($this->registry->config($module)[0])->findBy(['entite' => $membership->getEntite()], ['id' => 'DESC']);
        return array_values(array_filter($records, fn($record) => $this->effective($membership, $module, $record, $grants) !== null));
    }
    public function editableRows(UtilisateurEntite $membership, string $module): array
    {
        $grants = $this->grants($membership);
        return array_values(array_filter($this->rows($membership, $module), fn($record) => $this->effective($membership, $module, $record, $grants)?->getAccessLevel() === 'edit'));
    }
    public function assign(UtilisateurEntite $membership, string $module, int $id, string $level, Utilisateur $actor): DossierDelegation
    {
        $this->record($membership->getEntite(), $module, $id);
        $grant = $this->em->getRepository(DossierDelegation::class)->findOneBy(['membership' => $membership, 'module' => $module, 'recordId' => $id]) ?? new DossierDelegation();
        $grant->setMembership($membership)->setModule($module)->setRecordId($id)->setAccessLevel($level)->setAssignedBy($actor);
        $this->em->persist($grant);
        return $grant;
    }
}
