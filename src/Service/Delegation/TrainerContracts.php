<?php

declare (strict_types=1);
namespace App\Service\Delegation;

use App\Entity\{ContratFormateur, UtilisateurEntite};
use App\Form\Administrateur\ContratFormateurType;
use Symfony\Component\Form\{FormFactoryInterface, FormInterface, FormError};
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Doctrine\ORM\EntityManagerInterface;
final class TrainerContracts
{
    public function __construct(private readonly FormFactoryInterface $forms, private readonly DossierAccess $access, private readonly EntityManagerInterface $em)
    {
    }
    public function form(ContratFormateur $contract, UtilisateurEntite $member): FormInterface
    {
        $form = $this->forms->create(ContratFormateurType::class, $contract, ['entite' => $member->getEntite()]);
        $form->remove('status');
        foreach (['session' => 'sessions', 'formateur' => 'formateurs'] as $field => $module) {
            $choices = $module === 'sessions' ? $this->access->editableRows($member, $module) : $this->access->rows($member, $module);
            $form->add($field, ChoiceType::class, ['label' => $field === 'session' ? 'Session du portefeuille' : 'Formateur', 'choices' => $choices, 'choice_value' => fn($r) => $r?->getId(), 'choice_label' => fn($r) => $field === 'session' ? $r->getCode() : trim($r->getUtilisateur()?->getPrenom() . ' ' . $r->getUtilisateur()?->getNom()), 'placeholder' => 'Sélectionner', 'constraints' => [new \Symfony\Component\Validator\Constraints\NotNull()]]);
        }
        return $form;
    }
    public function validate(FormInterface $form, ContratFormateur $contract): void
    {
        if (!$form->isValid()) {
            return;
        }
        if (!in_array($contract->getFormateur(), $contract->getSession()->getFormateursEffectifs(), true)) {
            $form->addError(new FormError('Affectez d’abord ce formateur au planning de la session.'));
        }
        $existing = $this->em->getRepository(ContratFormateur::class)->findOneBy(['session' => $contract->getSession(), 'formateur' => $contract->getFormateur()]);
        if ($existing && $existing->getId() !== $contract->getId()) {
            $form->addError(new FormError('Un contrat existe déjà pour ce formateur dans cette session.'));
        }
        if ($contract->getMontantPrevuCents() < 0 || $contract->getFraisMissionCents() < 0) {
            $form->addError(new FormError('Les montants doivent être positifs ou nuls.'));
        }
    }
    public function defaults(ContratFormateur $contract): void
    {
        $prefs = $contract->getEntite()->getPreferences();
        if (!$prefs) {
            return;
        }
        foreach (['ConditionsGenerales', 'ConditionsParticulieres', 'ClauseEngagement', 'ClauseObjet', 'ClauseObligations', 'ClauseNonConcurrence', 'ClauseInexecution', 'ClauseAssurance', 'ClauseFinContrat', 'ClauseProprieteIntellectuelle'] as $name) {
            $getter = 'getContratFormateur' . $name . 'Default';
            if (method_exists($prefs, $getter)) {
                $contract->{'set' . $name}($prefs->{$getter}());
            }
        }
    }
}
