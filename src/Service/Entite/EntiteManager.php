<?php

namespace App\Service\Entite;

use App\Entity\Entite;
use App\Repository\EntiteRepository;
use App\Service\Doctrine\DoctrineManager;

class EntiteManager {

    private $doctrineManager;
    private $entiteRepository;

    public function __construct(DoctrineManager $doctrineManager, EntiteRepository $entiteRepository)
    {
        $this->doctrineManager = $doctrineManager;
        $this->entiteRepository = $entiteRepository;
    }

    public function create(Entite $entite){
        return $this->doctrineManager->saveInDb($entite, 'Problème à la création de l\'entite');
    }

    public function edit(Entite $entite){
        return $this->doctrineManager->saveInDb($entite, 'Erreur à la modification l\'entite');
    }

    public function delete(Entite $entite){
        return $this->doctrineManager->delete($entite, 'Erreur à la suppression l\'entite' );
    }

    public function getRepository(){
        return $this->entiteRepository;
    }
    
}    