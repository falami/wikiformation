<?php

declare (strict_types=1);
namespace App\Controller;

use App\Entity\{Entite, Utilisateur};
use App\Entity\Partner\{Partner, PartnerTerms, Referral, Commission};
use App\Entity\Billing\EntiteSubscription;
use App\Service\Partner\{Referrals, PartnerStripeGateway};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
final class PartnerController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private Referrals $referrals,
        private PartnerStripeGateway $stripe,
        private LockFactory $locks,
        #[Autowire('%env(bool:default:app.partner_payouts_default:PARTNER_PAYOUTS_ENABLED)%')]
        private bool $payoutsEnabled,
        #[Autowire('%app.url%')]
        private string $appUrl
    )
    {
    }
    #[Route('/recommande/{code}', name: 'app_partner_referral', methods: ['GET'], requirements: ['code' => '[A-Za-z0-9-]{4,40}'])]
    public function referral(string $code, Request $request): Response
    {
        $partner = $this->referrals->byCode($code);
        if (!$partner) {
            throw $this->createNotFoundException('Code partenaire inconnu ou suspendu.');
        }
        // Session-only attribution, confirmed in the organisation creation form. No analytics cookie.
        if (!$request->getSession()->has('affiliate_code')) {
            $request->getSession()->set('affiliate_code', $partner->code);
        }
        return $this->redirectToRoute('app_public_pricing');
    }
    #[Route('/super/partenaires', name: 'app_super_partners', methods: ['GET', 'POST'])]
    public function index(Request $request, UserPasswordHasherInterface $hasher): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');
        $error = null;
        if ($request->isMethod('POST')) {
            $this->csrf($request, 'partner_create');
            try {
                $email = mb_strtolower(trim($request->request->getString('email')));
                $name = trim($request->request->getString('name'));
                $first = trim($request->request->getString('firstName'));
                $last = trim($request->request->getString('lastName'));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 180 || !$name || mb_strlen($name) > 100 || !$first || !$last || mb_strlen($first) > 50 || mb_strlen($last) > 50) {
                    throw new \DomainException('Renseignez un nom, un prénom, une structure et une adresse e-mail valides.');
                }
                $rate = $this->bps($request->request->getString('rate'));
                $user = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
                if ($user && !$request->request->getBoolean('confirmExisting')) {
                    throw new \DomainException('Ce compte existe. Confirmez son identité pour le rattacher.');
                }
                if ($user && $this->em->getRepository(Partner::class)->findOneBy(['user' => $user])) {
                    throw new \DomainException('Ce compte possède déjà un espace partenaire.');
                }
                if (!$user) {
                    $user = (new Utilisateur())->setEmail($email)->setPrenom($first)->setNom($last);
                    $user->setPassword($hasher->hashPassword($user, bin2hex(random_bytes(32))));
                    $this->em->persist($user);
                }
                $user->setRoles(array_values(array_unique([...$user->getRoles(), 'ROLE_PARTNER'])));
                $p = new Partner();
                $p->name = $name;
                $p->user = $user;
                $p->code = 'WF-' . strtoupper(bin2hex(random_bytes(5)));
                $this->em->persist($p);
                $terms = new PartnerTerms();
                $terms->partner = $p;
                $terms->author = $this->getUser();
                $terms->rateBps = $rate;
                $this->em->persist($terms);
                $this->em->flush();
                return $this->redirectToRoute('app_super_partner', ['id' => $p->id]);
            } catch (\DomainException $e) {
                $error = $e->getMessage();
            }
        }
        $page = max(1, $request->query->getInt('page', 1));
        $partners = $this->em->getRepository(Partner::class)->findBy([], ['id' => 'DESC'], 31, ($page - 1) * 30);
        $rows = [];
        foreach (array_slice($partners, 0, 30) as $p) {
            $rows[] = ['partner' => $p, 'terms' => $this->referrals->terms($p), 'clients' => $this->em->getRepository(Referral::class)->count(['partner' => $p])];
        }
        return $this->render('partner/index.html.twig', ['rows' => $rows, 'page' => $page, 'more' => count($partners) > 30, 'error' => $error, 'values' => $request->request->all(), 'enabled' => $this->payoutsEnabled]);
    }
    #[Route('/super/partenaires/{id}', name: 'app_super_partner', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function manage(Partner $partner, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');
        $error = null;
        if ($request->isMethod('POST')) {
            $this->csrf($request, 'partner_' . $partner->id);
            try {
                if ($request->request->getString('action') === 'attach') {
                    $entite = $this->em->find(Entite::class, $request->request->getInt('entite'));
                    if (!$entite) {
                        throw new \DomainException('Organisme introuvable.');
                    }
                    $this->referrals->attach($partner, $entite, $this->getUser(), 'super_admin');
                } else {
                    $rate = $this->bps($request->request->getString('rate'));
                    $fee = $this->bps($request->request->getString('feeRate'));
                    $fixed = $this->cents($request->request->getString('feeFixed'));
                    $automatic = $request->request->getBoolean('automatic');
                    if ($automatic && !$request->request->getBoolean('feesConfirmed')) {
                        throw new \DomainException('Confirmez les frais convenus avec le partenaire avant d’activer ses versements.');
                    }
                    $terms = new PartnerTerms();
                    $terms->partner = $partner;
                    $terms->author = $this->getUser();
                    $terms->rateBps = $rate;
                    $terms->feeBps = $fee;
                    $terms->feeFixedCents = $fixed;
                    $this->em->persist($terms);
                    $partner->active = $request->request->getBoolean('active');
                    $partner->automaticPayouts = $automatic;
                }
                $this->em->flush();
                $this->addFlash('success', 'Paramètres enregistrés. Les commissions déjà calculées conservent leurs conditions.');
                return $this->redirectToRoute('app_super_partner', ['id' => $partner->id]);
            } catch (\DomainException $e) {
                $error = $e->getMessage();
            }
        }
        return $this->detail($partner, $request, true, $error);
    }
    #[Route('/partenaire', name: 'app_partner_dashboard', methods: ['GET'])]
    public function dashboard(Request $request): Response
    {
        return $this->detail($this->own(), $request, false);
    }
    #[Route('/partenaire/banque', name: 'app_partner_bank', methods: ['POST'])]
    public function bank(Request $request): Response
    {
        $p = $this->own();
        $this->csrf($request, 'partner_bank');
        $lock = $this->locks->createLock('affiliate-account-' . $p->id, 300);
        if (!$lock->acquire()) {
            throw $this->createAccessDeniedException('Une configuration est déjà en cours.');
        }
        try {
            if (!$p->stripeAccountId) {
                $p->stripeAccountId = $this->stripe->createAccount($p->user->getEmail(), $p->id);
                $this->em->flush();
            }
            $return = rtrim($this->appUrl, '/') . $this->generateUrl('app_partner_bank_return');
            return $this->redirect($this->stripe->onboarding($p->stripeAccountId, $return, $return));
        } catch (\Throwable $e) {
            $this->addFlash('warning', 'Configuration bancaire indisponible. Contactez la plateforme ou réessayez plus tard.');
            return $this->redirectToRoute('app_partner_dashboard');
        } finally {
            $lock->release();
        }
    }
    #[Route('/partenaire/banque/retour', name: 'app_partner_bank_return', methods: ['GET'])]
    public function bankReturn(): Response
    {
        $p = $this->own();
        if ($p->stripeAccountId) {
            try {
                $a = $this->stripe->account($p->stripeAccountId);
                $p->payoutsEnabled = (bool) $a['payouts_enabled'] && ($a['capabilities']['transfers'] ?? '') === 'active';
                $this->em->flush();
            } catch (\Throwable $e) {
                $this->addFlash('warning', 'Le statut bancaire n’a pas pu être actualisé.');
            }
        }
        return $this->redirectToRoute('app_partner_dashboard');
    }
    #[Route('/partenaire/banque/espace', name: 'app_partner_bank_dashboard', methods: ['POST'])]
    public function bankDashboard(Request $request): Response
    {
        $p = $this->own();
        $this->csrf($request, 'partner_bank');
        if (!$p->stripeAccountId) {
            return $this->redirectToRoute('app_partner_dashboard');
        }
        try {
            return $this->redirect($this->stripe->dashboard($p->stripeAccountId));
        } catch (\Throwable $e) {
            $this->addFlash('warning', 'Espace bancaire temporairement indisponible.');
            return $this->redirectToRoute('app_partner_dashboard');
        }
    }
    private function own(): Partner
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $p = $this->em->getRepository(Partner::class)->findOneBy(['user' => $this->getUser()]);
        if (!$p) {
            throw $this->createAccessDeniedException('Cet espace est réservé aux apporteurs enregistrés.');
        }
        return $p;
    }
    private function detail(Partner $p, Request $request, bool $admin, ?string $error = null): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $qb = $this->em->getRepository(Commission::class)->createQueryBuilder('c')->join('c.referral', 'r')->where('r.partner=:p')->setParameter('p', $p);
        $totals = (clone $qb)->select('c.status AS status, SUM(c.grossCents) AS gross, SUM(c.netCents-c.reversedCents) AS net, COUNT(c.id) AS count')->groupBy('c.status')->getQuery()->getArrayResult();
        $commissions = $qb->orderBy('c.paidAt', 'DESC')->setFirstResult(($page - 1) * 30)->setMaxResults(31)->getQuery()->getResult();
        $clients = [];
        foreach ($this->em->getRepository(Referral::class)->findBy(['partner' => $p], ['id' => 'DESC']) as $ref) {
            $clients[] = ['referral' => $ref, 'subscription' => $this->em->getRepository(EntiteSubscription::class)->findOneBy(['entite' => $ref->entite], ['id' => 'DESC'])];
        }
        return $this->render('partner/detail.html.twig', ['partner' => $p, 'terms' => $this->referrals->terms($p), 'admin' => $admin, 'error' => $error, 'clients' => $clients, 'commissions' => array_slice($commissions, 0, 30), 'more' => count($commissions) > 30, 'page' => $page, 'totals' => $totals, 'termsHistory' => $admin ? $this->em->getRepository(PartnerTerms::class)->findBy(['partner' => $p], ['id' => 'DESC'], 20) : [], 'entities' => $admin ? $this->em->getRepository(Entite::class)->findBy([], ['nom' => 'ASC']) : [], 'referralUrl' => rtrim($this->appUrl, '/') . $this->generateUrl('app_partner_referral', ['code' => $p->code]), 'enabled' => $this->payoutsEnabled]);
    }
    private function csrf(Request $r, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $r->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Rechargez le formulaire.');
        }
    }
    private function cents(string $value): int
    {
        $value = str_replace(',', '.', trim($value));
        if (!preg_match('/^\d{1,4}(?:\.\d{1,2})?$/D', $value)) {
            throw new \DomainException('Montant invalide (deux décimales maximum).');
        }
        [$whole, $decimal] = array_pad(explode('.', $value), 2, '');
        return (int) $whole * 100 + (int) str_pad($decimal, 2, '0');
    }
    private function bps(string $value): int
    {
        $bps = $this->cents($value);
        if ($bps > 10000) {
            throw new \DomainException('Le pourcentage doit être compris entre 0 et 100.');
        }
        return $bps;
    }
}
