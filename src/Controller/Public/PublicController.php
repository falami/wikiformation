<?php
// src/Controller/Public/PublicController.php
declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\{Formation, Utilisateur, Session, Reservation, Categorie, FormationContentNode};
use App\Repository\CategorieRepository; // ✅ AJOUT

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use App\Enum\EnginType;
use App\Filter\FormationsFilter;
use App\Repository\FormationRepository;
use App\Repository\SessionRepository;
use App\Repository\SiteRepository;
use App\Enum\StatusSession;
use App\Form\Public\ReservationType;
use App\Service\Utilisateur\UtilisateurManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\Billing\PlanRepository;
use App\Repository\Billing\AddonRepository;
use App\Repository\Billing\EntiteSubscriptionRepository;
use App\Service\Billing\StripeBillingService;
use App\Form\ContactType;
use Symfony\Component\Mailer\MailerInterface;

#[Route('/')]
final class PublicController extends AbstractController
{
    public function __construct(
        private readonly SessionRepository $sessionRepo,
        private readonly SiteRepository $siteRepo,
        private readonly UtilisateurManager $utilisateurManager,
        private readonly CategorieRepository $categorieRepo, // ✅ AJOUT
        private readonly FormationRepository $formationRepo, // ✅ AJOUT (utile pour pages catégorie)
    ) {}

    #[Route('', name: 'app_public', methods: ['GET'])]
    public function index(
        PlanRepository $plans,
        AddonRepository $addons,
        EntiteSubscriptionRepository $subRepo,
        StripeBillingService $billing,
    ): Response {
        $trialConsumed = false;

        $user = $this->getUser();
        if ($user instanceof Utilisateur && $user->getEntite()) {
            $sub = $subRepo->findLatestForEntite($user->getEntite());
            $trialConsumed = $sub && ($sub->getTrialEndsAt() instanceof \DateTimeImmutable);
        }

        $activePlans = $plans->findActiveOrdered();

        return $this->render('public/index.html.twig', [
            'plans'        => $activePlans,
            'addons'       => $addons->findBy(['isActive' => true], ['id' => 'ASC']),
            'trialConsumed' => $trialConsumed,
            'planPrices'   => $billing->getPlansPublicPrices($activePlans),
        ]);
    }

    // ✅ NOUVEAU : page catégorie (racine -> sous-catégories) ou (sous-catégorie -> formations)
    #[Route('/categorie/{slug}', name: 'app_public_categorie_show', methods: ['GET'])]
    public function categorieShow(string $slug, Request $request): Response
    {
        /** @var Categorie|null $categorie */
        $categorie = $this->categorieRepo->findOneBy(['slug' => $slug]);

        if (!$categorie instanceof Categorie) {
            throw $this->createNotFoundException('Catégorie introuvable.');
        }

        // Sous-catégories de cette catégorie
        $children = $this->categorieRepo->createQueryBuilder('c')
            ->andWhere('c.parent = :p')->setParameter('p', $categorie)
            ->orderBy('c.nom', 'ASC')
            ->getQuery()
            ->getResult();

        // Si c’est une sous-catégorie OU une catégorie sans enfants, on affiche les formations filtrées
        $showFormations = ($categorie->getParent() !== null) || (count($children) === 0);

        $rows = [];
        if ($showFormations) {
            // ⚠️ ADAPTE ICI si ton champ n'est pas "categorie" sur Formation
            $formations = $this->formationRepo->createQueryBuilder('f')
                ->andWhere('f.isPublic = 1') // ADAPTE si tu as ce champ, sinon enlève
                ->andWhere('f.categorie = :cat')->setParameter('cat', $categorie) // ✅ ADAPTE ICI
                ->orderBy('f.titre', 'ASC')
                ->getQuery()
                ->getResult();

            // On récupère la prochaine session publiée (si tu as déjà une méthode optimisée, remplace)
            // Ici: on se contente d'utiliser la méthode existante du catalogue si tu l'as.
            $nextSessions = $this->sessionRepo->findNextPublishedSessionsForFormations($formations);
            $nextByFormationId = [];
            foreach ($nextSessions as $s) {
                $f = $s->getFormation();
                if ($f) {
                    $nextByFormationId[$f->getId()] = $s;
                }
            }

            $rows = array_map(static function (Formation $f) use ($nextByFormationId): array {
                return [
                    'formation'   => $f,
                    'nextSession' => $nextByFormationId[$f->getId()] ?? null,
                ];
            }, $formations);
        }

        return $this->render('public/categorie/show.html.twig', [
            'categorie'       => $categorie,
            'children'        => $children,
            'showFormations'  => $showFormations,
            'formations'      => $rows,
        ]);
    }

