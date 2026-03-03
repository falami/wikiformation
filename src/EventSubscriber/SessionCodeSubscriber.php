<?php

namespace App\EventSubscriber;

use App\Entity\Session;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

final class SessionCodeSubscriber implements EventSubscriber
{
  public function getSubscribedEvents(): array
  {
    return [Events::prePersist];
  }

  public function prePersist(LifecycleEventArgs $args): void
  {
    $entity = $args->getObject();
    if (!$entity instanceof Session) {
      return;
    }

    // si l'utilisateur a déjà rempli => on ne touche pas
    if ($entity->getCode() && trim($entity->getCode()) !== '') {
      return;
    }

    $entity->setCode($this->generateCode($entity));
  }

  private function generateCode(Session $s): string
  {
    $date = ($s->getDateDebut() ?? new \DateTimeImmutable())->format('ymd'); // 251230
    $suffix = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));           // A1B2
    return "S-{$date}-{$suffix}";                                           // S-251230-A1B2
  }
}
