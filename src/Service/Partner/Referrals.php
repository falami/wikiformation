<?php

declare (strict_types=1);
namespace App\Service\Partner;

use App\Entity\{Entite, Utilisateur};
use App\Entity\Partner\{Partner, Referral, PartnerTerms};
use App\Entity\Billing\EntiteSubscription;
use Doctrine\ORM\EntityManagerInterface;
final class Referrals
{
    public function __construct(private EntityManagerInterface $em)
    {
    }
    public function byCode(string $code): ?Partner
    {
        return $this->em->getRepository(Partner::class)->findOneBy(['code' => strtoupper(trim($code)), 'active' => true]);
    }
    public function attach(Partner $partner, Entite $entite, Utilisateur $author, string $source = 'code'): Referral
    {
        if (!$partner->active) {
            throw new \DomainException('Cet apporteur est suspendu.');
        }
        if ($partner->user === $author || $partner->user === $entite->getCreateur()) {
            throw new \DomainException('Un apporteur ne peut pas parrainer son propre organisme.');
        }
        if ($this->em->getRepository(Referral::class)->findOneBy(['entite' => $entite])) {
            throw new \DomainException('Cet organisme est déjà rattaché à un apporteur.');
        }
        foreach ($this->em->getRepository(EntiteSubscription::class)->findBy(['entite' => $entite]) as $sub) {
            if ($sub->getStripeSubscriptionId()) {
                throw new \DomainException('Rattachez l’apporteur avant le premier abonnement Stripe.');
            }
        }
        $referral = new Referral();
        $referral->partner = $partner;
        $referral->entite = $entite;
        $referral->author = $author;
        $referral->source = $source;
        $this->em->persist($referral);
        return $referral;
    }
    public function terms(Partner $partner, ?\DateTimeImmutable $at = null): ?PartnerTerms
    {
        return $this->em->getRepository(PartnerTerms::class)->createQueryBuilder('t')->where('t.partner = :partner AND t.effectiveAt <= :at')->setParameter('partner', $partner)->setParameter('at', $at ?? new \DateTimeImmutable())->orderBy('t.effectiveAt', 'DESC')->addOrderBy('t.id', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }
}
