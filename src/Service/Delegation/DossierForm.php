<?php

declare (strict_types=1);
namespace App\Service\Delegation;

use App\Entity\{UtilisateurEntite, Prospect, Session, Inscription};
use App\Enum\{ProspectStatus, StatusInscription};
use Symfony\Component\Form\{FormFactoryInterface, FormInterface};
use Symfony\Component\Form\Extension\Core\Type\{FormType, TextType, TextareaType, IntegerType, EmailType, DateTimeType, ChoiceType, EnumType};
use Symfony\Component\Validator\Constraints as Assert;
final class DossierForm
{
    public function __construct(private readonly FormFactoryInterface $factory, private readonly DossierRegistry $registry, private readonly DossierAccess $access)
    {
    }
    public function editable(string $module): bool
    {
        return !in_array($module, DossierRegistry::FINANCIAL_MODULES, true);
    }
    public function build(string $module, object $record, UtilisateurEntite $membership, bool $new): FormInterface
    {
        $builder = $this->factory->createNamedBuilder('dossier', FormType::class, $record, ['data_class' => $record::class, 'csrf_token_id' => 'dossier_' . $membership->getId() . '_' . $module . '_' . $record->getId()]);
        if ($module === 'clients') {
            foreach ($new ? ['prenom' => 'Prénom', 'nom' => 'Nom', 'email' => 'E-mail'] : ['prenom' => 'Prénom', 'nom' => 'Nom', 'telephone' => 'Téléphone'] as $field => $label) {
                $constraints = $field === 'telephone' ? [new Assert\Length(max: 180)] : [new Assert\NotBlank(), new Assert\Length(max: 180)];
                if ($field === 'email') {
                    $constraints[] = new Assert\Email();
                }
                $builder->add($field, $field === 'email' ? EmailType::class : TextType::class, ['mapped' => false, 'label' => $label, 'constraints' => $constraints, 'data' => $new ? null : $record->getUtilisateur()->{'get' . ucfirst($field)}()]);
            }
        }
        foreach ($this->registry->config($module)[3] as $field => $label) {
            if (!method_exists($record, 'set' . ucfirst($field)) || in_array($field, ['code', 'montantDuCents'], true)) {
                continue;
            }
            $required = in_array($field, ['nom', 'prenom', 'raisonSociale', 'titre', 'capacite', 'duree'], true);
            $type = TextType::class;
            $options = ['label' => $label, 'required' => $required, 'constraints' => $required ? [new Assert\NotBlank()] : []];
            if (in_array($field, ['capacite', 'duree'], true)) {
                $type = IntegerType::class;
                $options['constraints'][] = new Assert\Range(min: 1, max: 10000);
            } elseif ($field === 'nextActionAt') {
                $type = DateTimeType::class;
                $options += ['widget' => 'single_text', 'input' => 'datetime_immutable'];
            } else {
                if (str_starts_with($field, 'email')) {
                    $type = EmailType::class;
                } elseif (in_array($field, ['description', 'notes', 'objectifs', 'pedagogie', 'conditionPrealable', 'modalitesEvaluation', 'certifications'], true)) {
                    $type = TextareaType::class;
                }
                $options['constraints'][] = new Assert\Length(max: $type === TextareaType::class ? 10000 : 180);
                if ($type === EmailType::class) {
                    $options['constraints'][] = new Assert\Email();
                }
            }
            $builder->add($field, $type, $options);
        }
        if ($record instanceof Prospect) {
            $builder->add('linkedEntreprise', ChoiceType::class, ['label' => 'Entreprise du portefeuille', 'choices' => $this->access->editableRows($membership, 'entreprises'), 'choice_value' => fn($c) => $c?->getId(), 'choice_label' => fn($c) => $c->getRaisonSociale(), 'required' => false, 'placeholder' => 'Prospect non encore rattaché']);
            $builder->add('status', EnumType::class, ['class' => ProspectStatus::class, 'choice_label' => fn($s) => $s->label(), 'label' => 'Étape commerciale']);
        }
        if ($record instanceof Inscription) {
            $builder->add('status', EnumType::class, ['class' => StatusInscription::class, 'choices' => [StatusInscription::PREINSCRIT, StatusInscription::CONFIRME, StatusInscription::EN_COURS, StatusInscription::ANNULE, StatusInscription::ABSENT], 'choice_label' => fn($s) => $s->label(), 'label' => 'Statut']);
        }
        if ($new && $record instanceof Session) {
            $builder->add('start', DateTimeType::class, ['mapped' => false, 'label' => 'Début du premier créneau', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'constraints' => [new Assert\NotNull()]])->add('end', DateTimeType::class, ['mapped' => false, 'label' => 'Fin du premier créneau', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'constraints' => [new Assert\NotNull()]]);
        }
        if ($module === 'clients' && $new) {
            $builder->add('company', ChoiceType::class, ['mapped' => false, 'label' => 'Entreprise du portefeuille', 'choices' => $this->access->editableRows($membership, 'entreprises'), 'choice_value' => fn($c) => $c?->getId(), 'choice_label' => fn($c) => $c->getRaisonSociale(), 'required' => false, 'placeholder' => 'Sélectionner une entreprise']);
        }
        if ($record instanceof Session && $new) {
            $builder->add('entrepriseCliente', ChoiceType::class, ['label' => 'Entreprise cliente', 'choices' => $this->access->editableRows($membership, 'entreprises'), 'choice_value' => fn($c) => $c?->getId(), 'choice_label' => fn($c) => $c->getRaisonSociale(), 'required' => false, 'placeholder' => 'Sans entreprise cliente']);
        }
        if ($new || $record instanceof Session) {
            $relations = match ($module) {
                'sessions' => ['formation' => ['formations', 'Formation', false], 'site' => ['sites', 'Site', true], 'formateur' => ['formateurs', 'Formateur', false]],
                'inscriptions' => ['session' => ['sessions', 'Session', true], 'stagiaire' => ['clients', 'Stagiaire', true], 'entreprise' => ['entreprises', 'Entreprise', false]],
                'formateurs' => ['utilisateur' => ['clients', 'Compte existant (facultatif)', false]],
                default => [],
            };
            foreach ($relations as $field => [$relatedModule, $label, $required]) {
                $choices = in_array($relatedModule, ['clients', 'sessions', 'entreprises'], true) ? $this->access->editableRows($membership, $relatedModule) : $this->access->rows($membership, $relatedModule);
                if ($relatedModule === 'clients') {
                    $choices = array_map(fn($m) => $m->getUtilisateur(), $choices);
                }
                $builder->add($field, ChoiceType::class, ['label' => $label, 'choices' => $choices, 'choice_value' => fn($r) => $r?->getId(), 'choice_label' => fn($r) => $relatedModule === 'clients' ? trim($r->getPrenom() . ' ' . $r->getNom()) : $this->registry->title($r), 'placeholder' => 'Sélectionner un dossier attribué', 'required' => $required, 'constraints' => $required ? [new Assert\NotNull()] : []]);
            }
        }
        if ($new && $module === 'formateurs') {
            foreach (['prenom' => 'Prénom du nouveau formateur', 'nom' => 'Nom du nouveau formateur', 'email' => 'E-mail du nouveau formateur'] as $field => $label) {
                $builder->add($field, $field === 'email' ? EmailType::class : TextType::class, ['mapped' => false, 'required' => false, 'label' => $label, 'constraints' => $field === 'email' ? [new Assert\Email(), new Assert\Length(max: 180)] : [new Assert\Length(max: 180)]]);
            }
        }
        return $builder->getForm();
    }
}
