<?php
declare(strict_types=1);
namespace App\Service\DocumentStudio;

/** Explicit registry: neither template paths nor property access come from user input. */
final class Catalog
{
    public const TYPES = [
        'convention_contrat'=>['label'=>'Convention de formation','icon'=>'file-earmark-text','group'=>'Contrats'],
        'contrat_formateur'=>['label'=>'Contrat formateur','icon'=>'person-badge','group'=>'Contrats'],
        'devis'=>['label'=>'Devis','icon'=>'receipt','group'=>'Facturation'],
        'facture'=>['label'=>'Facture','icon'=>'receipt-cutoff','group'=>'Facturation'],
        'facture_acquittee'=>['label'=>'Facture acquittée','icon'=>'patch-check','group'=>'Facturation'],
        'convocation'=>['label'=>'Convocation','icon'=>'calendar-event','group'=>'Formation'],
        'attestation'=>['label'=>'Certificat de réalisation','icon'=>'award','group'=>'Formation'],
        'formation_programme_complet'=>['label'=>'Programme de formation','icon'=>'journal-richtext','group'=>'Formation'],
        'emargement_sheet'=>['label'=>'Feuille d’émargement','icon'=>'pen','group'=>'Suivi'],
        'convention_appreciation'=>['label'=>'Appréciation stagiaire','icon'=>'chat-square-heart','group'=>'Suivi'],
        'convention_dossier_inventory'=>['label'=>'Inventaire du dossier','icon'=>'folder-check','group'=>'Suivi'],
        'qcm_questionnaire'=>['label'=>'Questionnaire QCM','icon'=>'ui-checks','group'=>'Évaluation'],
        'qcm_result'=>['label'=>'Résultat QCM','icon'=>'bar-chart','group'=>'Évaluation'],
    ];
    public const FIELDS = [
        'organisme.nom'=>'Organisme · Nom', 'organisme.adresse'=>'Organisme · Adresse',
        'organisme.siret'=>'Organisme · SIRET', 'organisme.email'=>'Organisme · Email',
        'organisme.telephone'=>'Organisme · Téléphone', 'organisme.nda'=>'Organisme · Déclaration d’activité',
        'organisme.signataire'=>'Organisme · Nom du signataire', 'organisme.fonction'=>'Organisme · Fonction du signataire',
        'organisme.signature'=>'Organisme · Signature configurée', 'organisme.cachet'=>'Organisme · Cachet configuré',
        'document.numero'=>'Document · Numéro', 'session.code'=>'Session · Référence',
        'formation.titre'=>'Formation · Intitulé', 'formation.objectifs'=>'Formation · Objectifs',
        'formation.prerequis'=>'Formation · Prérequis', 'stagiaire.prenom'=>'Stagiaire · Prénom',
        'stagiaire.nom'=>'Stagiaire · Nom', 'entreprise.nom'=>'Entreprise · Raison sociale',
        'formateur.prenom'=>'Formateur · Prénom', 'formateur.nom'=>'Formateur · Nom',
    ];
    public const SECTIONS = [
        'convocation'=>['Description & Objectifs','Programme de la formation'],
        'formation_programme_complet'=>['Objectifs pédagogiques','Public concerné','Prérequis','Méthodes et moyens pédagogiques','Modalités pratiques','Modalités d’évaluation','Modalités et délais d’accès','Accessibilité et handicap','Contact pédagogique','Sanction et reconnaissance de la formation','Résultats et indicateurs'],
        'convention_contrat'=>['Objet','Conditions financières','Engagements des parties','Dispositions finales','Règlement et frais annexes','Annulation, report et interruption','Inexécution de la prestation','Réclamations et règlement des différends'],
        'contrat_formateur'=>['5 - Obligations professionnelles','6 - Non-concurrence et non-sollicitation','7 - Inexécution du contrat','8 - Assurance','9 - Fin de contrat','10 - Propriété intellectuelle'],
    ];
    public static function defaults(): array { return ['primary'=>'','accent'=>'','font'=>'original','fields'=>[], 'blocks'=>[]]; }
    public static function requireKind(string $kind): array { return self::TYPES[$kind] ?? throw new \DomainException('Type de document inconnu.'); }
}
