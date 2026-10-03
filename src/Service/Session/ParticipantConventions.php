<?php

declare(strict_types=1);
namespace App\Service\Session;

use App\Entity\Session;
use App\Repository\ConventionContratRepository;

final class ParticipantConventions
{
    public function __construct(private readonly ConventionContratRepository $repository) {}

    /** Only explicit enrolment coverage (or legacy individual coverage), never the whole company. */
    public function forSession(Session $session): array
    {
        if (!$session->getId()) return [];
        $documents = $this->repository->createQueryBuilder('c')->addSelect('i', 'company', 'd', 'u')
            ->leftJoin('c.inscriptions', 'i')->leftJoin('c.entreprise', 'company')->leftJoin('c.devis', 'd')->leftJoin('c.stagiaire', 'u')
            ->andWhere('c.session = :session AND c.entite = :entite')
            ->setParameter('session', $session)->setParameter('entite', $session->getEntite())
            ->orderBy('c.id', 'ASC')->getQuery()->getResult();
        $result = [];
        foreach ($documents as $document) {
            $quote = $document->getDevis();
            $foreignQuote = $quote && $quote->getEntite()?->getId() !== $session->getEntite()?->getId();
            $card = [
                'id' => $document->getId(), 'number' => $document->getNumero() ?: 'Convention #'.$document->getId(),
                'payer' => $document->getDestinataireLabel(), 'company' => $document->getEntreprise() !== null,
                'signed' => $document->isSigned(), 'estimated' => !$quote,
                'amount' => $foreignQuote ? null : ($quote ? $quote->getMontantTtcCents() : $session->getTarifEffectifCents() * $document->getEffectifTotal()),
                'currency' => $quote && !$foreignQuote ? $quote->getDevise() : 'EUR',
                'participants' => $document->getEffectifTotal(),
            ];
            foreach ($session->getInscriptions() as $inscription) {
                if (!$inscription->getId() || $inscription->getEntite()?->getId() !== $session->getEntite()?->getId()) continue;
                if ($document->getInscriptions()->contains($inscription)
                    || ($document->getInscriptions()->isEmpty() && $document->getStagiaire() && $document->getStagiaire()->getId() === $inscription->getStagiaire()?->getId())) {
                    $result[$inscription->getId()][] = $card;
                }
            }
        }
        return $result;
    }
}
