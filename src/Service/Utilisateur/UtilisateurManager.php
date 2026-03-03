<?php

namespace App\Service\Utilisateur;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use App\Service\Doctrine\DoctrineManager;

class UtilisateurManager {

    private $doctrineManager;
    private $utilisateurRepository;

    public function __construct(DoctrineManager $doctrineManager, UtilisateurRepository $utilisateurRepository)
    {
        $this->doctrineManager = $doctrineManager;
        $this->utilisateurRepository = $utilisateurRepository;
    }

    public function create(Utilisateur $utilisateur){
        return $this->doctrineManager->saveInDb($utilisateur, 'Problème à la création de l\'utilisateur');
    }

    public function edit(Utilisateur $utilisateur){
        return $this->doctrineManager->saveInDb($utilisateur, 'Erreur à la modification l\'utilisateur');
    }

    public function delete(Utilisateur $utilisateur){
        return $this->doctrineManager->delete($utilisateur, 'Erreur à la suppression l\'utilisateur' );
    }

    public function getRepository(){
        return $this->utilisateurRepository;
    }


}