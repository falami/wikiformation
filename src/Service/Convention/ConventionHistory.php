<?php
namespace App\Service\Convention;

use App\Entity\{ConventionContrat, ConventionRevision, Utilisateur};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ConventionHistory
{
    public function __construct(private ConventionDocument $documents, private EntityManagerInterface $em) {}
    public function state(ConventionContrat $c): array
    {
        return ['numero' => $c->getNumero(), 'signed' => $c->isSigned(), 'pdf' => $c->getPdfPath(),
            'titre' => $c->getIntituleFormation(), 'duree' => $c->getDureeFormation(),
            'conditions' => $c->getConditionsFinancieres(), 'effectif' => $c->getEffectifPrevisionnel(),
            'libres' => $c->getParticipantsLibres(),
            'inscriptions' => array_map(fn($i) => $i->getId(), $c->getInscriptions()->toArray()),
            'signatures' => [$c->getDateSignatureEntreprise()?->format(DATE_ATOM), $c->getDateSignatureStagiaire()?->format(DATE_ATOM), $c->getDateSignatureOf()?->format(DATE_ATOM)],
        ];
    }
    public function token(ConventionContrat $c): string { return hash('sha256', json_encode($this->state($c))); }
    public function capture(ConventionContrat $c, Utilisateur $actor, string $reason): ConventionRevision
    {
        $response = $this->documents->response($c);
        $bytes = $response instanceof BinaryFileResponse ? file_get_contents($response->getFile()->getPathname()) : $response->getContent();
        if (!$bytes || !str_starts_with($bytes, '%PDF')) throw new \DomainException('Le PDF original ne peut pas être conservé. Restaurez-le avant toute modification.');
        return new ConventionRevision($c, $this->state($c), $reason, trim($actor->getPrenom().' '.$actor->getNom()), $bytes);
    }
    public function list(ConventionContrat $c): array { return $this->em->getRepository(ConventionRevision::class)->findBy(['convention' => $c], ['id' => 'DESC']); }
}
