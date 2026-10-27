<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:privacy:inventory', description: 'Inventaire technique JSON du modèle Doctrine pour préparer les règles de conservation ; aucune donnée personnelle ni écriture.')]
final class PrivacyInventoryCommand extends Command
{
    public function __construct(private EntityManagerInterface $em) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $items = [];
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            if ($meta->isMappedSuperclass || $meta->isEmbeddedClass) continue;
            $dates = [];
            foreach ($meta->getFieldNames() as $field) if (preg_match('/date|time/', $meta->getTypeOfField($field) ?? '')) $dates[] = $field;
            $links = [];
            foreach ($meta->getAssociationNames() as $field) if ($meta->getAssociationTargetClass($field) === \App\Entity\Utilisateur::class) $links[] = $field;
            $items[] = ['entity' => $meta->getName(), 'table' => $meta->getTableName(), 'dateFields' => $dates, 'userAssociations' => $links, 'retention' => 'À qualifier par finalité, base légale, point de départ et éventuel gel juridique.'];
        }
        usort($items, fn(array $a, array $b) => strcmp($a['table'], $b['table']));
        $output->writeln(json_encode(['scope' => 'Modèle applicatif uniquement : compléter avec fichiers, journaux, caches, sauvegardes, boîtes e-mail et sous-traitants. Aucune purge exécutée ; aucune durée déduite automatiquement.', 'entities' => $items], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return Command::SUCCESS;
    }
}
