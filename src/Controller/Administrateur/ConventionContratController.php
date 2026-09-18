<?php

declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\{ConventionContrat, Inscription, Entite, Utilisateur, Session};
use App\Form\Administrateur\ConventionContratType;
use App\Service\Pdf\PdfManager;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{
    Request,
    Response,
    JsonResponse,
    RedirectResponse,
    BinaryFileResponse
};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Entity\Entreprise;
use App\Service\Sequence\ConventionContratNumberGenerator;
use App\Security\Permission\TenantPermission;



#[Route('/administrateur/{entite}/conventions', name: 'app_administrateur_convention_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::CONVENTION_MANAGE, subject: 'entite')]
final class ConventionContratController extends AbstractController
{
    public function __construct(
        private readonly UtilisateurEntiteManager $utilisateurEntiteManager,
        private readonly PdfManager $pdf,
        private readonly string $projectDir,
        private readonly ConventionContratNumberGenerator $ccNumber,
    ) {}

    #[Route('/liste', name: 'index', methods: ['GET'])]
    public function index(Entite $entite): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        return $this->render('administrateur/convention/index.html.twig', [
            'entite' => $entite,

        ]);
    }

    #[Route('/ajax', name: 'ajax', methods: ['POST'])]
    public function ajax(Entite $entite, Request $request, EM $em): JsonResponse
    {
        $start   = max(0, $request->request->getInt('start', 0));
        $length  = min(100, max(1, $request->request->getInt('length', 10)));
        $searchV = (string) (($request->request->all('search')['value'] ?? ''));

        $order   = $request->request->all('order');

        // mapping colonnes DataTables -> champs DQL
        $map = [
            0 => 'c.numero',
            1 => 'e.raisonSociale',
            2 => 'f.titre',
            3 => 'se.code',
        ];

        $repo = $em->getRepository(ConventionContrat::class);

        $qb = $repo->createQueryBuilder('c')
            ->leftJoin('c.session', 'se')->addSelect('se')
            ->leftJoin('se.formation', 'f')->addSelect('f')
            ->leftJoin('c.entreprise', 'e')->addSelect('e')
            ->leftJoin('c.stagiaire', 'u')->addSelect('u')
            ->andWhere('c.entite = :entite')
            ->setParameter('entite', $entite);

        $recordsTotal = (int)(clone $qb)
            ->select('COUNT(DISTINCT c.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()->getSingleScalarResult();

        if ($searchV !== '') {
            $search = $qb->expr()->orX(
                'c.numero LIKE :s', 'e.raisonSociale LIKE :s',
                'u.nom LIKE :s', 'u.prenom LIKE :s', 'f.titre LIKE :s', 'se.code LIKE :s'
            );
            if (ctype_digit($searchV)) {
                $search->add('c.id = :idExact');
                $qb->setParameter('idExact', (int) $searchV);
            }
            $qb->andWhere($search)->setParameter('s', '%' . $searchV . '%');
        }



        $recordsFiltered = (int)(clone $qb)
            ->select('COUNT(DISTINCT c.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()->getSingleScalarResult();

        $orderColIdx = isset($order[0]['column']) ? (int) $order[0]['column'] : 0;
        $orderDir    = (isset($order[0]['dir']) && strtolower((string)$order[0]['dir']) === 'asc') ? 'ASC' : 'DESC';
        $orderBy     = $map[$orderColIdx] ?? 'c.id';

        /** @var ConventionContrat[] $rows */
        $rows = $qb->orderBy($orderBy, $orderDir)
            ->setFirstResult($start)
            ->setMaxResults($length)
            ->getQuery()->getResult();

        $data = array_map(function (ConventionContrat $c) use ($entite) {
            $sess = $c->getSession();
            $form = $sess?->getFormation();

            return [
                'id'        => $c->getId(),
                'numero'    => $c->getNumero() ?: '—',
                'entreprise' => $c->getDestinataireLabel(),
                'formation' => $form?->getTitre() ?? '—',
                'session'   => $sess?->getCode() ?? '—',
                'actions'   => $this->renderView('administrateur/convention/_actions.html.twig', [
                    'c' => $c,
                    'entite' => $entite,
                ]),
            ];
        }, $rows);

        return new JsonResponse([
            'draw'            => $request->request->getInt('draw', 0),
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $data,
        ]);
    }

    #[Route('/from-inscription/{id}', name: 'from_inscription', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function fromInscription(Entite $entite, Inscription $inscription, Request $request, EM $em): Response
    {
        // sécurité entité/session si besoin
        if ($inscription->getSession()?->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createNotFoundException();
        }
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $session    = $inscription->getSession();
        $entreprise = $inscription->getEntreprise();
        $stagiaire  = $inscription->getStagiaire();

        if (!$session) {
            throw new \LogicException('Inscription sans session.');
        }

        if (!$stagiaire || ($entreprise && $entreprise->getEntite()?->getId() !== $entite->getId())) {
            throw $this->createNotFoundException('Destinataire introuvable.');
        }

        $tokenId = 'conv_inscription_' . $inscription->getId();
        if ($request->isMethod('GET')) {
            return $this->render('administrateur/convention/confirm_creation.html.twig', [
                'entite' => $entite, 'session' => $session,
                'destinataire' => $entreprise?->getRaisonSociale() ?? trim($stagiaire->getPrenom() . ' ' . $stagiaire->getNom()),
                'inscriptions' => [$inscription], 'tokenId' => $tokenId,
            ]);
        }
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }

        return $this->createFromInscriptions($entite, $session, $entreprise, [$inscription], $em);
    }


    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(Entite $entite, ConventionContrat $c): Response
    {
        $this->assertConventionTenant($entite, $c);
        /** @var Utilisateur $user */
        $user = $this->getUser();

        return $this->render('administrateur/convention/show.html.twig', [
            'c' => $c,
            'entite' => $entite,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Entite $entite, ConventionContrat $c, Request $req, EM $em): Response
    {
        $this->assertConventionTenant($entite, $c);
        if ($c->isSigned()) {
            $this->addFlash('warning', 'Une convention signée ne peut plus être modifiée.');
            return $this->redirectToRoute('app_administrateur_convention_show', ['entite' => $entite->getId(), 'id' => $c->getId()]);
        }
        /** @var Utilisateur $user */
        $user = $this->getUser();

        // 🔒 logique de lock
        $lockSession   = true;
        $lockEntreprise = ($c->getEntreprise() !== null) !== ($c->getStagiaire() !== null);
        $lockStagiaire  = $lockEntreprise;


        $form = $this->createForm(ConventionContratType::class, $c, [
            'entite'         => $entite,
            'lock_session'   => $lockSession,
            'lock_entreprise' => $lockEntreprise,
            'lock_stagiaire' => $lockStagiaire,
        ])->handleRequest($req);

        if ($form->isSubmitted() && $form->isValid()) {
            foreach ($c->getInscriptions() as $inscription) {
                $c->getDevis()?->addInscription($inscription);
            }
            if (!$c->hasNumero()) {
                $c->setNumero($this->ccNumber->nextForEntite($entite->getId()));
            }
            // Toute modification rend le précédent document obsolète.
            $c->setPdfPath(null);
            $em->flush();
            $this->addFlash('success', 'Convention mise à jour.');

            return $this->redirectToRoute('app_administrateur_convention_show', [
                'entite' => $entite->getId(),
                'id'     => $c->getId(),
            ]);
        }

        return $this->render('administrateur/convention/form.html.twig', [
            'form'  => $form,
            'title' => 'Éditer convention',
            'c'     => $c,
            'entite' => $entite,
        ]);
    }


    #[Route('/{id}/generer-pdf', name: 'generate_pdf', methods: ['POST'])]
    public function generatePdf(Entite $entite, ConventionContrat $c, Request $req, EM $em): RedirectResponse
    {
        $this->assertConventionTenant($entite, $c);
        if ($c->isSigned() && $c->getPdfPath()) {
            throw $this->createAccessDeniedException('Le PDF d’une convention signée ne peut pas être remplacé.');
        }
        if (!$this->isCsrfTokenValid('genpdf' . $c->getId(), (string)$req->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }

        $vars = $this->buildTemplateVars($entite, $c);
        $absolutePath = $this->pdf->conventionContrat($vars, sprintf('convention-%d.pdf', $c->getId()));
        $c->setPdfPath($this->toRelativeWebPath($absolutePath));
        $em->flush();

        $this->addFlash('success', 'PDF généré.');
        return $this->redirectToRoute('app_administrateur_convention_show', [
            'entite' => $entite->getId(),
            'id'     => $c->getId(),
        ]);
    }



    #[Route('/{id}/pdf', name: 'pdf', methods: ['GET'])]
    public function pdf(Entite $entite, ConventionContrat $c): Response
    {
        $this->assertConventionTenant($entite, $c);
        $rel = $c->getPdfPath();
        if (!$rel) {
            $this->addFlash('warning', 'Aucun PDF généré.');
            return $this->redirectToRoute('app_administrateur_convention_show', [
                'entite' => $entite->getId(),
                'id'     => $c->getId(),
            ]);
        }

        $abs = $this->projectDir . '/public/' . ltrim($rel, '/');
        if (!is_file($abs)) {
            $this->addFlash('warning', 'PDF introuvable sur le serveur.');
            return $this->redirectToRoute('app_administrateur_convention_show', [
                'entite' => $entite->getId(),
                'id'     => $c->getId(),
            ]);
        }

        return new BinaryFileResponse($abs);
    }

    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'])]
    public function delete(Entite $entite, ConventionContrat $c, Request $req, EM $em): RedirectResponse
    {
        $this->assertConventionTenant($entite, $c);
        if ($c->isSigned()) {
            throw $this->createAccessDeniedException('Une convention signée ne peut pas être supprimée.');
        }
        if ($this->isCsrfTokenValid('del' . $c->getId(), (string)$req->request->get('_token'))) {
            $em->remove($c);
            $em->flush();
            $this->addFlash('success', 'Convention supprimée.');
        }

        return $this->redirectToRoute('app_administrateur_convention_index', [
            'entite' => $entite->getId(),
        ]);
    }

    private function buildTemplateVars(Entite $entite, ConventionContrat $c): array
    {
        $session   = $c->getSession();
        $formation = $session?->getFormation();

        return [
            'entite'     => $entite,
            'convention' => $c,
            'session'    => $session,
            'formation'  => $formation,
            'entreprise' => $c->getEntreprise(),
            'stagiaire'  => $c->getStagiaire(),
            'destinataireLabel' => $c->getDestinataireLabel(),
        ];
    }

    private function toRelativeWebPath(string $absolute): string
    {
        $public = rtrim($this->projectDir, '/') . '/public/';
        return str_starts_with($absolute, $public)
            ? substr($absolute, strlen($public))
            : $absolute;
    }



    #[Route('/from-entreprise/{entreprise}/{session}', name: 'from_entreprise_session', methods: ['GET', 'POST'])]
    public function fromEntrepriseSession(
        Entite $entite,
        Entreprise $entreprise,
        Session $session,
        Request $request,
        EM $em
    ): Response {
        if ($entreprise->getEntite()?->getId() !== $entite->getId()
            || $session->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createNotFoundException();
        }

        $inscriptions = $em->getRepository(Inscription::class)->findBy([
            'session' => $session, 'entreprise' => $entreprise,
        ]);
        $tokenId = 'conv_entreprise_' . $entreprise->getId() . '_' . $session->getId();
        if ($request->isMethod('GET')) {
            return $this->render('administrateur/convention/confirm_creation.html.twig', [
                'entite' => $entite, 'session' => $session,
                'destinataire' => $entreprise->getRaisonSociale(),
                'inscriptions' => $inscriptions, 'tokenId' => $tokenId,
            ]);
        }
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }

        return $this->createFromInscriptions($entite, $session, $entreprise, $inscriptions, $em);
    }

    #[Route('/bulk-from-inscriptions', name: 'bulk_from_inscriptions', methods: ['POST'])]
    public function bulkFromInscriptions(Entite $entite, Request $req, EM $em): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('bulk_conv', (string) $req->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }

        $inscriptions = $this->loadSelectedInscriptions($entite, $req->request->all('ids'), $em);
        if (!$inscriptions) {
            $this->addFlash('warning', 'Sélectionnez au moins une inscription.');
            return $this->redirectToRoute('app_administrateur_convention_index', ['entite' => $entite->getId()]);
        }

        return $this->createFromInscriptions(
            $entite, $inscriptions[0]->getSession(), $inscriptions[0]->getEntreprise(), $inscriptions, $em
        );
    }

    #[Route('/from-session-entreprise', name: 'from_session_entreprise', methods: ['POST'])]
    public function fromSessionEntreprise(Entite $entite, Request $request, EM $em): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('conv_bulk_' . $entite->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }

        $session = $em->getRepository(Session::class)->find($request->request->getInt('sessionId'));
        $entreprise = $em->getRepository(Entreprise::class)->find($request->request->getInt('entrepriseId'));
        if (!$session || !$entreprise || $session->getEntite()?->getId() !== $entite->getId()
            || $entreprise->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createNotFoundException('Session ou entreprise introuvable.');
        }

        $inscriptions = $this->loadSelectedInscriptions($entite, $request->request->all('inscriptionIds'), $em);
        return $this->createFromInscriptions($entite, $session, $entreprise, $inscriptions, $em);
    }

    /** @return list<Inscription> */
    private function loadSelectedInscriptions(Entite $entite, array $ids, EM $em): array
    {
        foreach ($ids as $id) {
            if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id <= 0) {
                throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Inscription invalide.');
            }
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $inscriptions = $em->getRepository(Inscription::class)->findBy(['id' => $ids]);
        if (count($inscriptions) !== count($ids)) {
            throw $this->createNotFoundException('Une inscription sélectionnée est introuvable.');
        }
        foreach ($inscriptions as $inscription) {
            if ($inscription->getSession()?->getEntite()?->getId() !== $entite->getId()
                || $inscription->getEntite()?->getId() !== $entite->getId()) {
                throw $this->createNotFoundException();
            }
        }
        return $inscriptions;
    }

    /** @param list<Inscription> $inscriptions */
    private function createFromInscriptions(
        Entite $entite, Session $session, ?Entreprise $entreprise, array $inscriptions, EM $em
    ): RedirectResponse {
        if (!$inscriptions) {
            $this->addFlash('warning', 'Sélectionnez au moins une inscription.');
            return $this->redirectToRoute('app_administrateur_convention_index', ['entite' => $entite->getId()]);
        }
        $stagiaire = $entreprise ? null : $inscriptions[0]->getStagiaire();
        foreach ($inscriptions as $inscription) {
            if ($inscription->getSession()?->getId() !== $session->getId()
                || $inscription->getEntite()?->getId() !== $entite->getId()
                || ($entreprise && $inscription->getEntreprise()?->getId() !== $entreprise->getId())
                || (!$entreprise && (!$stagiaire || $inscription->getStagiaire()?->getId() !== $stagiaire->getId()))) {
                throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException(
                    'Les inscriptions doivent appartenir à la même session et au même destinataire.'
                );
            }
        }

        // Chaque création représente un dossier distinct, même pour une entreprise/session déjà utilisée.
        return $em->wrapInTransaction(function () use ($entite, $session, $entreprise, $stagiaire, $inscriptions, $em) {
            $c = (new ConventionContrat())
                ->setEntite($entite)
                ->setCreateur($this->getUser())
                ->setSession($session)
                ->setEntreprise($entreprise)
                ->setStagiaire($stagiaire)
                ->setNumero($this->ccNumber->nextForEntite($entite->getId()));
            foreach ($inscriptions as $inscription) {
                $c->addInscription($inscription);
            }
            $em->persist($c);
            $em->flush();

            return $this->redirectToRoute('app_administrateur_convention_edit', [
                'entite' => $entite->getId(), 'id' => $c->getId(),
            ]);
        });
    }

    private function assertConventionTenant(Entite $entite, ConventionContrat $c): void
    {
        if ($c->getEntite()?->getId() !== $entite->getId()
            || $c->getSession()?->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createNotFoundException('Convention introuvable.');
        }
    }
}
