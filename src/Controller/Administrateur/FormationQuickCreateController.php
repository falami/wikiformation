<?php

namespace App\Controller\Administrateur;

use App\Entity\{Categorie, Engin, Entite, Formateur, Utilisateur, UtilisateurEntite};
use App\Form\Administrateur\{CategorieType, EnginType, FormateurType};
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\{TextType, EmailType};
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\{Request, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class FormationQuickCreateController extends AbstractController
{
    #[Route('/administrateur/{entite}/formation/creation-rapide/{kind}', name: 'app_formation_quick_create', requirements: ['kind' => 'categorie|engin|formateur'], methods: ['GET', 'POST'])]
    public function create(Entite $entite, string $kind, Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher): JsonResponse
    {
        $this->denyAccessUnlessGranted(TenantPermission::FORMATION_MANAGE, $entite);
        $this->denyAccessUnlessGranted(match ($kind) {
            'engin' => TenantPermission::ENGIN_MANAGE,
            'formateur' => TenantPermission::FORMATEUR_MANAGE,
            default => TenantPermission::FORMATION_MANAGE,
        }, $entite);
        $object = match ($kind) { 'categorie' => new Categorie(), 'engin' => new Engin(), default => new Formateur() };
        $object->setEntite($entite)->setCreateur($this->getUser());
        $form = $this->createForm(match ($kind) { 'categorie' => CategorieType::class, 'engin' => EnginType::class, default => FormateurType::class }, $object, [
            'entite' => $entite,
            'action' => $this->generateUrl('app_formation_quick_create', ['entite' => $entite->getId(), 'kind' => $kind]),
            'csrf_token_id' => 'formation_quick_' . $kind . '_' . $entite->getId(),
        ]);
        $keep = match ($kind) { 'categorie' => ['nom', 'parent', 'slug'], 'engin' => ['nom', 'site', 'type'], default => ['utilisateur'] };
        foreach ($form->all() as $name => $field) {
            if (!in_array($name, $keep, true)) $form->remove($name);
        }
        if ($kind === 'categorie') $form->add('slug', \Symfony\Component\Form\Extension\Core\Type\HiddenType::class, ['required' => false]);
        if ($kind === 'formateur') {
            $options = $form->get('utilisateur')->getConfig()->getOptions();
            $options['required'] = false;
            $options['placeholder'] = 'Nouvelle personne (renseigner ci-dessous)';
            $form->add('utilisateur', \Symfony\Bridge\Doctrine\Form\Type\EntityType::class, $options);
            foreach (['prenom' => 'Prénom', 'nom' => 'Nom', 'email' => 'Adresse e-mail'] as $name => $label) {
                $form->add($name, $name === 'email' ? EmailType::class : TextType::class, [
                    'mapped' => false, 'required' => false, 'label' => $label,
                    'constraints' => $name === 'email' ? [new Assert\Email(), new Assert\Length(max: 180)] : [new Assert\Length(max: 100)],
                ]);
            }
        }
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            $normalize = static fn(string $value): string => (string) (new AsciiSlugger('fr'))->slug(trim($value))->lower();
            if ($kind === 'categorie') $object->setSlug($normalize($object->getNom() ?? ''));
            if ($kind !== 'formateur') {
                foreach ($em->getRepository($object::class)->findBy(['entite' => $entite]) as $existing) {
                    if ($normalize($existing->getNom() ?? '') === $normalize($object->getNom() ?? '')) {
                        $form->get('nom')->addError(new FormError('Ce nom existe déjà dans votre organisme. Sélectionnez l’élément existant.'));
                        break;
                    }
                }
            }
            $newUser = null;
            if ($kind === 'formateur') {
                if ($object->getUtilisateur()) {
                    if ($em->getRepository(Formateur::class)->findOneBy(['entite' => $entite, 'utilisateur' => $object->getUtilisateur()])) {
                        $form->get('utilisateur')->addError(new FormError('Cet utilisateur est déjà formateur dans votre organisme.'));
                    }
                } else {
                    if (!$this->isGranted(TenantPermission::UTILISATEUR_MANAGE, $entite)) {
                        $form->addError(new FormError('La création d’une nouvelle personne nécessite le droit de gestion des utilisateurs.'));
                    }
                    foreach (['prenom', 'nom', 'email'] as $name) {
                        if (trim((string) $form->get($name)->getData()) === '') $form->get($name)->addError(new FormError('Ce champ est requis pour une nouvelle personne.'));
                    }
                    $email = mb_strtolower(trim((string) $form->get('email')->getData()));
                    if ($em->getRepository(Utilisateur::class)->findByCanonicalEmail(['email' => $email])) {
                        $form->get('email')->addError(new FormError('Cette adresse est déjà utilisée. Sélectionnez l’utilisateur de votre organisme ou gérez son rattachement dans Utilisateurs.'));
                    }
                    $newUser = (new Utilisateur())->setPrenom(trim((string) $form->get('prenom')->getData()))->setNom(trim((string) $form->get('nom')->getData()))->setEmail($email)->setCreateur($this->getUser());
                    $newUser->setPassword($hasher->hashPassword($newUser, bin2hex(random_bytes(32))));
                    $object->setUtilisateur($newUser);
                }
            }
            if ($form->isValid()) {
                if ($newUser) {
                    $em->persist($newUser);
                    $membership = (new UtilisateurEntite())->setUtilisateur($newUser)->setEntite($entite)->setCreateur($this->getUser())->setRoles([UtilisateurEntite::TENANT_FORMATEUR]);
                    $em->persist($membership);
                }
                $em->persist($object);
                $em->flush();
                $label = match ($kind) {
                    'categorie' => (string) $object,
                    'engin' => $object->getNom() . ' - ' . $object->getSite()->getNom(),
                    default => $object->getUtilisateur()->getPrenom() . ' ' . $object->getUtilisateur()->getNom() . ' - ' . $object->getUtilisateur()->getEmail(),
                };
                return $this->json(['id' => $object->getId(), 'label' => $label], 201);
            }
        }
        return $this->json(['html' => $this->renderView('administrateur/formation/_quick_create.html.twig', ['form' => $form->createView(), 'kind' => $kind])], $form->isSubmitted() ? 422 : 200);
    }
}