    #[Route('/formation/{slug}', name: 'app_public_show', methods: ['GET'])]
    public function show(string $slug, FormationRepository $repo, Request $request): Response
    {
        $formation = $repo->findOneForPublicShowBySlug($slug);

        if (!$formation instanceof Formation) {
            throw $this->createNotFoundException('Formation introuvable.');
        }

        $now = new \DateTimeImmutable('today');

        $sessions = array_values(array_filter(
            $formation->getSessions()->toArray(),
            static fn(Session $s): bool => $s->getStatus() === StatusSession::PUBLISHED
        ));

        usort($sessions, static function (Session $a, Session $b) use ($now): int {
            $da = $a->getDateDebut() ?? $now;
            $db = $b->getDateDebut() ?? $now;
            return $da <=> $db;
        });

        $upcoming = array_values(array_filter(
            $sessions,
            static fn(Session $s): bool => ($s->getDateFin() ?? $now) >= $now
        ));

        $past = array_values(array_filter(
            $sessions,
            static fn(Session $s): bool => ($s->getDateFin() ?? $now) < $now
        ));

        $next = $upcoming[0] ?? null;

        $allNodes  = $formation->getContentNodes()->toArray();

        $rootNodes = array_values(array_filter(
            $allNodes,
            static fn(FormationContentNode $n): bool => $n->getParent() === null
        ));

        usort(
            $rootNodes,
            static fn(FormationContentNode $a, FormationContentNode $b): int => ($a->getPosition() ?? 0) <=> ($b->getPosition() ?? 0)
        );

        $contentTree = $rootNodes;

        $metaTitle = $formation->getTitre() . ' — Formations voile';

        $rawDesc  = (string) $formation->getDescription();
        $metaDesc = strip_tags($rawDesc);
        $metaDesc = mb_substr(trim($metaDesc), 0, 160);

        $reservationDemande = new Reservation();
        $reservationDemande->setFormation($formation);
        $reservationDemande->setDateReservation(new \DateTimeImmutable());

        $user = $this->getUser();
        if ($user !== null) {
            /** @var Utilisateur $user */
            $reservationDemande->setUtilisateur($user);
            $reservationDemande->setCreateur($user);
            $reservationDemande->setEntite($user->getEntite());
        } else {
            $user = $this->utilisateurManager->getRepository()->findOneBy(['id' => 1]);
            $reservationDemande->setCreateur($user);
            $reservationDemande->setEntite($user->getEntite());
        }





        $formReservationDemande = $this->createForm(ReservationType::class, $reservationDemande);


        return $this->render('public/show.html.twig', [
            'formation'              => $formation,
            'next'                   => $next,
            'upcoming'               => $upcoming,
            'past'                   => $past,
            'metaTitle'              => $metaTitle,
            'metaDesc'               => $metaDesc,
            'contentTree'            => $contentTree,
            'formReservationDemande' => $formReservationDemande->createView(),
        ]);
    }

    #[Route('/catalogue', name: 'app_public_catalogue', methods: ['GET'])]
    public function catalogue(FormationRepository $formationRepo, Request $request): Response
    {
        $formations = $formationRepo->findPublicCatalogue();
        $nextSessions = $this->sessionRepo->findNextPublishedSessionsForFormations($formations);

        $nextByFormationId = [];
        foreach ($nextSessions as $s) {
            $f = $s->getFormation();
            if ($f) {
                $nextByFormationId[$f->getId()] = $s;
            }
        }

        $rows = array_map(static function (Formation $f) use ($nextByFormationId): array {
            return [
                'formation'   => $f,
                'nextSession' => $nextByFormationId[$f->getId()] ?? null,
            ];
        }, $formations);

        return $this->render('public/catalogue.html.twig', [
            'formations' => $rows,
        ]);
    }


    #[Route('/contact', name: 'app_public_contact', methods: ['GET', 'POST'])]
    public function contact(Request $request, MailerInterface $mailer): Response
    {
        $form = $this->createForm(ContactType::class);
        $form->handleRequest($request);

        // pour revenir sur la page d’où vient le footer
        $referer = $request->headers->get('referer');
        $fallbackRedirect = $this->generateUrl('app_public_contact');

        if ($form->isSubmitted() && !$form->isValid()) {
            // IMPORTANT : si tu rediriges, tu perds les erreurs -> on les met en flash
            $messages = [];
            foreach ($form->getErrors(true) as $error) {
                $messages[] = $error->getMessage();
            }
            $this->addFlash('danger', implode(' • ', array_unique($messages)));

            return $this->redirect($referer ?: $fallbackRedirect);
        }

        if ($form->isSubmitted() && $form->isValid()) {

            // honeypot
            if ($form->get('website')->getData()) {
                $this->addFlash('danger', 'Une erreur est survenue.');
                return $this->redirect($referer ?: $fallbackRedirect);
            }

            // tempo minimale
            $startedAt = (int) $form->get('startedAt')->getData();
            if ($startedAt > 0 && (time() - $startedAt) < 2) {
                $this->addFlash('danger', 'Veuillez réessayer.');
                return $this->redirect($referer ?: $fallbackRedirect);
            }

            $data = $form->getData();

            $email = (new Email())
                ->from(new Address('no-reply@wikiformation.fr', 'Wikiformation'))
                ->replyTo($data['email'])
                ->to('contact@wikiformation.fr')
                ->subject('Nouveau message de contact — ' . $data['nom'])
                ->text("Nom : {$data['nom']}\nEmail : {$data['email']}\n\nMessage :\n{$data['message']}");

            try {
                $mailer->send($email);
                $this->addFlash('success', 'Votre message a bien été envoyé !');
            } catch (\Throwable $e) {
                $this->addFlash('danger', "L'envoi a échoué. Réessayez plus tard.");
            }

            return $this->redirect($referer ?: $fallbackRedirect);
        }

        // page dédiée /contact
        return $this->render('public/contact.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/_partials/footer-contact-form', name: 'app_public_footer_contact_form', methods: ['GET'])]
    public function footerContactForm(): Response
    {
        $form = $this->createForm(ContactType::class);

        return $this->render('public/_footer_contact_form.html.twig', [
            'contactForm' => $form->createView(),
        ]);
    }


