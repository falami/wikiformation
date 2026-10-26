<?php

declare(strict_types=1);

namespace App\Service\Convention;

use App\Entity\{ConventionContrat, SessionPiece};
use App\Enum\SessionPieceType;
use App\Security\DossierDocumentAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Session deposits are supporting documents, not individual electronic signatures. */
final class ConventionPaperDeposits
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%session_piece_dir%')] private readonly string $uploadDir,
    ) {}

    /**
     * @param list<ConventionContrat> $conventions
     * @return array<int, list<SessionPiece>> Indexed by convention ID; validated deposits first.
     */
    public function forConventions(array $conventions): array
    {
        $sessionConventions = [];
        foreach ($conventions as $convention) {
            $session = $convention->getSession();
            $tenantId = $convention->getEntite()?->getId();
            if (!$convention->getId() || !$session?->getId() || !$tenantId
                || $session->getEntite()?->getId() !== $tenantId) {
                continue;
            }
            $sessionConventions[$session->getId()][] = $convention->getId();
        }

        $directory = realpath($this->uploadDir);
        if (!$sessionConventions || $directory === false) {
            return [];
        }

        // Batch the listing lookup and require both the document and its session to share a tenant.
        $pieces = $this->em->getRepository(SessionPiece::class)->createQueryBuilder('p')
            ->innerJoin('p.session', 's')->addSelect('s')
            ->andWhere('s.id IN (:sessions) AND p.entite = s.entite')
            ->andWhere('p.type = :type')
            ->setParameter('sessions', array_keys($sessionConventions))
            ->setParameter('type', SessionPieceType::CONVENTION_SIGNEE)
            ->orderBy('p.valide', 'DESC')->addOrderBy('p.uploadedAt', 'DESC')->addOrderBy('p.id', 'DESC')
            ->getQuery()->getResult();

        $deposits = [];
        foreach ($pieces as $piece) {
            try {
                $path = DossierDocumentAccess::filePath($directory, $piece->getFilename());
            } catch (NotFoundHttpException) {
                continue;
            }
            $resolved = realpath($path);
            if ($resolved === false || !str_starts_with($resolved, $directory.DIRECTORY_SEPARATOR)
                || !is_file($resolved) || !is_readable($resolved)) {
                continue;
            }
            foreach ($sessionConventions[$piece->getSession()->getId()] as $conventionId) {
                $deposits[$conventionId][] = $piece;
            }
        }

        return $deposits;
    }
}
