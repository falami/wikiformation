<?php

namespace App\Controller\Administrateur;

use App\Entity\{Entite, Entreprise, Utilisateur};
use App\Exception\BillingQuotaExceededException;
use App\Security\Permission\TenantPermission;
use App\Service\Billing\BillingGuard;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class SessionEntrepriseController extends AbstractController
{
    #[Route('/administrateur/{entite}/session/ajax/entreprise/new', name: 'app_administrateur_session_entreprise_new', methods: ['POST'])]
    #[IsGranted(TenantPermission::SESSION_MANAGE, subject: 'entite')]
    #[IsGranted(TenantPermission::ENTREPRISE_MANAGE, subject: 'entite')]
    public function create(Entite $entite, Request $request, EntityManagerInterface $em, BillingGuard $billing): JsonResponse
    {
        if (!$this->isCsrfTokenValid('session_entreprise_new_'.$entite->getId(), (string) $request->request->get('_token'))) {
            return $this->json(['success'=>false, 'message'=>'Le formulaire a expiré. Rechargez la page.'], 403);
        }
        $values = [];
        foreach (['raisonSociale'=>255, 'siret'=>14, 'email'=>180, 'adresse'=>255, 'codePostal'=>20, 'ville'=>100] as $key=>$max) {
            $values[$key] = trim((string) $request->request->get($key, ''));
            if ($key === 'siret') $values[$key] = preg_replace('/\s+/', '', $values[$key]);
            if (mb_strlen($values[$key]) > $max) {
                return $this->json(['success'=>false, 'message'=>'Un champ dépasse la longueur autorisée.'], 422);
            }
        }
        if ($values['raisonSociale'] === '' || ($values['siret'] !== '' && !preg_match('/^\d{14}$/D', $values['siret']))
            || ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL))) {
            return $this->json(['success'=>false, 'message'=>'Renseignez la raison sociale, un e-mail valide et un SIRET de 14 chiffres si vous les indiquez.'], 422);
        }

        $query = $em->getRepository(Entreprise::class)->createQueryBuilder('e')->andWhere('e.entite = :entite')->setParameter('entite', $entite);
        $match = 'LOWER(TRIM(e.raisonSociale)) = :name';
        if ($values['siret'] !== '') {
            $match .= ' OR e.siret = :siret';
            $query->setParameter('siret', $values['siret']);
        }
        $matches = $query->andWhere('('.$match.')')->setParameter('name', mb_strtolower($values['raisonSociale']))->getQuery()->getResult();
        if (count($matches) > 1) {
            return $this->json(['success'=>false, 'message'=>'Le nom et le SIRET correspondent à plusieurs entreprises. Sélectionnez la bonne entreprise dans la liste.'], 409);
        }
        $entreprise = $matches[0] ?? null;
        $already = $entreprise !== null;
        if (!$entreprise) {
            try {
                $billing->assertCanCreateEntreprise($entite);
                /** @var Utilisateur $creator */
                $creator = $this->getUser();
                $entreprise = (new Entreprise())->setEntite($entite)->setCreateur($creator)->setRaisonSociale($values['raisonSociale'])
                    ->setSiret($values['siret'] ?: null)->setEmail($values['email'] ?: null)
                    ->setAdresse($values['adresse'] ?: null)->setCodePostal($values['codePostal'] ?: null)->setVille($values['ville'] ?: null);
                $em->persist($entreprise);
                $em->flush();
            } catch (BillingQuotaExceededException $e) {
                return $this->json(['success'=>false, 'message'=>$e->getMessage()], 409);
            } catch (UniqueConstraintViolationException) {
                return $this->json(['success'=>false, 'retryable'=>true, 'message'=>'Cette entreprise vient d’être créée. Réessayez pour la sélectionner.'], 409);
            }
        }
        return $this->json(['success'=>true, 'id'=>$entreprise->getId(), 'label'=>$entreprise->getRaisonSociale(), 'already'=>$already], $already ? 200 : 201);
    }
}
