<?php

declare (strict_types=1);
namespace App\Controller\Administrateur;

use App\Entity\{Entite, UtilisateurEntite, DossierDelegation, AuditLog};
use App\Service\Delegation\{DossierAccess, DossierRegistry};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
#[Route('/administrateur/{entite}/delegations', name: 'app_administrateur_delegation_', requirements: ['entite' => '\d+'])]
#[IsGranted('ENTITE_ADMIN', subject: 'entite')]
final class DelegationController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(
        #[MapEntity(id: 'entite')]
        Entite $entite,
        Request $request,
        EntityManagerInterface $em,
        DossierRegistry $registry,
        DossierAccess $access
    ): Response
    {
        $module = $request->query->getString('module', 'entreprises');
        $config = $registry->config($module);
        $members = array_values(array_filter($em->getRepository(UtilisateurEntite::class)->findBy(['entite' => $entite]), fn($m) => $m->isActive() && ($m->hasRole(UtilisateurEntite::TENANT_COMMERCIAL) || $m->hasRole(UtilisateurEntite::TENANT_OPCO))));
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('delegation_' . $entite->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }
            if ($request->request->getString('action') === 'revoke') {
                $grant = $em->find(DossierDelegation::class, $request->request->getInt('grant'));
                if (!$grant || $grant->getMembership()->getEntite()?->getId() !== $entite->getId()) {
                    throw $this->createNotFoundException();
                }
                $payload = ['grant' => $grant->getId(), 'membership' => $grant->getMembership()->getId(), 'module' => $grant->getModule(), 'recordId' => $grant->getRecordId()];
                $em->remove($grant);
            } else {
                $member = $em->find(UtilisateurEntite::class, $request->request->getInt('membership'));
                if (!$member || !in_array($member, $members, true)) {
                    throw $this->createNotFoundException();
                }
                $level = $request->request->getString('level', 'read');
                if (!in_array($level, ['read', 'edit'], true)) {
                    throw $this->createNotFoundException();
                }
                if (!$member->hasRole(UtilisateurEntite::TENANT_COMMERCIAL) && (!in_array($module, DossierRegistry::PARTNER_MODULES, true) || $level !== 'read')) {
                    $this->addFlash('danger', 'Un compte OPCO peut uniquement consulter les inscriptions, conventions et factures qui lui sont attribuées.');
                    return $this->redirectToRoute('app_administrateur_delegation_index', ['entite' => $entite->getId(), 'module' => $module]);
                }
                $id = $request->request->getInt('record');
                $access->assign($member, $module, $id, $level, $this->getUser());
                $payload = ['membership' => $member->getId(), 'module' => $module, 'recordId' => $id, 'level' => $level];
            }
            $em->persist((new AuditLog())->setCreateur($this->getUser())->setCreatedAt(new \DateTimeImmutable())->setEntite($entite)->setActor($this->getUser())->setEvent('delegation.' . $request->request->getString('action', 'assign'))->setPayload($payload));
            $em->flush();
            $this->addFlash('success', 'Les accès au dossier ont été mis à jour.');
            return $this->redirectToRoute('app_administrateur_delegation_index', ['entite' => $entite->getId(), 'module' => $module]);
        }
        $records = $em->getRepository($config[0])->findBy(['entite' => $entite], ['id' => 'DESC']);
        $choices = array_map(fn($r) => ['id' => $r->getId(), 'title' => $registry->title($r)], $records);
        $grants = $em->createQueryBuilder()->select('d', 'm', 'u')->from(DossierDelegation::class, 'd')->join('d.membership', 'm')->join('m.utilisateur', 'u')->where('m.entite = :entite')->andWhere('d.module = :module')->setParameter('entite', $entite)->setParameter('module', $module)->orderBy('d.id', 'DESC')->getQuery()->getResult();
        $labels = array_column($choices, 'title', 'id');
        $modules = DossierRegistry::MODULES;
        return $this->render('portail/delegations.html.twig', compact('entite', 'module', 'config', 'modules', 'members', 'choices', 'grants', 'labels'));
    }
}
