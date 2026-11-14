<?php
declare(strict_types=1);
namespace App\Controller\Billing;
use App\Entity\{Entite, Utilisateur};
use App\Entity\Billing\Module;
use App\Security\Permission\TenantPermission;
use App\Service\Module\{ModuleBilling, ModuleEntitlement};
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/organisme/{entite}/options', name: 'app_modules_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::BILLING_MANAGE, subject: 'entite')]
final class ModuleController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Entite $entite, Request $request, EntityManagerInterface $em, ModuleEntitlement $access, UtilisateurEntiteManager $members): Response
    {
        $cards = [];
        foreach ($em->getRepository(Module::class)->findBy([], ['name' => 'ASC']) as $module) {
            $row = $access->access($entite, $module);
            if (!$module->active && !$row && !$module->included && !$access->internal($entite)) continue;
            $cards[] = ['module' => $module, 'access' => $row, 'allowed' => $access->allowed($entite, $module->code)];
        }
        return $this->render('modules/index.html.twig', ['entite' => $entite, 'cards' => $cards, 'internal' => $access->internal($entite), 'utilisateurEntite' => $members->getUserEntiteLink($entite), 'checkoutReturned' => $request->query->get('checkout') === 'success']);
    }

    #[Route('/{code}/{action}', name: 'action', requirements: ['action' => 'subscribe|cancel|resume|portal'], methods: ['POST'])]
    public function action(Entite $entite, string $code, string $action, Request $request, EntityManagerInterface $em, ModuleBilling $billing, LoggerInterface $logger): Response
    {
        if (!$this->isCsrfTokenValid('module_'.$entite->getId().'_'.$code.'_'.$action, $request->request->getString('_token'))) throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        $module = $em->find(Module::class, $code) ?? throw $this->createNotFoundException();
        $url = $this->generateUrl('app_modules_index', ['entite' => $entite->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
        try {
            if ($action === 'subscribe') return $this->redirect($billing->checkout($entite, $module, $url), 303);
            if ($action === 'portal') return $this->redirect($billing->portal($entite, $module, $url), 303);
            $billing->cancellation($entite, $module, $action === 'cancel', $this->getUser()?->getId());
            $this->addFlash('success', $action === 'cancel' ? 'Résiliation enregistrée. L’accès reste disponible jusqu’à la fin de la période en cours. Vos données seront conservées.' : 'Résiliation annulée. Votre abonnement se poursuivra.');
        } catch (\DomainException $e) { $this->addFlash('warning', $e->getMessage());
        } catch (\Throwable $e) {
            $logger->error('Module billing operation failed', ['exception' => $e, 'entite' => $entite->getId(), 'module' => $code, 'action' => $action]);
            $this->addFlash('danger', 'La demande n’a pas pu être confirmée. Réessayez dans quelques instants ; aucun double abonnement ne sera créé.');
        }
        return $this->redirect($url, 303);
    }
}
