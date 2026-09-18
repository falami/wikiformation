<?php

namespace App\Controller\Administrateur;

use App\Entity\{Devis, Entite, Session, SessionJour, Utilisateur};
use App\Enum\DevisStatus;
use App\Form\Administrateur\DevisConventionType;
use App\Security\Permission\TenantPermission;
use App\Service\Convention\DevisConventionCreator;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(TenantPermission::DEVIS_MANAGE, subject: 'entite')]
#[IsGranted(TenantPermission::CONVENTION_MANAGE, subject: 'entite')]
final class DevisConventionController extends AbstractController
{
    #[Route('/administrateur/{entite}/devis/{id}/convention', name: 'app_administrateur_devis_convention', requirements: ['entite' => '\d+', 'id' => '\d+'], methods: ['GET', 'POST'])]
    public function create(
        #[MapEntity(id: 'entite')] Entite $entite,
        #[MapEntity(id: 'id')] Devis $devis,
        Request $request,
        DevisConventionCreator $creator,
    ): Response {
        if ($devis->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createNotFoundException();
        }
        if ($devis->getStatus() === DevisStatus::CANCELED
            || ($devis->getEntrepriseDestinataire() === null) === ($devis->getDestinataire() === null)
            || $devis->getProspect()) {
            $this->addFlash('warning', 'La création d’une convention nécessite un devis non annulé adressé à une entreprise ou à un stagiaire.');
            return $this->redirectToRoute('app_administrateur_devis_show', ['entite' => $entite->getId(), 'id' => $devis->getId()]);
        }
        $this->denyAccessUnlessGranted(TenantPermission::INSCRIPTION_MANAGE, $entite);
        $createSession = $request->query->get('mode') === 'new';
        if ($createSession) {
            $this->denyAccessUnlessGranted(TenantPermission::SESSION_MANAGE, $entite);
        }

        $initial = ['conditionsFinancieres' => null, 'stagiaires' => [], 'formation' => $devis->getFormation(), 'capacite' => 8, 'jours' => [new SessionJour()]];
        foreach ($devis->getInscriptions() as $inscription) {
            if ($inscription->getEntite()?->getId() === $entite->getId()) {
                $initial['stagiaires'][] = $inscription->getStagiaire();
                $initial['session'] ??= $inscription->getSession();
            }
        }
        // Une clé par formulaire empêche un double clic / renvoi POST de recréer tout le dossier.
        $operations = $request->getSession()->get('devis_convention_operations', []);
        if ($request->isMethod('GET')) {
            $initial['operation'] = bin2hex(random_bytes(24));
            $operations[$initial['operation']] = ['devis' => $devis->getId(), 'convention' => null];
            $request->getSession()->set('devis_convention_operations', array_slice($operations, -30, null, true));
        }
        $form = $this->createForm(DevisConventionType::class, $initial, [
            'devis' => $devis,
            'create_session' => $createSession,
        ])->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $operation = $operations[$data['operation']] ?? null;
            if (!$operation || $operation['devis'] !== $devis->getId()) {
                $form->addError(new FormError('Ce formulaire a expiré. Ouvrez à nouveau la création de convention depuis le devis.'));
            } elseif ($operation['convention']) {
                return $this->redirectToRoute('app_administrateur_convention_show', ['entite' => $entite->getId(), 'id' => $operation['convention']]);
            } else {
                /** @var Utilisateur $user */
                $user = $this->getUser();
                $session = $data['session'] ?? null;
                if ($createSession) {
                    $session = (new Session())->setEntite($entite)->setCreateur($user)
                        ->setFormation($data['formation'])->setSite($data['site'])->setCapacite($data['capacite']);
                    foreach ($data['jours'] as $jour) {
                        $session->addJour($jour);
                    }
                }
                $stagiaires = $devis->getDestinataire() ? [$devis->getDestinataire()] : $data['stagiaires'];
                if ($stagiaires instanceof \Traversable) {
                    $stagiaires = iterator_to_array($stagiaires);
                }
                try {
                    $convention = $creator->create($devis, $session, $stagiaires, $user, $data['conditionsFinancieres']);
                    $operations[$data['operation']]['convention'] = $convention->getId();
                    $request->getSession()->set('devis_convention_operations', $operations);
                    $this->addFlash('success', 'Convention créée depuis le devis. Les inscriptions sont rattachées à la session.');
                    return $this->redirectToRoute('app_administrateur_convention_show', ['entite' => $entite->getId(), 'id' => $convention->getId()]);
                } catch (\DomainException $e) {
                    // Doctrine ferme l’EntityManager si la transaction est annulée : repartir sur un GET propre.
                    $this->addFlash('warning', $e->getMessage());
                    return $this->redirectToRoute('app_administrateur_devis_convention', [
                        'entite' => $entite->getId(), 'id' => $devis->getId(), 'mode' => $createSession ? 'new' : 'existing',
                    ]);
                }
            }
        }

        return $this->render('administrateur/devis/convention.html.twig', [
            'entite' => $entite, 'devis' => $devis, 'form' => $form, 'createSession' => $createSession,
        ]);
    }
}
