<?php

declare(strict_types=1);
namespace App\Form\Administrateur;
use App\Entity\QualityRecord;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\{TextType, TextareaType, ChoiceType, DateType, HiddenType};
use Symfony\Component\OptionsResolver\OptionsResolver;

final class QualityRecordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('kind', ChoiceType::class, ['label' => 'Type de suivi', 'choices' => QualityRecord::KINDS, 'attr' => ['class' => 'quality-select']])
            ->add('title', TextType::class, ['label' => 'Objet', 'attr' => ['maxlength' => 180]])
            ->add('occurredAt', DateType::class, ['label' => 'Date du constat / de la réception', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'attr' => ['class' => 'quality-date']])
            ->add('owner', TextType::class, ['label' => 'Responsable du suivi', 'attr' => ['maxlength' => 180]])
            ->add('dueAt', DateType::class, ['label' => 'Échéance', 'required' => false, 'widget' => 'single_text', 'input' => 'datetime_immutable', 'attr' => ['class' => 'quality-date']])
            ->add('status', ChoiceType::class, ['label' => 'Statut', 'choices' => QualityRecord::STATUSES, 'attr' => ['class' => 'quality-select']]);
        foreach (['description' => ['Constat et contexte', 'Décrivez les faits utiles. Évitez les données de santé ou personnelles non nécessaires.'], 'actions' => ['Actions et suites données', 'Précisez les actions engagées, les échanges et leur date. Pour une veille, indiquez comment ses enseignements sont utilisés.'], 'evidence' => ['Références des preuves', 'Référence de dossier, document conservé, courrier ou URL de la source. Les références ne remplacent pas la conservation des justificatifs.'], 'conclusion' => ['Conclusion et efficacité', 'Résultat observé, vérification effectuée et date ; suite à prévoir si nécessaire.']] as $key => [$label, $help]) {
            $builder->add($key, TextareaType::class, ['label' => $label, 'help' => $help, 'required' => $key === 'description', 'attr' => ['rows' => 4, 'maxlength' => $key === 'evidence' ? 8000 : 12000]]);
        }
        $builder->add('_version', HiddenType::class, ['mapped' => false, 'data' => (string) $options['version']]);
    }
    public function configureOptions(OptionsResolver $resolver): void { $resolver->setDefaults(['data_class' => QualityRecord::class, 'version' => 1]); }
}