    #[Route('/politique-de-confidentialite', name: 'app_public_politique_confidentialite')]
    public function politiqueConfidentialite(): Response
    {
        return $this->render('public/politique_confidentialite.html.twig');
    }

    #[Route('/mentions-legales', name: 'app_public_mentions_legales')]
    public function mentionsLegales(): Response
    {
        return $this->render('public/mentions_legales.html.twig');
    }

    #[Route('/cgu', name: 'app_public_cgu')]
    public function cgu(): Response
    {
        return $this->render('public/cgu.html.twig');
    }

    #[Route('/cgv', name: 'app_public_cgv')]
    public function cgv(): Response
    {
        return $this->render('public/cgv.html.twig');
    }


    #[Route('/tarifs', name: 'app_public_pricing')]
    public function price(
        PlanRepository $plans,
        AddonRepository $addons,
        EntiteSubscriptionRepository $subRepo,
    ): Response {
        $trialConsumed = false;

        $user = $this->getUser();
        if ($user instanceof Utilisateur && $user->getEntite()) {
            $sub = $subRepo->findLatestForEntite($user->getEntite());

            if ($sub) {
                $now = new \DateTimeImmutable();
                $trialEnds = $sub->getTrialEndsAt();

                // Essai consommé = il y a une date de fin d'essai ET elle est passée
                $trialConsumed = false;

                $user = $this->getUser();
                if ($user instanceof Utilisateur && $user->getEntite()) {
                    $sub = $subRepo->findLatestForEntite($user->getEntite());
                    $trialConsumed = $sub && ($sub->getTrialEndsAt() instanceof \DateTimeImmutable);
                }
            }
        }

        return $this->render('public/pricing.html.twig', [
            'plans' => $plans->findActiveOrdered(),
            'addons' => $addons->findBy(['isActive' => true], ['id' => 'ASC']),
            'trialConsumed' => $trialConsumed,
        ]);
    }


    #[Route('/formation', name: 'app_public_formation', methods: ['GET'])]
    public function formation(Request $request): Response
    {


        // 1) Filtres
        $filter = FormationsFilter::fromQuery($request->query->all());

        // 2) Destinations pour le select
        $destinations = $this->siteRepo->findDistinctDestinationsHavingSessions();

        // 3) Sessions filtrées (repo)
        $sessions = $this->sessionRepo->searchCalendar($filter, limit: 1000);

        // 🔒 Grand public : uniquement sessions PUBLISHED
        $sessions = array_values(array_filter(
            $sessions,
            static fn(Session $s): bool => $s->getStatus() === StatusSession::PUBLISHED
        ));

        // 4) Regrouper par formation + garder la prochaine session
        $formations = [];
        $now = new \DateTimeImmutable('now');

        foreach ($sessions as $session) {
            $formation = $session->getFormation();
            if (!$formation instanceof Formation) {
                continue;
            }

            $fid   = $formation->getId();
            $start = $session->getDateDebut() ?? $now;

            if (!isset($formations[$fid]) || $start < $formations[$fid]['start']) {
                $formations[$fid] = [
                    'formation'   => $formation,
                    'nextSession' => $session,
                    'start'       => $start,
                ];
            }
        }

        // 5) Tri
        $formations = array_values($formations);
        usort(
            $formations,
            static fn(array $a, array $b): int => $a['start'] <=> $b['start']
        );

        $enginTypeChoices = array_map(
            static fn(EnginType $c) => $c->value,
            EnginType::cases()
        );

        // ✅ 6) Catégories racines (parents = null) pour la galerie en haut de page
        // ADAPTE SI: tu as un champ isPublic / actif etc.
        $categoriesRoot = $this->categorieRepo->findHomeRoots();

        // 7) Choix du template (full page / fragment AJAX)
        $tpl = $request->isXmlHttpRequest()
            ? 'public/_list.html.twig'
            : 'public/formation.html.twig';

        return $this->render($tpl, [
            'formations'       => $formations,
            'destinations'     => $destinations,
            'activeFilters'    => $filter->toActiveFilters(),
            'enginTypeChoices' => $enginTypeChoices,
            'categoriesRoot'   => $categoriesRoot, // ✅ AJOUT
        ]);
    }
}
