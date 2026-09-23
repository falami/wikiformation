<?php

namespace App\Controller\Administrateur;

use App\Entity\{Devis, Entite, Session, SessionJour, Site, Utilisateur, UtilisateurEntite};
use App\Enum\DevisStatus;
use App\Exception\BillingQuotaExceededException;
use App\Form\Administrateur\{DevisConventionClientType, DevisConventionSessionType, DevisConventionSiteType};
use App\Security\Permission\TenantPermission;
use App\Service\Billing\BillingGuard;
use App\Service\Sequence\SessionNumberGenerator;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[IsGranted(TenantPermission::DEVIS_MANAGE, subject: 'entite')]
#[IsGranted(TenantPermission::CONVENTION_MANAGE, subject: 'entite')]
final class DevisConventionQuickCreateController extends AbstractController
{
    #[Route('/administrateur/{entite}/devis/{id}/convention/ajouter/{kind}', name: 'app_administrateur_devis_convention_quick_create', requirements: ['entite' => '\d+', 'id' => '\d+', 'kind' => 'session|site|client'], methods: ['GET', 'POST'])]
    public function create(
        #[MapEntity(id: 'entite')] Entite $entite,
        #[MapEntity(id: 'id')] Devis $devis,
        string $kind,
        Request $request,
        EntityManagerInterface $em,
        SessionNumberGenerator $sessionNumbers,
        UserPasswordHasherInterface $passwordHasher,
        BillingGuard $billingGuard,
        SluggerInterface $slugger,
    ): JsonResponse {
        if ($devis->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(match ($kind) {
            'session' => TenantPermission::SESSION_MANAGE,
            'site' => TenantPermission::SITE_MANAGE,
            'client' => TenantPermission::UTILISATEUR_MANAGE,
        }, $entite);
        if ($devis->getStatus() === DevisStatus::CANCELED || $devis->getProspect()
            || ($devis->getEntrepriseDestinataire() === null) === ($devis->getDestinataire() === null)
            || ($devis->getEntrepriseDestinataire() && $devis->getEntrepriseDestinataire()->getEntite()?->getId() !== $entite->getId())
            || ($devis->getFormation() && $devis->getFormation()->getEntite()?->getId() !== $entite->getId())) {
            return $this->json(['message' => 'Ce devis ne permet pas de préparer une convention.'], 422);
        }

        $initial = match ($kind) {
            'session' => ['formation' => $devis->getFormation(), 'capacite' => 8, 'jours' => [new SessionJour()]],
            'site' => ['pays' => 'France'],
            default => [],
        };
        $operations = $request->getSession()->get('devis_convention_quick_operations', []);
        if ($request->isMethod('GET')) {
            $initial['operation'] = bin2hex(random_bytes(24));
            $operations[$initial['operation']] = ['devis' => $devis->getId(), 'kind' => $kind, 'result' => null];
            $request->getSession()->set('devis_convention_quick_operations', array_slice($operations, -50, null, true));
        }
        $type = match ($kind) {
            'session' => DevisConventionSessionType::class,
            'site' => DevisConventionSiteType::class,
            'client' => DevisConventionClientType::class,
        };
        $options = [
            'action' => $this->generateUrl('app_administrateur_devis_convention_quick_create', ['entite' => $entite->getId(), 'id' => $devis->getId(), 'kind' => $kind]),
            'csrf_token_id' => 'devis_convention_quick_' . $kind . '_' . $devis->getId(),
        ];
        if ($kind === 'session') {
            $options['devis'] = $devis;
        }
        $form = $this->createForm($type, $initial, $options)->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $operation = $operations[$data['operation']] ?? null;
            if (!$operation || $operation['devis'] !== $devis->getId() || $operation['kind'] !== $kind) {
                $form->addError(new FormError('Ce formulaire a expiré. Fermez cette fenêtre puis rouvrez-la.'));
            } elseif ($operation['result']) {
                return $this->json($operation['result']);
            } else {
                /** @var Utilisateur $creator */
                $creator = $this->getUser();
                try {
                    $result = $em->wrapInTransaction(function () use ($em, $data, $kind, $entite, $devis, $creator, $sessionNumbers, $slugger, $passwordHasher, $billingGuard): array {
                        if ($kind === 'session') {
                            $session = (new Session())->setEntite($entite)->setCreateur($creator)
                                ->setFormation($data['formation'])->setSite($data['site'])->setCapacite($data['capacite'])
                                ->setCode($sessionNumbers->nextForEntite($entite->getId()));
                            foreach ($data['jours'] as $jour) {
                                $session->addJour($jour->setEntite($entite)->setCreateur($creator));
                            }
                            $em->persist($session);
                            $em->flush();
                            return [
                                'id' => $session->getId(),
                                'label' => sprintf('%s — %s (%s)', $session->getCode(), $session->getFormationLabel(), $session->getDateDebut()->format('d/m/Y')),
                                'code' => $session->getCode(), 'title' => $session->getFormationLabel(),
                                'duration' => $session->getDureeFormationMinutes() > 0 ? rtrim(rtrim(number_format($session->getDureeFormationHeures(), 2, ',', ' '), '0'), ',') . ' heures' : '',
                                'startLabel' => $session->getDateDebut()->format('d/m/Y à H:i'), 'endLabel' => $session->getDateFin()->format('d/m/Y à H:i'),
                                'site' => $session->getSite()->getNom(), 'capacity' => $session->getCapacite(), 'remaining' => $session->getCapacite(),
                            ];
                        }
                        if ($kind === 'site') {
                            $site = (new Site())->setEntite($entite)->setCreateur($creator)->setNom($data['nom'])
                                ->setSlug(mb_substr((string) $slugger->slug($data['nom'])->lower(), 0, 110) . '-' . bin2hex(random_bytes(8)))
                                ->setAdresse($data['adresse'])->setCodePostal($data['codePostal'])->setVille($data['ville'])->setPays($data['pays']);
                            $em->persist($site);
                            $em->flush();
                            return ['id' => $site->getId(), 'label' => $site->getNom(), 'city' => $site->getVille()];
                        }

                        $email = mb_strtolower(trim($data['email']));
                        $client = $em->getRepository(Utilisateur::class)->createQueryBuilder('u')
                            ->andWhere('LOWER(u.email) = :email')->setParameter('email', $email)->getQuery()->getOneOrNullResult();
                        $membership = $client ? $em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur' => $client, 'entite' => $entite]) : null;
                        if ($client && !$membership && $client->getEntite()?->getId() !== $entite->getId()) {
                            throw new \DomainException('Cette adresse e-mail ne peut pas être utilisée pour créer un stagiaire depuis cet écran.');
                        }
                        if ($membership && !$membership->isActive()) {
                            throw new \DomainException('Ce compte doit être réactivé depuis la gestion des clients avant de pouvoir être sélectionné.');
                        }
                        $already = $client !== null;
                        if (!$client) {
                            $client = (new Utilisateur())->setEmail($email)->setNom($data['nom'])->setPrenom($data['prenom'])
                                ->setCivilite($data['civilite'])->setTelephone($data['telephone'])->setEntite($entite)->setCreateur($creator)
                                ->setEntreprise($devis->getEntrepriseDestinataire())->setRoles(['ROLE_USER']);
                            $client->setPassword($passwordHasher->hashPassword($client, bin2hex(random_bytes(32))));
                            $em->persist($client);
                        }
                        if (!$membership || !$membership->hasRole(UtilisateurEntite::TENANT_STAGIAIRE)) {
                            $billingGuard->assertCanAddApprenantAndConsume($entite);
                            if (!$membership) {
                                $membership = (new UtilisateurEntite())->setEntite($entite)->setUtilisateur($client)->setCreateur($creator);
                                $client->addUtilisateurEntite($membership);
                            }
                            $membership->addRole(UtilisateurEntite::TENANT_STAGIAIRE);
                            $em->persist($membership);
                        }
                        $em->flush();
                        return [
                            'id' => $client->getId(), 'label' => trim($client->getPrenom() . ' ' . $client->getNom()) . ' — ' . $client->getEmail(),
                            'firstName' => $client->getPrenom(), 'lastName' => $client->getNom(), 'email' => $client->getEmail(),
                            'company' => $client->getEntreprise()?->getRaisonSociale(), 'already' => $already,
                        ];
                    });
                    $result += ['success' => true, 'kind' => $kind];
                    $operations[$data['operation']]['result'] = $result;
                    $request->getSession()->set('devis_convention_quick_operations', $operations);
                    return $this->json($result, 201);
                } catch (BillingQuotaExceededException | \DomainException $e) {
                    $form->addError(new FormError($e->getMessage()));
                } catch (UniqueConstraintViolationException) {
                    return $this->json(['message' => 'Un élément avec ces informations vient d’être créé. Fermez cette fenêtre et recherchez-le dans la liste.'], 409);
                }
            }
        }

        return $this->json([
            'html' => $this->renderView('administrateur/devis/_quick_create_form.html.twig', ['form' => $form->createView(), 'kind' => $kind, 'devis' => $devis, 'entite' => $entite]),
            'message' => $form->isSubmitted() ? 'Vérifiez les informations du formulaire.' : null,
        ], $request->isMethod('POST') ? 422 : 200);
    }
}
