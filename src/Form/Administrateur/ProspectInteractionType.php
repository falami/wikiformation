<?php

namespace App\Form\Administrateur;

use App\Entity\Utilisateur;
use App\Entity\Prospect;
use Doctrine\ORM\EntityRepository;
use App\Entity\ProspectInteraction;
use App\Enum\InteractionChannel;
use App\Form\DataTransformer\FrenchToDateTimeTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\{
  TextType,
  TextareaType,
  EnumType,
};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

final class ProspectInteractionType extends AbstractType
{
  public function __construct(
    private FrenchToDateTimeTransformer $frenchToDateTimeTransformer,
    private EntityManagerInterface $em
  ) {}
  public function buildForm(FormBuilderInterface $b, array $o): void
  {
    $choices = $this->buildActorChoices($o);
    $b
      ->add('channel', EnumType::class, [
        'class' => InteractionChannel::class,
        'label' => '*Canal',
        'attr'  => ['class' => 'form-select js-tomselect'],
        'choice_label' => fn(InteractionChannel $c) => $c->label(),
        'placeholder' => 'Choisir…',
        'required' => true,
      ])

      ->add('title', TextType::class, [
        'label' => '*Titre',
        'attr' => [
          'class' => 'form-control',
          'placeholder' => 'Ex: Relance téléphonique / Email devis / RDV',
          'autocomplete' => 'off',
          'maxlength' => 120,
        ],
      ])

      // ✅ on passe en TextType + transformer + flatpickr
      ->add('occurredAt', TextType::class, [
        'label' => 'Date & heure',
        'required' => true,
        'attr' => [
          'class' => 'form-control js-flatpickr-dt',
          'inputmode' => 'numeric',
          'autocomplete' => 'off',
          'placeholder' => 'JJ/MM/AAAA HH:mm',
        ],
      ])

      ->add('content', TextareaType::class, [
        'label' => 'Compte rendu',
        'required' => false,
        'attr' => [
          'class' => 'form-control',
          'rows' => 6,
          'placeholder' => 'Détails : points abordés, objections, prochaine étape…',
        ],
      ])
    ;

    $b->add('actorMixed', ChoiceType::class, [
      'mapped' => false,
      'required' => false,
      'label' => 'Rédacteur',
      'placeholder' => 'Choisir…',
      'choices' => $choices,
      'attr' => ['class' => 'form-select js-tomselect'],

      // ✅ défaut immédiat (si création et champ vide)
      'data' => (
        ($o['data'] instanceof ProspectInteraction && $o['data']->getActor())
        ? 'u:' . $o['data']->getActor()->getId()
        : (($o['data'] instanceof ProspectInteraction && $o['data']->getActorProspect())
          ? 'p:' . $o['data']->getActorProspect()->getId()
          : (!empty($o['current_user']) && $o['current_user'] instanceof Utilisateur
            ? 'u:' . $o['current_user']->getId()
            : null
          )
        )
      ),
    ]);


    // 2) Pré-remplissage (edit / default current_user)
    $b->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $e) use ($o) {
      $interaction = $e->getData();
      $form = $e->getForm();

      if (!$interaction) return;

      if ($interaction->getActor()) {
        $form->get('actorMixed')->setData('u:' . $interaction->getActor()->getId());
        return;
      }
      if ($interaction->getActorProspect()) {
        $form->get('actorMixed')->setData('p:' . $interaction->getActorProspect()->getId());
        return;
      }

      // création -> user connecté par défaut
      if (!empty($o['current_user']) && $o['current_user'] instanceof Utilisateur) {
        $form->get('actorMixed')->setData('u:' . $o['current_user']->getId());
      }
    });

    // 3) À la soumission, on split vers actor / actorProspect
    // 3) À la soumission, on split vers actor / actorProspect
    $b->addEventListener(FormEvents::SUBMIT, function (FormEvent $e) use ($o) {
      /** @var ProspectInteraction $interaction */
      $interaction = $e->getData();
      $form = $e->getForm();

      $val = (string)($form->get('actorMixed')->getData() ?? '');

      // reset
      $interaction->setActor(null);
      $interaction->setActorProspect(null);

      // ✅ si vide -> user connecté par défaut
      if ($val === '') {
        if (!empty($o['current_user']) && $o['current_user'] instanceof Utilisateur) {
          $interaction->setActor($o['current_user']);
        }
        return;
      }

      if (preg_match('/^(u|p):(\d+)$/', $val, $m)) {
        $type = $m[1];
        $id   = (int)$m[2];

        if ($type === 'u') {
          $u = $this->em->getRepository(Utilisateur::class)->find($id);
          if ($u) $interaction->setActor($u);
        } else {
          $p = $this->em->getRepository(Prospect::class)->find($id);
          if ($p) $interaction->setActorProspect($p);
        }
      } else {
        // ✅ fallback sécurité (valeur inattendue)
        if (!empty($o['current_user']) && $o['current_user'] instanceof Utilisateur) {
          $interaction->setActor($o['current_user']);
        }
      }
    });


    $b->get('occurredAt')->addModelTransformer($this->frenchToDateTimeTransformer);
  }


  private function buildActorChoices(array $o): array
  {
    $entite = $o['entite'] ?? null;

    // Utilisateurs de l'entité
    $users = $this->em->getRepository(Utilisateur::class)
      ->createQueryBuilder('u')
      ->join('u.utilisateurEntites', 'ue')
      ->andWhere('ue.entite = :e')->setParameter('e', $entite)
      ->orderBy('u.nom', 'ASC')->addOrderBy('u.prenom', 'ASC')
      ->getQuery()->getResult();

    // Prospects de l'entité (actifs / non convertis, à ton goût)
    $prospects = $this->em->getRepository(Prospect::class)
      ->createQueryBuilder('p')
      ->andWhere('p.entite = :e')->setParameter('e', $entite)
      ->orderBy('p.nom', 'ASC')->addOrderBy('p.prenom', 'ASC')
      ->getQuery()->getResult();

    $choices = [];

    // Optionnel : regrouper visuellement dans les libellés
    foreach ($users as $u) {
      $name = trim(($u->getPrenom() ?? '') . ' ' . ($u->getNom() ?? ''));
      $label = '👤 ' . $name;
      if ($label === '👤') $label = '👤 ' . ($u->getEmail() ?? ('Utilisateur #' . $u->getId()));
      $choices[$label] = 'u:' . $u->getId();
    }

    foreach ($prospects as $p) {
      $choices['🟡 ' . $p->getFullName()] = 'p:' . $p->getId();
    }

    return $choices;
  }


  public function configureOptions(OptionsResolver $r): void
  {
    $r->setDefaults([
      'data_class' => ProspectInteraction::class,
      'entite' => null,
      'current_user' => null, // 👈 ajouté
    ]);
  }
}
