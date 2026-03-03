<?php

// src/EventSubscriber/EntiteActivitySubscriber.php
namespace App\EventSubscriber;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class EntiteActivitySubscriber implements EventSubscriberInterface
{
  public function __construct(
    private TokenStorageInterface $tokenStorage,
    private EntityManagerInterface $em,
  ) {}

  public static function getSubscribedEvents(): array
  {
    return [KernelEvents::REQUEST => ['onKernelRequest', -20]];
  }

  public function onKernelRequest(RequestEvent $event): void
  {
    if (!$event->isMainRequest()) return;

    $token = $this->tokenStorage->getToken();
    if (!$token) return;

    $user = $token->getUser();
    if (!$user instanceof Utilisateur) return;

    // ⬇️ adapte selon TON modèle :
    // - soit $user->getEntite()
    // - soit une entité "courante" en session / UtilisateurEntiteManager
    $entite = $user->getEntite();
    if (!$entite instanceof Entite) return;

    $now = new \DateTimeImmutable();

    $last = $entite->getLastActivityAt();
    if ($last !== null) {
      // throttle : 10 minutes
      if ($last > $now->sub(new \DateInterval('PT10M'))) {
        return;
      }
    }

    $entite->setLastActivityAt($now); // ou $entite->touchActivity()
    $this->em->flush();
  }
}
