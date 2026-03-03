<?php

namespace App\Service\Email;

use App\Entity\Entite;
use Symfony\Component\Mime\Address;

final class EntiteEmailResolver
{
  public function __construct(
    private string $defaultFromEmail = 'contact@wikiformation.fr',
    private string $defaultFromName  = 'Wiki Formation',
  ) {}

  public function from(Entite $entite): Address
  {
    $email = $this->entiteEmail($entite) ?: $this->defaultFromEmail;
    $name  = $this->entiteName($entite) ?: $this->defaultFromName;

    return new Address($email, $name);
  }

  public function replyTo(Entite $entite): ?Address
  {
    $email = $this->entiteEmail($entite);
    if (!$email) return null;

    $name = $this->entiteName($entite) ?: $this->defaultFromName;
    return new Address($email, $name);
  }

  private function entiteName(Entite $entite): ?string
  {
    return method_exists($entite, 'getNom') ? (string)$entite->getNom() : null;
  }

  private function entiteEmail(Entite $entite): ?string
  {
    // ✅ adapte ici si ton champ s'appelle autrement
    if (method_exists($entite, 'getEmail') && $entite->getEmail()) {
      return (string)$entite->getEmail();
    }
    /*if (method_exists($entite, 'getEmailContact') && $entite->getEmailContact()) {
      return (string)$entite->getEmailContact();
    }
    if (method_exists($entite, 'getEmailCommercial') && $entite->getEmailCommercial()) {
      return (string)$entite->getEmailCommercial();
    }*/

    return null;
  }
}
