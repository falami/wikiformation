<?php
declare(strict_types=1);

namespace App\Service\Marketplace;

use App\Entity\{AuditLog, Entite};
use App\Entity\Marketplace\{Seller, Settings};
use Doctrine\ORM\EntityManagerInterface;

/** A separate explicit acceptance prevents legacy platform-paid terms authorising seller-paid fees. */
final class SellerPaymentPolicy
{
    public const VERSION = '2026-10-28-seller-processing-v1';
    public const TEXT = 'Pour mes nouvelles ventes, les frais de traitement Stripe constatés sur le paiement sont déduits de ma part vendeur, en plus de la commission de la plateforme. Cette règle remplace toute ancienne répartition de ces frais pour les nouvelles commandes uniquement. Aucun supplément lié au moyen de paiement n’est ajouté au prix payé par l’acheteur. Le versement attend le rapprochement des frais ; les remboursements, litiges et reprises peuvent réduire les sommes versées. Les autres frais éventuels de mon compte Stripe relèvent de mon contrat avec Stripe. Les commandes déjà enregistrées conservent leurs conditions. Le tarif de transmission électronique est distinct.';

    public static function snapshot(Settings $settings): array
    {
        $data = ['version' => self::VERSION, 'text' => self::TEXT, 'commissionBps' => $settings->commissionBps,
            'termsVersion' => $settings->termsVersion, 'terms' => $settings->terms, 'feesPayer' => 'seller'];
        return $data + ['sha256' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR))];
    }

    public static function acceptance(Seller $seller, Settings $settings, Entite $entite): AuditLog
    {
        $now = new \DateTimeImmutable();
        return (new AuditLog())->setEvent('marketplace.seller_fees_accepted')->setActor($seller->user)
            ->setCreateur($seller->user)->setEntite($entite)->setCreatedAt($now)
            ->setPayload(['sellerId' => $seller->id, 'actorId' => $seller->user?->getId(),
                'acceptedAt' => $now->format(DATE_ATOM), 'policy' => self::snapshot($settings)]);
    }

    public static function assertAccepted(EntityManagerInterface $em, Seller $seller, Settings $settings): array
    {
        $proof = $em->getRepository(AuditLog::class)->findOneBy(
            ['event' => 'marketplace.seller_fees_accepted', 'actor' => $seller->user], ['id' => 'DESC']);
        $payload = $proof?->getPayload();
        if (!$seller->id || !$seller->user || ($payload['sellerId'] ?? null) !== $seller->id
            || !hash_equals(self::snapshot($settings)['sha256'], (string) ($payload['policy']['sha256'] ?? ''))) {
            throw new \DomainException('Le vendeur doit consulter et accepter les conditions actuelles de frais de paiement dans son profil avant une nouvelle vente.');
        }
        return $payload + ['auditLogId' => $proof->getId()];
    }
}
