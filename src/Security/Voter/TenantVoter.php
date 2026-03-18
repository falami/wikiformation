<?php

namespace App\Security\Voter;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Entity\UtilisateurEntite;
use App\Repository\UtilisateurEntiteRepository;
use App\Security\Permission\TenantPermission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class TenantVoter extends Voter
{
  public function __construct(
    private readonly UtilisateurEntiteRepository $ueRepo
  ) {}

  protected function supports(string $attribute, mixed $subject): bool
  {
    return $subject instanceof Entite
      && in_array($attribute, TenantPermission::ALL, true);
  }

  protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
  {
    $user = $token->getUser();
    if (!$user instanceof Utilisateur) {
      return false;
    }

    /** @var Entite $entite */
    $entite = $subject;

    // ✅ Super admin : tout OK SAUF ENTREPRISE (optionnel mais recommandé)
    if ($user->isSuperAdmin()) {
      if ($attribute === TenantPermission::ENTREPRISE) {
        return $user->getEntreprise() !== null;
      }
      return true;
    }

    $membership = $this->ueRepo->findOneBy([
      'utilisateur' => $user,
      'entite'      => $entite,
    ]);

    if (!$membership instanceof UtilisateurEntite || !$membership->isActive()) {
      return false;
    }

    if ($attribute === TenantPermission::ACCESS) {
      return true;
    }

    $isAdmin = $membership->isTenantAdmin();
    if ($attribute === TenantPermission::ADMIN) {
      return $isAdmin;
    }

    // ✅ Cas spécial ENTREPRISE : rôle + entreprise liée
    if ($attribute === TenantPermission::ENTREPRISE) {
      return $membership->hasRole(UtilisateurEntite::TENANT_ENTREPRISE)
        && $user->getEntreprise() !== null;
    }

    // ✅ Extranet entreprise : uniquement si rôle entreprise + entreprise liée
    if (in_array($attribute, self::ENTREPRISE_ALLOWED, true)) {
      return $membership->hasRole(UtilisateurEntite::TENANT_ENTREPRISE)
        && $user->getEntreprise() !== null;
    }

    // ✅ Extranet stagiaire
    if (in_array($attribute, self::STAGIAIRE_ALLOWED, true)) {
      return $membership->hasRole(UtilisateurEntite::TENANT_STAGIAIRE);
    }

    // ✅ Extranet formateur
    if (in_array($attribute, self::FORMATEUR_ALLOWED, true)) {
      return $membership->hasRole(UtilisateurEntite::TENANT_FORMATEUR);
    }

    // ✅ Rôles “simples”
    $roleMap = [
      TenantPermission::FORMATEUR  => UtilisateurEntite::TENANT_FORMATEUR,
      TenantPermission::STAGIAIRE  => UtilisateurEntite::TENANT_STAGIAIRE,
      TenantPermission::OF         => UtilisateurEntite::TENANT_OF,
      TenantPermission::OPCO       => UtilisateurEntite::TENANT_OPCO,
      TenantPermission::COMMERCIAL => UtilisateurEntite::TENANT_COMMERCIAL,
    ];

    if (isset($roleMap[$attribute])) {
      return $membership->hasRole($roleMap[$attribute]);
    }

    // ✅ Le reste = admin only
    return in_array($attribute, self::ADMIN_ONLY, true) ? $isAdmin : false;
  }


  private const FORMATEUR_ALLOWED = [
    TenantPermission::FORMATEUR_DASHBOARD_MANAGE,
    TenantPermission::FORMATEUR_EMARGEMENT_MANAGE,
    TenantPermission::FORMATEUR_ESPACE_MANAGE,
    TenantPermission::FORMATEUR_SATISFACTION_MANAGE,
    TenantPermission::FORMATEUR_POSITIONING_MANAGE,
    TenantPermission::FORMATEUR_RAPPORT_MANAGE,
    TenantPermission::FORMATEUR_EMARGEMENT_PDF_MANAGE,
  ];


  private const ENTREPRISE_ALLOWED = [
    TenantPermission::DASHBOARD_ENTREPRISE_MANAGE,
    TenantPermission::ENTREPRISE_DOCUMENTS_MANAGE,
    TenantPermission::CONVENTION_SIGNATURE_ENTREPRISE_MANAGE,
  ];



  private const STAGIAIRE_ALLOWED = [

    TenantPermission::STAGIAIRE_CONVENTION_SIGNATURE_MANAGE,
    TenantPermission::STAGIAIRE_COURS_MANAGE,
    TenantPermission::STAGIAIRE_DASHBOARD_MANAGE,
    TenantPermission::STAGIAIRE_DOSSIER_INSCRIPTION_MANAGE,
    TenantPermission::STAGIAIRE_ELEARNING_MANAGE,
    TenantPermission::STAGIAIRE_FORMATION_CONTENT_MANAGE,
    TenantPermission::STAGIAIRE_POSITIONING_MANAGE,
    TenantPermission::STAGIAIRE_QCM_MANAGE,
    TenantPermission::STAGIAIRE_POSITIONING_LIST_MANAGE,
    TenantPermission::STAGIAIRE_SATISFACTION_MANAGE,
    TenantPermission::STAGIAIRE_ESPACE_MANAGE,
    TenantPermission::STAGIAIRE_EMARGEMENT_MANAGE,
  ];


  private const ADMIN_ONLY = [

    TenantPermission::PREMIUM_MANAGE,
    TenantPermission::CONTRAT_FORMATEUR_MANAGE,
    TenantPermission::DEPENSE_MANAGE,
    TenantPermission::QCM_ASSIGNMENT_MANAGE,
    TenantPermission::FORMATEUR_SATISFACTION_ASSIGNMENT_MANAGE,
    TenantPermission::FORMATEUR_MANAGE,
    TenantPermission::FORMATEUR_SATISFACTION_ATTEMPT_MANAGE,
    TenantPermission::FORMATEUR_SATISFACTION_TEMPLATE_MANAGE,
    TenantPermission::FORMATION_CONTENT_MANAGE,
    TenantPermission::TVA_API_MANAGE,
    TenantPermission::SESSION_STAGIAIRE_MANAGE,
    TenantPermission::QCM_ATTEMPT_MANAGE,
    TenantPermission::QCM_MANAGE,
    TenantPermission::QCM_FOLLOW_UP_MANAGE,
    TenantPermission::RESERVATION_MANAGE,
    TenantPermission::SATISFACTION_ASSIGNMENT_MANAGE,
    TenantPermission::SATISFACTION_ATTEMPT_MANAGE,
    TenantPermission::SATISFACTION_MANAGE,
    TenantPermission::SATISFACTION_RESULT_MANAGE,
    TenantPermission::SATISFACTION_TEMPLATE_MANAGE,
    TenantPermission::SESSION_PIECE_MANAGE,
    TenantPermission::SESSION_MANAGE,
    TenantPermission::SESSION_POSITION_MANAGE,
    TenantPermission::SITE_MANAGE,
    TenantPermission::TVA_DASHBOARD_MANAGE,
    TenantPermission::TVA_DATATABLE_MANAGE,
    TenantPermission::UTILISATEUR_MANAGE,
    TenantPermission::UTILISATEUR_DEVIS_MANAGE,
    TenantPermission::UTILISATEUR_EMAIL_MANAGE,
    TenantPermission::UTILISATEUR_FACTURE_MANAGE,
    TenantPermission::FORMATION_CONTENT_REORDER_MANAGE,
    TenantPermission::FORMATION_MANAGE,
    TenantPermission::INSCRIPTION_MANAGE,
    TenantPermission::OCR_MANAGE,
    TenantPermission::PAIEMENT_MANAGE,
    TenantPermission::PASSEPORT_PREVENTION_MANAGE,
    TenantPermission::PLANNING_FORMATEUR_MANAGE,
    TenantPermission::PLANNING_SESSION_MANAGE,
    TenantPermission::PLANNING_STAGIAIRES_MANAGE,
    TenantPermission::POSITIONING_ASSIGNMENT_MANAGE,
    TenantPermission::POSITIONING_CHAPTER_MANAGE,
    TenantPermission::POSITIONING_MANAGE,
    TenantPermission::POSITIONING_ITEM_MANAGE,
    TenantPermission::POSITIONING_QUESTIONNAIRE_MANAGE,
    TenantPermission::PROSPECT_MANAGE,
    TenantPermission::PROSPECT_DEVIS_MANAGE,
    TenantPermission::PROSPECT_EMAIL_MANAGE,
    TenantPermission::PROSPECT_INTERACTION_MANAGE,
    TenantPermission::ENTREPRISE_MANAGE,
    TenantPermission::EMAIL_TEMPLATE_MANAGE,
    TenantPermission::ENGIN_MANAGE,
    TenantPermission::ENTITE_PREFERENCE_MANAGE,
    TenantPermission::FINANCE_DASHBOARD_MANAGE,
    TenantPermission::FINANCE_DATATABLE_MANAGE,
    TenantPermission::CONVENTION_MANAGE,
    TenantPermission::DEVIS_MANAGE,
    TenantPermission::DOCUMENT_PDF_MANAGE,
    TenantPermission::DOSSIER_INSCRIPTION_MANAGE,
    TenantPermission::ELEARNING_CONTENT_MANAGE,
    TenantPermission::ELEARNING_CONTENT_REORDER_MANAGE,
    TenantPermission::ELEARNING_COURSE_MANAGE,
    TenantPermission::ELEARNING_ENROLLMENT_MANAGE,
    TenantPermission::BPF_MANAGE,
    TenantPermission::CATEGORIE_MANAGE,
    TenantPermission::CHARGE_MANAGE,
    TenantPermission::ADMIN_DASHBOARD_MANAGE,
    TenantPermission::AVOIR_MANAGE,
    TenantPermission::FACTURE_MANAGE,
    TenantPermission::ATTESTATION_MANAGE,
    TenantPermission::USERS_MANAGE,
    TenantPermission::BILLING_MANAGE,
  ];
}
