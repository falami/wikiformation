<?php
// src/Enum/SessionPieceType.php

namespace App\Enum;

enum SessionPieceType: string implements LabelledEnum
{
  case ATTESTATION_FORMATION       = 'attestation_formation';
  case ATTESTATION_ASSIDUITE       = 'attestation_assiduite';
  case CARTE_ID_RECTO              = 'carte_id_recto';
  case CARTE_ID_VERSO              = 'carte_id_verso';
  case CONVENTION_SIGNEE           = 'convention_signee';
  case CONTRAT_FORMATEUR_SIGNE     = 'contrat_formateur_signe';
  case CONTRAT_STAGIAIRE_SIGNE     = 'contrat_stagiaire_signe';
  case COMPTE_RENDU_FORMATEUR      = 'compte_rendu_formateur';
  case DEVIS_SIGNE                 = 'devis_signe';
  case DEMANDE_PRISE_CHARGE        = 'demande_prise_charge';
  case EMARGEMENT_SIGNE            = 'emargement_signe';
  case FACTURE                     = 'facture';
  case FACTURE_ACQUITTEE           = 'facture_acquittee';
  case PREUVE_PAIEMENT             = 'preuve_paiement';
  case QCM_DEBUT_PAPIER            = 'qcm_debut_papier';
  case QCM_FIN_PAPIER              = 'qcm_fin_papier';
  case SATISFACTION_STAGIAIRE      = 'satisfaction_stagiaire';
  case POSITIONNEMENT_PAPIER       = 'positionnement_papier';
  case PROGRAMME                   = 'programme';

  public function label(): string
  {
    return match ($this) {

      self::ATTESTATION_FORMATION   => 'Attestation de formation (URSSAF)',
      self::ATTESTATION_ASSIDUITE   => 'Attestation d’assiduité',
      self::CARTE_ID_RECTO          => 'Carte d\'identité recto',
      self::CARTE_ID_VERSO          => 'Carte d\'identité verso',
      self::CONVENTION_SIGNEE       => 'Convention signée',
      self::CONTRAT_FORMATEUR_SIGNE => 'Contrat formateur signé',
      self::CONTRAT_STAGIAIRE_SIGNE => 'Contrat stagiaire signé',
      self::COMPTE_RENDU_FORMATEUR  => 'Compte rendu formateur',
      self::DEVIS_SIGNE             => 'Devis signé',
      self::DEMANDE_PRISE_CHARGE    => 'Demande de prise en charge (OPCO)',
      self::EMARGEMENT_SIGNE        => 'Émargements signés',
      self::FACTURE                 => 'Facture',
      self::FACTURE_ACQUITTEE       => 'Facture acquittée',
      self::PREUVE_PAIEMENT         => 'Preuve de paiement',
      self::QCM_DEBUT_PAPIER        => 'QCM début',
      self::QCM_FIN_PAPIER          => 'QCM fin',
      self::SATISFACTION_STAGIAIRE  => 'Satisfaction stagiaire',
      self::POSITIONNEMENT_PAPIER   => 'Test de positionnement',
      self::PROGRAMME               => 'Programme détaillé',
    };
  }
}
