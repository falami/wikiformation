<?php
declare(strict_types=1);

namespace App\Service\Sequence;

use Doctrine\ORM\EntityManagerInterface;

/**
 * prePersist may run before Doctrine opens its flush transaction (including
 * certificates created during onFlush). Reserve no sequence there. A private,
 * random insert value is replaced in postPersist, before that transaction commits.
 */
final class DeferredDocumentNumber
{
    private \WeakMap $pending;

    public function __construct()
    {
        $this->pending = new \WeakMap();
    }

    public function prepare(object $document): string
    {
        $this->pending[$document] = true;
        return 'PENDING-' . bin2hex(random_bytes(16));
    }

    public function complete(object $document, EntityManagerInterface $em, callable $generate): void
    {
        if (!isset($this->pending[$document])) return;
        $number = $generate();
        $metadata = $em->getClassMetadata($document::class);
        $em->getConnection()->update($metadata->getTableName(), ['numero' => $number], ['id' => $document->getId()]);
        $document->setNumero($number);
        $em->getUnitOfWork()->setOriginalEntityProperty(spl_object_id($document), 'numero', $number);
        unset($this->pending[$document]);
    }
}
