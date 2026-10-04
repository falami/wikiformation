<?php
namespace App\Controller\Administrateur;

use App\Entity\{Entite, RapportFormateur};
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\{ChoiceType, TextareaType};
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/administrateur/{entite}/rapports-formateurs', name: 'app_administrateur_rapport_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::FORMATEUR_MANAGE, subject: 'entite')]
final class RapportFormateurController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Entite $entite, Request $request, EntityManagerInterface $em): Response {
        $status = $request->query->getString('statut', 'open');
        $qb = $em->getRepository(RapportFormateur::class)->createQueryBuilder('r')
            ->leftJoin('r.session', 's')->addSelect('s')
            ->leftJoin('r.formateur', 'f')->addSelect('f')
            ->leftJoin('f.utilisateur', 'u')->addSelect('u')
            ->where('r.entite = :entite')->setParameter('entite', $entite);
        if ($status === 'open') $qb->andWhere('r.statutTraitement IN (:statuses)')->setParameter('statuses', ['new', 'in_progress']);
        elseif (in_array($status, RapportFormateur::STATUSES, true)) $qb->andWhere('r.statutTraitement = :status')->setParameter('status', $status);
        $qb->addSelect("CASE WHEN r.importance = 'urgent' THEN 0 WHEN r.importance = 'high' THEN 1 WHEN r.importance = 'normal' THEN 2 ELSE 3 END AS HIDDEN priorityOrder")->orderBy('priorityOrder', 'ASC')->addOrderBy('r.submittedAt', 'DESC');
        $page = max(1, $request->query->getInt('page', 1));
        $reports = $qb->setFirstResult(($page - 1) * 50)->setMaxResults(51)->getQuery()->getResult();
        $hasNext = count($reports) > 50;
        return $this->render('administrateur/rapport/index.html.twig', ['entite' => $entite, 'rapports' => array_slice($reports, 0, 50), 'statut' => $status, 'statuts' => RapportFormateur::STATUSES, 'page' => $page, 'hasNext' => $hasNext]);
    }

    #[Route('/{id}/traiter', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Entite $entite, RapportFormateur $rapport, Request $request, EntityManagerInterface $em): Response {
        if ($rapport->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
        $form = $this->createFormBuilder(['statut' => $rapport->getStatutTraitement(), 'actions' => $rapport->getActionsRealisees()])
            ->add('statut', ChoiceType::class, ['label' => 'Statut de traitement', 'choices' => RapportFormateur::STATUSES, 'attr' => ['class' => 'form-select']])
            ->add('actions', TextareaType::class, ['label' => 'Actions réalisées / réponse au formateur', 'required' => false, 'attr' => ['rows' => 6, 'class' => 'form-control']])
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $rapport->traiter($data['statut'], $data['actions'], $this->getUser());
            $em->flush();
            $this->addFlash('success', 'Le suivi du rapport a été enregistré.');
            return $this->redirectToRoute('app_administrateur_rapport_edit', ['entite' => $entite->getId(), 'id' => $rapport->getId()]);
        }
        return $this->render('administrateur/rapport/edit.html.twig', ['entite' => $entite, 'rapport' => $rapport, 'form' => $form, 'statuts' => RapportFormateur::STATUSES]);
    }
}
