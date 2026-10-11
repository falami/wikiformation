<?php
declare(strict_types=1);
namespace App\Controller\Administrateur;
use App\Entity\{Entite, EntitePreferences};
use App\Form\Administrateur\InvoicePreferencesType;
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(TenantPermission::ENTITE_PREFERENCE_MANAGE, subject: 'entite')]
final class InvoicePreferencesController extends AbstractController
{
    #[Route('/administrateur/{entite}/preferences/facturation', name: 'app_administrateur_preferences_facturation', methods: ['GET', 'POST'], requirements: ['entite' => '\d+'])]
    public function index(Entite $entite, Request $request, EntityManagerInterface $em): Response
    {
        $preferences = $entite->getPreferences();
        $settings = $preferences?->getInvoicePreferences() ?? [];
        unset($settings['confirmedAt'], $settings['confirmedBy']);
        $form = $this->createForm(InvoicePreferencesType::class, $settings)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $settings = $form->getData();
            $settings['confirmedAt'] = $settings['confirmed'] ? (new \DateTimeImmutable())->format(DATE_ATOM) : null;
            $settings['confirmedBy'] = $settings['confirmed'] ? $this->getUser()->getId() : null;
            if (!$preferences) {
                $preferences = (new EntitePreferences())->setEntite($entite)->setCreateur($this->getUser());
                $entite->setPreferences($preferences);
                $em->persist($preferences);
            }
            $preferences->setInvoicePreferences($settings)->setUpdatedBy($this->getUser());
            $em->flush();
            $this->addFlash('success', 'Les préférences de facturation ont été enregistrées. Les factures déjà émises conservent leurs mentions.');
            return $this->redirectToRoute('app_administrateur_preferences_facturation', ['entite' => $entite->getId()]);
        }
        return $this->render('administrateur/preferences/facturation.html.twig', ['entite' => $entite, 'form' => $form]);
    }
}
