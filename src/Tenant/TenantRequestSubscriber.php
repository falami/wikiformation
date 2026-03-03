<?php

namespace App\Tenant;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class TenantRequestSubscriber implements EventSubscriberInterface
{
  public function __construct(private TenantResolver $resolver) {}

  public static function getSubscribedEvents(): array
  {
    return [KernelEvents::REQUEST => ['onKernelRequest', 30]];
  }

  public function onKernelRequest(RequestEvent $event): void
  {
    if (!$event->isMainRequest()) return;

    $request = $event->getRequest();
    $tenant = $this->resolver->resolve($request);

    if ($tenant) {
      $request->attributes->set('_tenant', $tenant);
    }
  }
}
