<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Entreprise;
use App\Entity\Utilisateur;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class EntrepriseAccessVoter extends Voter
{
  public const VIEW_ENTREPRISE = 'VIEW_ENTREPRISE';

  protected function supports(string $attribute, mixed $subject): bool
  {
    return $attribute === self::VIEW_ENTREPRISE && $subject instanceof Entreprise;
  }

  protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
  {
    $user = $token->getUser();
    if (!$user instanceof Utilisateur) {
      return false;
    }

    /** @var Entreprise $entreprise */
    $entreprise = $subject;

    // ✅ Autorise uniquement si l'utilisateur est rattaché à CETTE entreprise
    return $user->getEntreprise()?->getId() === $entreprise->getId();
  }
}
