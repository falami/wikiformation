<?php

namespace App\EventSubscriber;

use App\Entity\Utilisateur;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security; // ✅ ICI
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

final class TenantTwigSubscriber implements EventSubscriberInterface
{
  public function __construct(
    private Environment $twig,
    private Security $security,      // ✅ OK maintenant
    private TenantContext $tenant,
  ) {}

  public static function getSubscribedEvents(): array
  {
    return [KernelEvents::CONTROLLER => 'onController'];
  }

  public function onController(ControllerEvent $event): void
  {
    $user = $this->security->getUser();
    if (!$user instanceof Utilisateur) {
      return;
    }

    $entite = $this->tenant->getCurrentEntiteForUser($user);
    if ($entite) {
      $this->twig->addGlobal('entite', $entite);
    }
  }
}
