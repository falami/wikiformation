<?php

namespace App\Form\Administrateur;

use App\Entity\DocumentFormateur;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{CheckboxType, ChoiceType, FileType, HiddenType, TextareaType, TextType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class DocumentFormateurType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $constraints = [new Assert\File(maxSize: '20M', extensions: ['pdf', 'docx', 'xlsx', 'odt', 'ods'])];
        if ($options['new_document']) $constraints[] = new Assert\NotNull(message: 'Choisissez le document à partager.');
        $builder
            ->add('titre', TextType::class, ['label' => 'Intitulé du document', 'empty_data' => '', 'attr' => ['maxlength' => 180, 'placeholder' => 'Ex. Feuille d’émargement collective', 'class' => 'form-control']])
            ->add('categorie', ChoiceType::class, ['label' => 'Catégorie', 'choices' => DocumentFormateur::CATEGORIES, 'attr' => ['data-document-category' => '']])
            ->add('description', TextareaType::class, ['label' => 'Consignes d’utilisation', 'required' => false, 'attr' => ['rows' => 3, 'maxlength' => 3000, 'class' => 'form-control',]])
            ->add('file', FileType::class, [
                'mapped' => false, 'label' => $options['new_document'] ? 'Fichier à partager' : 'Remplacer le fichier',
                'required' => $options['new_document'], 'constraints' => $constraints,
                'help' => 'PDF, Word (.docx), Excel (.xlsx), OpenDocument (.odt, .ods) · 20 Mo maximum. Utilisez vos documents avec le logo de votre organisme.',
                'attr' => ['accept' => '.pdf,.docx,.xlsx,.odt,.ods', 'class' => 'form-control'],
            ])
            ->add('noteVersion', TextType::class, ['mapped' => false, 'label' => 'Note de mise à jour', 'required' => false, 'constraints' => [new Assert\Length(max: 500)], 'attr' => ['maxlength' => 500, 'placeholder' => 'Ex. Actualisation du questionnaire', 'class' => 'form-control']])
            ->add('publie', CheckboxType::class, ['label' => 'Disponible dans l’espace formateur', 'required' => false, 'help' => 'Décochez pour retirer le document du téléchargement tout en conservant son historique.'])
            ->add('revision', HiddenType::class, ['mapped' => false, 'data' => (string) $builder->getData()->getLockVersion()]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DocumentFormateur::class, 'new_document' => false]);
        $resolver->setAllowedTypes('new_document', 'bool');
    }
}
