<?php

declare (strict_types=1);
namespace App\Service\Delegation;

use App\Entity\{Prospect, Session, Inscription, Formateur, Entreprise, UtilisateurEntite, Formation, ConventionContrat, Site, Devis, Facture, ContratFormateur};
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
/** Deliberate projection: never serialize full entities or signature/payment secrets. */
final class DossierRegistry
{
    public const MODULES = ['contrats-formateurs' => [ContratFormateur::class, 'Contrats formateurs', 'file-earmark-medical', ['numero' => 'Référence', 'montantPrevuCents' => 'Honoraires HT', 'fraisMissionCents' => 'Frais de mission HT', 'conditionsGenerales' => 'Conditions générales', 'conditionsParticulieres' => 'Conditions particulières']], 'prospects' => [Prospect::class, 'Prospects', 'person-plus', ['prenom' => 'Prénom', 'nom' => 'Nom', 'email' => 'E-mail', 'telephone' => 'Téléphone', 'societe' => 'Société', 'ville' => 'Ville', 'notes' => 'Notes', 'nextActionAt' => 'Prochaine relance']], 'sessions' => [Session::class, 'Sessions', 'calendar-event', ['code' => 'Code', 'formationIntituleLibre' => 'Intitulé libre', 'capacite' => 'Capacité']], 'inscriptions' => [Inscription::class, 'Inscriptions', 'person-check', ['montantDuCents' => 'Montant dû']], 'formateurs' => [Formateur::class, 'Formateurs', 'person-video3', ['certifications' => 'Certifications', 'adresse' => 'Adresse', 'codePostal' => 'Code postal', 'ville' => 'Ville', 'siret' => 'SIRET']], 'entreprises' => [Entreprise::class, 'Entreprises', 'buildings', ['raisonSociale' => 'Raison sociale', 'siret' => 'SIRET', 'email' => 'E-mail', 'emailFacturation' => 'E-mail de facturation', 'telephone' => 'Téléphone', 'adresse' => 'Adresse', 'codePostal' => 'Code postal', 'ville' => 'Ville']], 'clients' => [UtilisateurEntite::class, 'Stagiaires & contacts', 'people', []], 'formations' => [Formation::class, 'Formations', 'journal-bookmark', ['titre' => 'Intitulé', 'description' => 'Description', 'duree' => 'Durée en jours', 'objectifs' => 'Objectifs', 'conditionPrealable' => 'Prérequis', 'pedagogie' => 'Pédagogie', 'modalitesEvaluation' => 'Modalités d’évaluation']], 'conventions' => [ConventionContrat::class, 'Conventions', 'file-earmark-check', ['numero' => 'Référence', 'intituleFormation' => 'Formation', 'dureeFormation' => 'Durée', 'conditionsFinancieres' => 'Conditions financières', 'montantHtCents' => 'Montant HT']], 'sites' => [Site::class, 'Sites', 'geo-alt', ['nom' => 'Nom', 'adresse' => 'Adresse', 'codePostal' => 'Code postal', 'ville' => 'Ville', 'pays' => 'Pays']], 'devis' => [Devis::class, 'Devis', 'file-earmark-text', ['numero' => 'Référence', 'dateEmission' => 'Date d’émission', 'dateValidite' => 'Valable jusqu’au', 'montantHtCents' => 'Montant HT', 'montantTtcCents' => 'Montant TTC']], 'factures' => [Facture::class, 'Factures', 'receipt', ['numero' => 'Référence', 'dateEmission' => 'Date d’émission', 'montantHtCents' => 'Montant HT', 'montantTtcCents' => 'Montant TTC', 'note' => 'Note']]];
    public const PARTNER_MODULES = ['inscriptions', 'conventions', 'factures'];
    public const FINANCIAL_MODULES = ['conventions', 'devis', 'factures'];
    public function config(string $module): array
    {
        return self::MODULES[$module] ?? throw new NotFoundHttpException();
    }
    public function title(object $record): string
    {
        if ($record instanceof UtilisateurEntite) {
            return trim($record->getUtilisateur()?->getPrenom() . ' ' . $record->getUtilisateur()?->getNom());
        }
        if ($record instanceof Formateur) {
            return trim($record->getUtilisateur()?->getPrenom() . ' ' . $record->getUtilisateur()?->getNom());
        }
        if ($record instanceof Inscription) {
            return '#' . $record->getId() . ' · ' . trim($record->getStagiaire()?->getPrenom() . ' ' . $record->getStagiaire()?->getNom());
        }
        if ($record instanceof Prospect) {
            return trim($record->getPrenom() . ' ' . $record->getNom());
        }
        foreach (['getRaisonSociale', 'getTitre', 'getNumero', 'getCode', 'getNom'] as $getter) {
            if (method_exists($record, $getter) && $record->{$getter}()) {
                return (string) $record->{$getter}();
            }
        }
        return 'Dossier n° ' . $record->getId();
    }
    public function details(string $module, object $record): array
    {
        $values = [];
        foreach ($this->config($module)[3] as $field => $label) {
            $getter = 'get' . ucfirst($field);
            if (!method_exists($record, $getter)) {
                continue;
            }
            $value = $record->{$getter}();
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('d/m/Y H:i');
            } elseif (str_ends_with($field, 'Cents') && $value !== null) {
                $value = number_format($value / 100, 2, ',', ' ') . ' €';
            }
            $values[$label] = $value === null || $value === '' ? 'Non renseigné' : (string) $value;
        }
        if ($record instanceof UtilisateurEntite) {
            $values = ['Nom' => $this->title($record), 'E-mail' => $record->getUtilisateur()?->getEmail(), 'Téléphone' => $record->getUtilisateur()?->getTelephone() ?: 'Non renseigné'];
        }
        if (method_exists($record, 'getStatus')) {
            $status = $record->getStatus();
            $values['Statut'] = is_object($status) && method_exists($status, 'label') ? $status->label() : (is_string($status) ? $status : $status->value);
        }
        if ($record instanceof Inscription) {
            $values['Session'] = $record->getSession()?->getCode() ?: 'Non renseignée';
        }
        if ($record instanceof Session) {
            $values['Formation'] = ($record->getFormation()?->getTitre() ?: $record->getFormationIntituleLibre()) ?: 'Non renseignée';
            $values['Planning'] = implode("\n", array_map(fn($j) => $j->getDateDebut()?->format('d/m/Y H:i') . ' → ' . $j->getDateFin()?->format('H:i'), $record->getJours()->toArray())) ?: 'Aucun créneau';
        }
        return $values;
    }
}
