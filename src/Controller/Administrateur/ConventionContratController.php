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
        $start   = $request->request->getInt('start', 0);
        $length  = $request->request->getInt('length', 10);
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
            ->andWhere('c.entite = :entite')
            ->setParameter('entite', $entite);

        $recordsTotal = (int)(clone $qb)
            ->select('COUNT(DISTINCT c.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()->getSingleScalarResult();

        if ($searchV !== '') {
            $qb->andWhere('
        c.numero LIKE :s
        OR e.raisonSociale LIKE :s
        OR f.titre LIKE :s
        OR se.code LIKE :s
    ')
                ->setParameter('s', '%' . $searchV . '%');

            // bonus: si la recherche est numérique, on tente aussi un match sur l'id
            if (ctype_digit($searchV)) {
                $qb->orWhere('c.id = :idExact')
                    ->setParameter('idExact', (int) $searchV);
            }
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
                'entreprise' => $c->getEntreprise()?->getRaisonSociale() ?? '—',
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

    #[Route('/from-inscription/{id}', name: 'from_inscription', methods: ['GET'])]
    public function fromInscription(Entite $entite, Inscription $inscription, EM $em): RedirectResponse
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

        // ✅ 1) trouver la convention selon le cas
        $criteria = ['entite' => $entite, 'session' => $session];

        if ($entreprise) {
            $criteria['entreprise'] = $entreprise;
        } else {
            if (!$stagiaire) {
                throw new \LogicException("Inscription sans stagiaire.");
            }
            $criteria['stagiaire'] = $stagiaire;
        }



        /** @var ConventionContrat|null $c */
        $c = $em->getRepository(ConventionContrat::class)->findOneBy($criteria);

        // ✅ 2) créer si besoin
        if (!$c) {
            $c = (new ConventionContrat())
                ->setEntite($entite)
                ->setCreateur($user)
                ->setSession($session);

            if (!$c->hasNumero()) { // ✅ robuste
                $c->setNumero($this->ccNumber->nextForEntite($entite->getId()));
            }

            if ($entreprise) $c->setEntreprise($entreprise);
            else $c->setStagiaire($stagiaire);

            $em->persist($c);
        }


        // ✅ 3) attacher l'inscription à la convention (ManyToMany)
        $c->addInscription($inscription);

        $em->flush();

        return $this->redirectToRoute('app_administrateur_convention_edit', [
            'entite' => $entite->getId(),
            'id'     => $c->getId(),
        ]);
    }


    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(Entite $entite, ConventionContrat $c): Response
    {
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
        /** @var Utilisateur $user */
        $user = $this->getUser();

        // 🔒 logique de lock
        $lockSession   = true;
        $lockEntreprise = $c->getEntreprise() !== null; // lock si déjà pré-rempli
        $lockStagiaire  = $c->getStagiaire() !== null;  // lock si déjà pré-rempli

        if (!$c->hasNumero()) {
            $c->setNumero($this->ccNumber->nextForEntite($entite->getId()));
            $em->flush(); // ou laisser pour le flush du form
        }


        $form = $this->createForm(ConventionContratType::class, $c, [
            'entite'         => $entite,
            'lock_session'   => $lockSession,
            'lock_entreprise' => $lockEntreprise,
            'lock_stagiaire' => $lockStagiaire,
        ])->handleRequest($req);

        if ($form->isSubmitted() && $form->isValid()) {

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
            'preferences' => $entite->getPreferences(),
        ]);
    }



    #[Route('/{id}/pdf', name: 'pdf', methods: ['GET'])]
    public function pdf(Entite $entite, ConventionContrat $c): Response
    {
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



    #[Route('/from-entreprise/{entreprise}/{session}', name: 'from_entreprise_session', methods: ['GET'])]
    public function fromEntrepriseSession(
        Entite $entite,
        Entreprise $entreprise,
        Session $session,
        EM $em
    ): RedirectResponse {
        // 🔒 sécurités entité
        if ($entreprise->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createNotFoundException();
        }
        if ($session->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createNotFoundException();
        }

        /** @var Utilisateur $user */
        $user = $this->getUser();

        return $em->wrapInTransaction(function () use ($em, $entite, $entreprise, $session, $user) {

            // 1) récupérer (ou créer) la convention entreprise unique (entite+session+entreprise)
            $c = $em->getRepository(ConventionContrat::class)->findOneBy([
                'entite'     => $entite,
                'session'    => $session,
                'entreprise' => $entreprise,
            ]);

            if (!$c) {
                $c = (new ConventionContrat())
                    ->setEntite($entite)
                    ->setCreateur($user)
                    ->setSession($session)
                    ->setEntreprise($entreprise)
                    ->setStagiaire(null);

                if (!$c->hasNumero()) {
                    $c->setNumero($this->ccNumber->nextForEntite($entite->getId()));
                }

                $em->persist($c);
            } else {
                // robustesse : si par erreur stagiaire rempli, on nettoie
                if ($c->getStagiaire()) {
                    $c->setStagiaire(null);
                }
            }

            // 2) attacher toutes les inscriptions de cette entreprise pour cette session
            //    (tu peux filtrer sur statut "validée" si tu as un champ status)
            $inscriptions = $em->getRepository(Inscription::class)->createQueryBuilder('i')
                ->andWhere('i.session = :s')
                ->andWhere('i.entreprise = :e')
                ->setParameter('s', $session)
                ->setParameter('e', $entreprise)
                ->getQuery()
                ->getResult();

            foreach ($inscriptions as $insc) {
                $c->addInscription($insc);
            }

            $em->flush();

            return $this->redirectToRoute('app_administrateur_convention_edit', [
                'entite' => $entite->getId(),
                'id'     => $c->getId(),
            ]);
        });
    }



    #[Route('/bulk-from-inscriptions', name: 'bulk_from_inscriptions', methods: ['POST'])]
    public function bulkFromInscriptions(Entite $entite, Request $req, EM $em): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('bulk_conv', (string)$req->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }

        $ids = $req->request->all('ids'); // tableau d'IDs inscription
        $ids = array_values(array_filter(array_map('intval', (array)$ids)));

        if (!$ids) {
            $this->addFlash('warning', 'Aucune inscription sélectionnée.');
            return $this->redirectToRoute('app_administrateur_convention_index', ['entite' => $entite->getId()]);
        }

        /** @var Utilisateur $user */
        $user = $this->getUser();

        return $em->wrapInTransaction(function () use ($em, $entite, $ids, $user) {

            /** @var Inscription[] $inscriptions */
            $inscriptions = $em->getRepository(Inscription::class)->createQueryBuilder('i')
                ->leftJoin('i.session', 's')->addSelect('s')
                ->leftJoin('i.entreprise', 'e')->addSelect('e')
                ->leftJoin('i.stagiaire', 'u')->addSelect('u')
                ->andWhere('i.id IN (:ids)')->setParameter('ids', $ids)
                ->getQuery()->getResult();

            // sécurité entité + cohérence
            foreach ($inscriptions as $i) {
                if ($i->getSession()?->getEntite()?->getId() !== $entite->getId()) {
                    throw $this->createNotFoundException();
                }
            }

            // ici on impose : toutes même session + même entreprise
            $session = $inscriptions[0]->getSession();
            $entreprise = $inscriptions[0]->getEntreprise();

            if (!$session || !$entreprise) {
                throw new \LogicException('Bulk: il faut une session et une entreprise.');
            }

            foreach ($inscriptions as $i) {
                if ($i->getSession()?->getId() !== $session->getId() || $i->getEntreprise()?->getId() !== $entreprise->getId()) {
                    throw new \LogicException('Bulk: sélection invalide (mélange sessions/entreprises).');
                }
            }

            $c = $em->getRepository(ConventionContrat::class)->findOneBy([
                'entite' => $entite,
                'session' => $session,
                'entreprise' => $entreprise,
            ]);

            if (!$c) {
                $c = (new ConventionContrat())
                    ->setEntite($entite)
                    ->setCreateur($user)
                    ->setSession($session)
                    ->setEntreprise($entreprise)
                    ->setStagiaire(null);

                if (!$c->hasNumero()) {
                    $c->setNumero($this->ccNumber->nextForEntite($entite->getId()));
                }

                $em->persist($c);
            }


            foreach ($inscriptions as $i) {
                $c->addInscription($i);
            }

            $em->flush();

            return $this->redirectToRoute('app_administrateur_convention_edit', [
                'entite' => $entite->getId(),
                'id' => $c->getId(),
            ]);
        });
    }



    #[Route('/from-session-entreprise', name: 'from_session_entreprise', methods: ['POST'])]
    public function fromSessionEntreprise(
        Entite $entite,
        Request $request,
        EM $em
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('conv_bulk_' . $entite->getId(), (string)$request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF invalide');
        }

        $sessionId    = (int)$request->request->get('sessionId');
        $entrepriseId = (int)$request->request->get('entrepriseId');
        $ids          = (array)$request->request->all('inscriptionIds'); // array de strings

        /** @var Session|null $session */
        $session = $em->getRepository(Session::class)->find($sessionId);
        if (!$session || $session->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createNotFoundException('Session introuvable');
        }

        /** @var Entreprise|null $entreprise */
        $entreprise = $em->getRepository(Entreprise::class)->find($entrepriseId);
        if (!$entreprise || $entreprise->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createNotFoundException('Entreprise introuvable');
        }

        // 1) récupérer (ou créer) la convention entreprise de cette session
        $ccRepo = $em->getRepository(ConventionContrat::class);
        $cc = $ccRepo->findOneBy(['entite' => $entite, 'session' => $session, 'entreprise' => $entreprise]);

        if (!$cc) {
            /** @var Utilisateur $user */
            $user = $this->getUser();

            $cc = (new ConventionContrat())
                ->setEntite($entite)
                ->setCreateur($user)
                ->setSession($session)
                ->setEntreprise($entreprise)
                ->setStagiaire(null);

            if (!$cc->hasNumero()) {
                $cc->setNumero($this->ccNumber->nextForEntite($entite->getId()));
            }

            $em->persist($cc);
        }


        // 2) associer les inscriptions sélectionnées, MAIS uniquement celles qui matchent session+entreprise
        $insRepo = $em->getRepository(Inscription::class);

        $inscriptions = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id <= 0) continue;
            $ins = $insRepo->find($id);
            if (!$ins) continue;

            if ($ins->getSession()?->getId() !== $session->getId()) continue;
            if (($ins->getEntreprise()?->getId() ?? 0) !== $entreprise->getId()) continue;

            $inscriptions[] = $ins;
        }

        // option : si aucune, on n’écrase pas l’existant, on redirect quand même sur la convention
        if (!empty($inscriptions)) {
            // stratégie simple : on replace la liste
            // (si tu veux “ajouter sans retirer”, dis-le et je te donne la version additive)
            foreach ($cc->getInscriptions() as $existing) {
                $cc->removeInscription($existing);
            }
            foreach ($inscriptions as $ins) {
                $cc->addInscription($ins);
            }
        }

        $em->flush();

        return $this->redirectToRoute('app_administrateur_convention_show', [
            'entite' => $entite->getId(),
            'id' => $cc->getId(),
        ]);
    }
}
