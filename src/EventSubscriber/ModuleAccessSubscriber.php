<?php
declare(strict_types=1);
namespace App\EventSubscriber;
use App\Entity\Entite;
use App\Service\Module\ModuleEntitlement;
use App\Security\Permission\TenantPermission;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ModuleAccessSubscriber implements EventSubscriberInterface
{
    public function __construct(private ModuleEntitlement $modules, private Security $security, private UrlGeneratorInterface $urls, private \Twig\Environment $twig, private \App\Service\UtilisateurEntite\UtilisateurEntiteManager $members) {}
    public static function getSubscribedEvents(): array { return [KernelEvents::CONTROLLER_ARGUMENTS => ['check', -20]]; }
    public function check(ControllerArgumentsEvent $event): void
    {
        $controller = $event->getController();
        if (!is_array($controller)) return;
        $attributes = (new \ReflectionClass($controller[0]))->getAttributes(\App\Security\Attribute\RequiresModule::class);
        if (!$attributes) return;
        $code = $attributes[0]->newInstance()->code;
        foreach ($event->getArguments() as $entite) {
            if (!$entite instanceof Entite) continue;
            if (!$this->security->isGranted(TenantPermission::ACCESS, $entite)) throw new AccessDeniedHttpException();
            if ($this->modules->allowed($entite, $code)) return;
            if ($event->getRequest()->isMethod('GET') && $this->security->isGranted(TenantPermission::BILLING_MANAGE, $entite)) {
                $url = $this->urls->generate('app_modules_index', ['entite' => $entite->getId()]);
                $event->setController(static fn() => new RedirectResponse($url));
                $event->setArguments([]);
                return;
            }
            if ($event->getRequest()->isMethod('GET')) {
                $response = new \Symfony\Component\HttpFoundation\Response($this->twig->render('modules/locked.html.twig', [
                    'entite' => $entite, 'utilisateurEntite' => $this->members->getUserEntiteLink($entite),
                ]), 403, ['Cache-Control' => 'private, no-store']);
                $event->setController(static fn() => $response);
                $event->setArguments([]);
                return;
            }
            throw new AccessDeniedHttpException('Cette option n’est pas active pour cet organisme. Contactez son administrateur. Vos données sont conservées.');
        }
        throw new AccessDeniedHttpException('Organisme requis pour vérifier cette option.');
    }
}
