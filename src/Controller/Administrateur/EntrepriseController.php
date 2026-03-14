<?php

namespace App\Controller\Administrateur;

use App\Entity\{Entreprise, Entite, Utilisateur};
use App\Form\Administrateur\EntrepriseType;
use App\Security\Permission\TenantPermission;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\{JsonResponse, RedirectResponse, Request, Response};
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/administrateur/{entite}/entreprise')]
#[IsGranted(TenantPermission::ENTREPRISE_MANAGE, subject: 'entite')]
final class EntrepriseController extends AbstractController
{
    public function __construct(
        private readonly UtilisateurEntiteManager $utilisateurEntiteManager,
        private readonly Packages $assets,
        private readonly SluggerInterface $slugger,
    ) {
    }

    #[Route('', name: 'app_administrateur_entreprise_index', methods: ['GET'])]
    public function index(Entite $entite): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        return $this->render('administrateur/entreprise/index.html.twig', [
            'entite' => $entite,
        ]);
    }

    #[Route('/ajax', name: 'app_administrateur_entreprise_ajax', methods: ['POST'])]
  public function ajax(Entite $entite, Request $request, EntityManagerInterface $em): JsonResponse
  {
      $draw   = $request->request->getInt('draw', 0);
      $start  = max(0, $request->request->getInt('start', 0));
      $length = $request->request->getInt('length', 10);

      if ($length <= 0 || $length > 500) {
          $length = 10;
      }

      $search  = (array) $request->request->all('search');
      $searchV = trim((string) ($search['value'] ?? ''));

      $lockedFilter = (string) $request->request->get('lockedFilter', 'all');
      $searchName   = trim((string) $request->request->get('searchName', ''));

      $order = (array) $request->request->all('order');
      $orderDir = strtolower((string) ($order[0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
      $orderColIdx = (int) ($order[0]['column'] ?? 0);

      // # = colonne 0
      // Logo = colonne 1
      $orderMap = [
          0 => 'e.id',
          1 => 'e.logo',
          2 => 'e.raisonSociale',
          3 => 'e.siret',
          4 => 'e.emailFacturation',
      ];
      $orderBy = $orderMap[$orderColIdx] ?? 'e.id';

      $applyFilters = function (\Doctrine\ORM\QueryBuilder $qb, string $eAlias) use ($searchV, $searchName, $lockedFilter): void {
          if ($searchV !== '') {
              $qb->andWhere("(
                  $eAlias.raisonSociale LIKE :dt_q
                  OR $eAlias.siret LIKE :dt_q
                  OR $eAlias.emailFacturation LIKE :dt_q
                  OR $eAlias.email LIKE :dt_q
              )")
              ->setParameter('dt_q', '%' . $searchV . '%');
          }

          if ($searchName !== '') {
              $qb->andWhere("(
                  $eAlias.raisonSociale LIKE :fb_q
                  OR $eAlias.siret LIKE :fb_q
                  OR $eAlias.emailFacturation LIKE :fb_q
                  OR $eAlias.email LIKE :fb_q
              )")
              ->setParameter('fb_q', '%' . $searchName . '%');
          }

          if ($lockedFilter === '1') {
              $qb->andWhere(
                  "(SIZE($eAlias.inscriptions) > 0
                  OR SIZE($eAlias.conventionContrats) > 0
                  OR SIZE($eAlias.factures) > 0
                  OR SIZE($eAlias.utilisateurs) > 0)"
              );
          } elseif ($lockedFilter === '0') {
              $qb->andWhere(
                  "(SIZE($eAlias.inscriptions) = 0
                  AND SIZE($eAlias.conventionContrats) = 0
                  AND SIZE($eAlias.factures) = 0
                  AND SIZE($eAlias.utilisateurs) = 0)"
              );
          }
      };

      $qb = $em->getRepository(Entreprise::class)->createQueryBuilder('e')
          ->andWhere('e.entite = :entite')
          ->setParameter('entite', $entite);

      $applyFilters($qb, 'e');

      $qbTotal = $em->getRepository(Entreprise::class)->createQueryBuilder('e_t')
          ->select('COUNT(e_t.id)')
          ->andWhere('e_t.entite = :entite')
          ->setParameter('entite', $entite);

      $recordsTotal = (int) $qbTotal->getQuery()->getSingleScalarResult();

      $qbFiltered = $em->getRepository(Entreprise::class)->createQueryBuilder('e_f')
          ->select('COUNT(e_f.id)')
          ->andWhere('e_f.entite = :entite')
          ->setParameter('entite', $entite);

      $applyFilters($qbFiltered, 'e_f');

      $recordsFiltered = (int) $qbFiltered->getQuery()->getSingleScalarResult();

      /** @var Entreprise[] $rows */
      $rows = $qb
          ->orderBy($orderBy, $orderDir)
          ->addOrderBy('e.id', 'DESC')
          ->setFirstResult($start)
          ->setMaxResults($length)
          ->getQuery()
          ->getResult();

      $data = [];
      foreach ($rows as $e) {
          if (!$e instanceof Entreprise) {
              continue;
          }

          $locked = $this->isLockedEntreprise($e);

          if ($e->getLogo()) {
              $logoUrl = $this->getEntrepriseLogoUrl($e);

              $logoHtml = sprintf(
                  '<div class="d-flex align-items-center justify-content-center">
                      <img src="%s" alt="%s" class="entreprise-logo-thumb">
                  </div>',
                  htmlspecialchars($logoUrl, ENT_QUOTES),
                  htmlspecialchars((string) ($e->getRaisonSociale() ?: 'Logo entreprise'), ENT_QUOTES)
              );
          } else {
              $logoHtml = '
                  <div class="d-flex align-items-center justify-content-center">
                      <span class="entreprise-logo-fallback" title="Aucun logo">
                          <i class="bi bi-building"></i>
                      </span>
                  </div>
              ';
          }

          $data[] = [
              'id'               => $e->getId(),
              'logo'             => $logoHtml,
              'raisonSociale'    => $e->getRaisonSociale() ?: '—',
              'siret'            => $e->getSiret() ?: '—',
              'emailFacturation' => $e->getEmailFacturation() ?: '—',
              'inscriptions'     => $e->getInscriptions()->count(),
              'factures'         => $e->getFactures()->count(),
              'locked'           => $locked ? 'Oui' : 'Non',
              'actions'          => $this->renderView('administrateur/entreprise/_actions.html.twig', [
                  'entite'     => $entite,
                  'entreprise' => $e,
                  'locked'     => $locked,
              ]),
          ];
      }

      return new JsonResponse([
          'draw'            => $draw,
          'recordsTotal'    => $recordsTotal,
          'recordsFiltered' => $recordsFiltered,
          'data'            => $data,
      ]);
  }

    #[Route('/ajouter', name: 'app_administrateur_entreprise_ajouter', methods: ['GET', 'POST'])]
    #[Route('/modifier/{id}', name: 'app_administrateur_entreprise_modifier', methods: ['GET', 'POST'])]
    public function addEdit(
        Entite $entite,
        Request $request,
        EntityManagerInterface $em,
        ?Entreprise $entreprise = null
    ): Response {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $isEdit = (bool) $entreprise;

        if ($entreprise && $entreprise->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createNotFoundException('Entreprise introuvable pour cette entité.');
        }

        if (!$entreprise) {
            $entreprise = new Entreprise();
            $entreprise->setCreateur($user);
            $entreprise->setEntite($entite);
        }

        $locked = $isEdit ? $this->isLockedEntreprise($entreprise) : false;
        $oldLogo = $entreprise->getLogo();

        $form = $this->createForm(EntrepriseType::class, $entreprise, [
            'entite' => $entite,
            'locked' => $locked,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile|null $logoFile */
            $logoFile = $form->get('logoFile')->getData();
            $deleteLogo = (bool) $form->get('deleteLogo')->getData();

            if ($deleteLogo && $logoFile instanceof UploadedFile) {
                $form->get('deleteLogo')->addError(new FormError('Tu ne peux pas supprimer le logo et en téléverser un nouveau en même temps.'));
            }

            if ($form->isValid()) {
                if ($deleteLogo && $oldLogo) {
                    $this->removeLogoFile($oldLogo);
                    $entreprise->setLogo(null);
                }

                if ($logoFile instanceof UploadedFile) {
                    if ($oldLogo) {
                        $this->removeLogoFile($oldLogo);
                    }

                    $newFilename = $this->uploadLogo($logoFile, (string) $entreprise->getRaisonSociale());
                    $entreprise->setLogo($newFilename);
                }

                $em->persist($entreprise);
                $em->flush();

                $this->addFlash('success', $isEdit ? 'Entreprise modifiée.' : 'Entreprise ajoutée.');

                return $this->redirectToRoute('app_administrateur_entreprise_index', [
                    'entite' => $entite->getId(),
                ]);
            }
        }

        return $this->render('administrateur/entreprise/form.html.twig', [
            'entite'               => $entite,
            'entreprise'           => $entreprise,
            'modeEdition'          => $isEdit,
            'locked'               => $locked,
            'form'                 => $form->createView(),
            'googleMapsBrowserKey' => $this->getParameter('GOOGLE_MAPS_BROWSER_KEY'),
            'currentLogoUrl'       => $this->getEntrepriseLogoUrl($entreprise),
            'hasCurrentLogo'       => (bool) $entreprise->getLogo(),
        ]);
    }

    #[Route('/supprimer/{id}', name: 'app_administrateur_entreprise_supprimer', methods: ['GET'])]
    public function delete(Entite $entite, EntityManagerInterface $em, Entreprise $entreprise): RedirectResponse
    {
        if ($entreprise->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createNotFoundException('Entreprise introuvable pour cette entité.');
        }

        if ($this->isLockedEntreprise($entreprise)) {
            $this->addFlash('warning', 'Entreprise utilisée (inscriptions / factures / conventions). Suppression refusée.');

            return $this->redirectToRoute('app_administrateur_entreprise_index', [
                'entite' => $entite->getId(),
            ]);
        }

        $id = $entreprise->getId();

        if ($entreprise->getLogo()) {
            $this->removeLogoFile($entreprise->getLogo());
        }

        $em->remove($entreprise);
        $em->flush();

        $this->addFlash('success', 'Entreprise #' . $id . ' supprimée.');

        return $this->redirectToRoute('app_administrateur_entreprise_index', [
            'entite' => $entite->getId(),
        ]);
    }

    private function isLockedEntreprise(Entreprise $e): bool
    {
        return $e->getInscriptions()->count() > 0
            || $e->getConventionContrats()->count() > 0
            || $e->getFactures()->count() > 0;
    }

    private function getUploadDir(): string
    {
        return $this->getParameter('kernel.project_dir') . '/public/uploads/entreprises/logo';
    }

    private function uploadLogo(UploadedFile $file, string $baseName = 'entreprise'): string
    {
        $safeName = $this->slugger->slug($baseName ?: 'entreprise')->lower()->toString();
        $extension = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin';
        $filename = sprintf('%s-%s.%s', $safeName, uniqid('', true), $extension);

        $filesystem = new Filesystem();
        $uploadDir = $this->getUploadDir();

        if (!$filesystem->exists($uploadDir)) {
            $filesystem->mkdir($uploadDir, 0775);
        }

        $file->move($uploadDir, $filename);

        return $filename;
    }

    private function removeLogoFile(?string $filename): void
    {
        if (!$filename) {
            return;
        }

        $path = $this->getUploadDir() . '/' . ltrim($filename, '/');
        $filesystem = new Filesystem();

        if ($filesystem->exists($path)) {
            $filesystem->remove($path);
        }
    }

    private function getEntrepriseLogoUrl(?Entreprise $entreprise): ?string
    {
        if ($entreprise && $entreprise->getLogo()) {
            return $this->assets->getUrl('uploads/entreprises/logo/' . $entreprise->getLogo());
        }

        return null;
    }
}