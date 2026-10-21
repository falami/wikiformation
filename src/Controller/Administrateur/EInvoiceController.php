<?php
declare(strict_types=1);
namespace App\Controller\Administrateur;

use App\Entity\{Entite, EInvoiceConnection, EInvoiceTransmission, EInvoiceReceived, AuditLog};
use App\Repository\Billing\EntiteSubscriptionRepository;
use App\Security\Permission\TenantPermission;
use App\Service\EInvoicing\{EInvoiceConfiguration, SuperPdpClient, SuperPdpException, ConnectionManager, ConnectionLease, EInvoiceUsageBilling};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, RedirectResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class EInvoiceController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em, private EInvoiceConfiguration $configuration,
        private SuperPdpClient $api, private ConnectionManager $manager, private ConnectionLease $lease) {}

    #[Route('/administrateur/{entite}/preferences/facturation-electronique', name: 'app_administrateur_einvoice', methods: ['GET'], requirements: ['entite' => '\d+'])]
    public function index(Entite $entite, Request $request, EInvoiceUsageBilling $usage): Response
    {
        $this->denyAccessUnlessGranted(TenantPermission::ENTITE_PREFERENCE_MANAGE, $entite);
        $mode = $this->mode($request);
        $connection = $this->em->getRepository(EInvoiceConnection::class)->findOneBy(['entite' => $entite, 'environment' => $mode]);
        return $this->privateResponse($this->render('administrateur/preferences/einvoice.html.twig', [
            'entite' => $entite, 'mode' => $mode, 'connection' => $connection, 'configured' => $this->configuration->ready(),
            'usage' => $usage->summary($entite),
            'transmissions' => $connection ? $this->em->getRepository(EInvoiceTransmission::class)->findBy(['connection' => $connection], ['id' => 'DESC'], 50) : [],
            'received' => $connection ? $this->em->getRepository(EInvoiceReceived::class)->findBy(['connection' => $connection], ['id' => 'DESC'], 50) : [],
        ]));
    }

    #[Route('/administrateur/{entite}/preferences/facturation-electronique/connexion', name: 'app_administrateur_einvoice_connect', methods: ['POST'], requirements: ['entite' => '\d+'])]
    public function connect(Entite $entite, Request $request): Response
    {
        $this->denyAccessUnlessGranted(TenantPermission::ENTITE_PREFERENCE_MANAGE, $entite);
        $mode = $this->mode($request); $this->csrf($request, $entite, 'connect', $mode);
        if (!$this->configuration->ready()) {
            $this->addFlash('warning', 'Le raccordement de la plateforme doit être configuré par votre administrateur technique.');
            return $this->back($entite, $mode);
        }
        $number = preg_replace('/\s+/', '', $entite->getSiret() ?? '');
        if ($mode === 'production' && !preg_match('/^\d{14}$/', $number)) {
            $this->addFlash('warning', 'Complétez le SIRET de votre organisme avant de le connecter.');
            return $this->back($entite, $mode);
        }
        $state = bin2hex(random_bytes(32));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $request->getSession()->set('einvoice_oauth', ['state' => $state, 'verifier' => $verifier, 'entite' => $entite->getId(),
            'actor' => $this->getUser()->getId(), 'mode' => $mode, 'created' => time(), 'locale' => $request->getLocale()]);
        return $this->privateResponse(new RedirectResponse($this->api->authorizationUrl(
            $this->configuration->clientId, $this->configuration->redirectUri, $state, $challenge,
            $mode === 'production' ? substr($number, 0, 9) : null, $mode === 'production' ? 'fr_siren' : 'sandbox'
        )));
    }

    #[Route('/administrateur/facturation-electronique/retour', name: 'app_administrateur_einvoice_callback', methods: ['GET'])]
    public function callback(Request $request): Response
    {
        $pending = $request->getSession()->remove('einvoice_oauth');
        if (!is_array($pending) || !is_string($request->query->get('state')) || !hash_equals($pending['state'], $request->query->get('state'))
            || time() - $pending['created'] > 600 || $pending['actor'] !== $this->getUser()->getId()) {
            return $this->privateResponse($this->render('administrateur/preferences/einvoice_connection_expired.html.twig', [], new Response(status: 403)));
        }
        $entite = $this->em->find(Entite::class, $pending['entite']) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(TenantPermission::ENTITE_PREFERENCE_MANAGE, $entite);
        $mode = $pending['mode'];
        if (!$this->configuration->ready() || !$request->query->get('code') || $request->query->has('error')) {
            $this->addFlash('warning', 'La connexion n’a pas été finalisée. Vous pouvez recommencer depuis cette page.');
            return $this->back($entite, $mode);
        }
        $connection = $this->em->getRepository(EInvoiceConnection::class)->findOneBy(['entite' => $entite, 'environment' => $mode]);
        if (!$connection) { $connection = new EInvoiceConnection($entite, $mode); $this->em->persist($connection); $this->em->flush(); }
        $lock = $this->lease->acquire($connection->getId());
        if (!$lock) { $this->addFlash('warning', 'Une synchronisation est en cours. Réessayez la connexion dans quelques instants.'); return $this->back($entite, $mode); }
        try {
            $this->em->refresh($connection);
            $tokens = $this->api->exchangeCode($this->configuration->clientId, $this->configuration->clientSecret,
                (string) $request->query->get('code'), $this->configuration->redirectUri, $pending['verifier']);
            $this->manager->connect($connection, $tokens);
            $this->audit($connection, 'EINVOICE_CONNECTED'); $this->em->flush();
            $this->addFlash('success', 'Organisme connecté et identité vérifiée. L’envoi automatique reste à activer séparément.');
        } catch (\DomainException $e) { $this->addFlash('warning', $e->getMessage());
        } catch (SuperPdpException|\RuntimeException $e) { $this->addFlash('warning', 'Connexion non finalisée. Vérifiez la configuration et votre compte auprès de la plateforme, puis reconnectez-vous.');
        } finally { $this->lease->release($connection->getId(), $lock); }
        return $this->back($entite, $mode);
    }

    #[Route('/administrateur/{entite}/preferences/facturation-electronique/automatique', name: 'app_administrateur_einvoice_automatic', methods: ['POST'], requirements: ['entite' => '\d+'])]
    public function automatic(Entite $entite, Request $request, EntiteSubscriptionRepository $subscriptions): Response
    {
        $this->denyAccessUnlessGranted(TenantPermission::ENTITE_PREFERENCE_MANAGE, $entite);
        $mode = $this->mode($request); $this->csrf($request, $entite, 'automatic', $mode);
        $connection = $this->connection($entite, $mode);
        $enable = $request->request->get('enable') === '1';
        if ($enable) {
            $this->denyAccessUnlessGranted(TenantPermission::BILLING_MANAGE, $entite);
            $subscription = $subscriptions->findLatestForEntite($entite);
            $now = new \DateTimeImmutable();
            if ($mode !== 'production' || !$connection->isVerified() || !$connection->getCredentials()
                || !$subscription || $subscription->getStatus() !== 'active'
                || !preg_match('/^cus_[A-Za-z0-9]+$/D', $subscription->getStripeCustomerId() ?? '')
                || !preg_match('/^sub_[A-Za-z0-9]+$/D', $subscription->getStripeSubscriptionId() ?? '')
                || ($subscription->getEndedAt() && $subscription->getEndedAt() <= $now)
                || ($subscription->getCurrentPeriodEnd() && $subscription->getCurrentPeriodEnd() <= $now)
                || $request->request->get('accept_price') !== '1') {
                $this->addFlash('warning', 'Un abonnement Stripe actif et l’acceptation du tarif affiché par facture transmise sont nécessaires.');
                return $this->back($entite, $mode);
            }
        }
        $lock = $this->lease->acquire($connection->getId());
        if (!$lock) { $this->addFlash('warning', 'Une synchronisation est en cours. Réessayez dans quelques instants.'); return $this->back($entite, $mode); }
        try {
            $this->em->refresh($connection);
            if ($enable) $connection->consentToBilling($this->getUser()->getId());
            $connection->setAutomatic($enable);
            $this->audit($connection, $enable ? 'EINVOICE_AUTOMATIC_ENABLED' : 'EINVOICE_AUTOMATIC_DISABLED');
            $this->em->flush();
            $this->addFlash('success', $enable ? 'L’envoi automatique des nouvelles factures éligibles est activé à '.number_format($connection->getUnitPriceCents() / 100, 2, ',', '').' € HT par facture transmise.' : 'L’envoi automatique est désactivé.');
        } catch (\DomainException $e) { $this->addFlash('warning', $e->getMessage());
        } finally { $this->lease->release($connection->getId(), $lock); }
        return $this->back($entite, $mode);
    }

    #[Route('/administrateur/{entite}/preferences/facturation-electronique/deconnexion', name: 'app_administrateur_einvoice_disconnect', methods: ['POST'], requirements: ['entite' => '\d+'])]
    public function disconnect(Entite $entite, Request $request): Response
    {
        $this->denyAccessUnlessGranted(TenantPermission::ENTITE_PREFERENCE_MANAGE, $entite);
        $mode = $this->mode($request); $this->csrf($request, $entite, 'disconnect', $mode);
        $connection = $this->connection($entite, $mode);
        $lock = $this->lease->acquire($connection->getId());
        if (!$lock) { $this->addFlash('warning', 'Une synchronisation est en cours. Réessayez dans quelques instants.'); return $this->back($entite, $mode); }
        try { $this->em->refresh($connection); $connection->disconnect(); $this->audit($connection, 'EINVOICE_DISCONNECTED'); $this->em->flush();
        } finally { $this->lease->release($connection->getId(), $lock); }
        $request->getSession()->remove('einvoice_oauth');
        $this->addFlash('success', 'Connexion locale supprimée. L’historique et les transmissions déjà facturables sont conservés. Vous pouvez également révoquer l’accès depuis la plateforme.');
        return $this->back($entite, $mode);
    }

    #[Route('/administrateur/{entite}/preferences/facturation-electronique/recue/{id}', name: 'app_administrateur_einvoice_download', methods: ['GET'], requirements: ['entite' => '\d+', 'id' => '\d+'])]
    public function download(Entite $entite, int $id): Response
    {
        $this->denyAccessUnlessGranted(TenantPermission::FACTURE_MANAGE, $entite);
        $received = $this->em->find(EInvoiceReceived::class, $id) ?? throw $this->createNotFoundException();
        $connection = $received->getConnection();
        if ($connection->getEntite()->getId() !== $entite->getId()) throw $this->createNotFoundException();
        $lock = $this->lease->acquire($connection->getId());
        if (!$lock) return $this->privateResponse(new Response('Synchronisation en cours. Réessayez dans quelques instants.', 409));
        try {
            $this->em->refresh($connection);
            $file = $this->api->downloadInvoice($this->manager->accessToken($connection), $received->getProviderId());
            return $this->privateResponse(new Response($file['content'], 200, ['Content-Type' => 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="facture-recue-'.$id.(str_starts_with($file['content'], '%PDF') ? '.pdf' : '.xml').'"', 'X-Content-Type-Options' => 'nosniff']));
        } catch (SuperPdpException|\DomainException|\RuntimeException $e) {
            return $this->privateResponse(new Response('Téléchargement indisponible. Vérifiez la connexion à la plateforme.', 503));
        } finally { $this->lease->release($connection->getId(), $lock); }
    }
    private function mode(Request $request): string
    {
        $mode = (string) ($request->isMethod('POST') ? $request->request->get('mode', 'production') : $request->query->get('mode', 'production'));
        if (!in_array($mode, ['production', 'sandbox'], true)) throw $this->createNotFoundException();
        return $mode;
    }
    private function connection(Entite $entite, string $mode): EInvoiceConnection
    { return $this->em->getRepository(EInvoiceConnection::class)->findOneBy(['entite' => $entite, 'environment' => $mode]) ?? throw $this->createNotFoundException(); }
    private function csrf(Request $request, Entite $entite, string $action, string $mode): void
    { if (!$this->isCsrfTokenValid('einvoice-'.$action.'-'.$entite->getId().'-'.$mode, $request->request->get('_token'))) throw $this->createAccessDeniedException('Formulaire expiré.'); }
    private function audit(EInvoiceConnection $connection, string $event): void
    {
        $this->em->persist((new AuditLog())->setEvent($event)->setActor($this->getUser())->setCreateur($this->getUser())->setEntite($connection->getEntite())
            ->setCreatedAt(new \DateTimeImmutable())->setPayload(['connection' => $connection->getId(), 'mode' => $connection->getEnvironment(), 'unitPriceCents' => $connection->getUnitPriceCents()]));
    }
    private function back(Entite $entite, string $mode): Response
    { return $this->privateResponse($this->redirectToRoute('app_administrateur_einvoice', ['entite' => $entite->getId(), 'mode' => $mode])); }
    private function privateResponse(Response $response): Response
    { $response->headers->set('Cache-Control', 'private, no-store'); $response->headers->set('Referrer-Policy', 'no-referrer'); return $response; }
}
