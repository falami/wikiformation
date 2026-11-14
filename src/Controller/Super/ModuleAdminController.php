<?php
declare(strict_types=1);
namespace App\Controller\Super;
use App\Entity\Entite;
use App\Entity\Billing\{Module, ModuleAccess};
use App\Service\Module\ModuleBilling;
use App\Service\Stripe\StripeClientFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/super/options', name: 'app_super_modules_')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class ModuleAdminController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(EntityManagerInterface $em): Response
    {
        return $this->render('modules/admin.html.twig', ['modules' => $em->getRepository(Module::class)->findBy([], ['name' => 'ASC']), 'organisms' => $em->getRepository(Entite::class)->findBy([], ['nom' => 'ASC']), 'accesses' => $em->getRepository(ModuleAccess::class)->findBy([], ['id' => 'DESC'])]);
    }
    #[Route('/catalogue', name: 'save', methods: ['POST'])]
    public function save(Request $r, EntityManagerInterface $em, StripeClientFactory $stripe, \Psr\Log\LoggerInterface $logger): Response
    {
        $this->csrf($r);
        try {
            $code = $r->request->getString('code');
            if (!preg_match('/^[a-z][a-z0-9_]{1,59}$/D', $code)) throw new \DomainException('Code invalide : lettres minuscules, chiffres et tirets bas.');
            $stored = $em->find(Module::class, $code);
            // Validate a detached candidate so a later flush cannot persist rejected input.
            $module = new Module();
            $module->code = $code;
            $name = trim($r->request->getString('name'));
            $amount = str_replace(',', '.', trim($r->request->getString('price')));
            $days = $r->request->getString('trialDays');
            if ($name === '' || mb_strlen($name) > 120 || !preg_match('/^\d{1,6}(\.\d{1,2})?$/D', $amount) || !ctype_digit($days) || (int)$days > 730) throw new \DomainException('Vérifiez le nom, le tarif et la durée d’essai (0 à 730 jours).');
            $module->name = $name; $module->description = $r->request->getString('description');
            $module->monthlyPrice = (int)round((float)$amount * 100); $module->trialDays = (int)$days;
            $module->active = $r->request->getBoolean('active'); $module->included = $r->request->getBoolean('included');
            $module->stripePriceId = trim($r->request->getString('stripePriceId')) ?: null;
            if ($r->request->getBoolean('createPrice') && !$module->included) {
                if ($module->monthlyPrice < 50) throw new \DomainException('Le prix minimum est de 0,50 €.');
                $client = $stripe->client();
                $key = 'module-product-'.$module->code;
                $product = $client->products->create(['name' => $module->name, 'metadata' => ['module_code' => $module->code]], ['idempotency_key' => $key.'-'.hash('sha256', $module->name)]);
                $price = $client->prices->create(['product' => $product->id, 'currency' => 'eur', 'unit_amount' => $module->monthlyPrice, 'recurring' => ['interval' => 'month'], 'metadata' => ['module_code' => $module->code]], ['idempotency_key' => 'module-price-'.$product->id.'-'.$module->monthlyPrice]);
                $module->stripePriceId = $price->id;
            }
            if ($module->active && !$module->included) {
                if (!$module->stripePriceId || $module->monthlyPrice < 50) throw new \DomainException('Un tarif Stripe mensuel est nécessaire avant de publier une option payante.');
                ModuleBilling::validatePrice($module, $stripe->client()->prices->retrieve($module->stripePriceId)->toArray());
            }
            if ($stored) {
                foreach (get_object_vars($module) as $field => $value) $stored->$field = $value;
                $module = $stored;
            }
            $em->persist($module); $em->flush();
            $this->addFlash('success', 'Catalogue enregistré. Les abonnements existants conservent leur tarif.');
        } catch (\DomainException $e) { $this->addFlash('warning', $e->getMessage());
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $logger->error('Module catalogue Stripe error', ['exception' => $e]);
            $this->addFlash('danger', 'Stripe n’a pas confirmé le tarif. Vérifiez la configuration et réessayez. Le catalogue n’a pas été modifié.');
        }
        return $this->redirectToRoute('app_super_modules_index', [], 303);
    }
    #[Route('/acces', name: 'grant', methods: ['POST'])]
    public function grant(Request $r, EntityManagerInterface $em, ModuleBilling $billing): Response
    {
        $this->csrf($r);
        $entite = $em->find(Entite::class, $r->request->getInt('entite')) ?? throw $this->createNotFoundException();
        $module = $em->find(Module::class, $r->request->getString('code')) ?? throw $this->createNotFoundException();
        $action = $r->request->getString('action');
        if (!in_array($action, ['gift', 'trial', 'revoke'], true)) throw $this->createNotFoundException();
        $end = null; $date = $r->request->getString('end');
        if ($date !== '') {
            $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Europe/Paris'));
            if (!$end || $end->format('Y-m-d') !== $date || $end <= new \DateTimeImmutable()) throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Choisissez une date future valide.');
        }
        if ($action === 'trial' && !$end) throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Une fin est requise pour un essai.');
        $reason = trim($r->request->getString('reason'));
        if ($reason === '' || mb_strlen($reason) > 500) throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Indiquez un motif de 1 à 500 caractères.');
        $billing->locked($entite, function () use ($billing, $entite, $module, $action, $end, $reason) {
            $row = $billing->row($entite, $module);
            $row->gift = $action !== 'revoke'; $row->giftEnd = $action === 'revoke' ? null : $end;
            if ($action === 'trial') $row->trialUsed = true;
            $row->audit($action.': '.$reason.($end ? ' / fin '.$end->format(DATE_ATOM) : ''), $this->getUser()?->getId());
        });
        $this->addFlash('success', 'Droit mis à jour. Un accès offert ne modifie pas la facturation d’un abonnement Stripe déjà en cours.');
        return $this->redirectToRoute('app_super_modules_index', [], 303);
    }
    private function csrf(Request $r): void
    {
        if (!$this->isCsrfTokenValid('module_admin', $r->request->getString('_token'))) throw $this->createAccessDeniedException();
    }
}
