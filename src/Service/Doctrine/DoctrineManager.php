<?php

namespace App\Service\Doctrine;


use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class DoctrineManager
{

    private $entityManager;
    private $logger;

    public function __construct(EntityManagerInterface $entityManager, LoggerInterface $logger)
    {
        $this->entityManager = $entityManager;
        $this->logger = $logger;
    }

    public function saveInDb($object, string $messageErreur)
    {
        
        try {
            $this->entityManager->persist($object);
            $this->entityManager->flush();
            return true;
        } catch (\Exception $e) {
            $this->logger->critical($messageErreur . $e->getMessage());
            return false;
        }

    }

    public function delete($object, string $messageErreur){
        try {
            $this->entityManager->remove($object);
            $this->entityManager->flush();
            return true;
        } catch (\Exception $e) {
            $this->logger->critical($messageErreur . $e->getMessage());
            return false;
        }
    }
}