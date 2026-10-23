<?php

declare(strict_types=1);
namespace App\Controller\Administrateur;

use App\Entity\{Entite, Session, Formateur, Emargement, AuditLog, ContratFormateur};
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\{CheckboxType, TextareaType};
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class TrainerAttendanceCorrectionController extends AbstractController
{
    #[Route('/administrateur/{entite}/session/{id}/corriger-intervenant', name: 'app_admin_session_correct_trainer', methods: ['GET','POST'], requirements: ['entite'=>'\d+', 'id'=>'\d+'])]
    #[IsGranted(TenantPermission::SESSION_MANAGE, subject: 'entite')]
    public function edit(Entite $entite, Session $session, Request $request, EntityManagerInterface $em): Response
    {
        if ($session->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
        $trainers = $em->getRepository(Formateur::class)->findBy(['entite'=>$entite]);
        $currentTrainers = array_values(array_filter($session->getFormateursEffectifs(), static fn(Formateur $f): bool => $f->getUtilisateur() !== null));
        $trainers = array_values(array_filter($trainers, static fn(Formateur $f): bool => $f->getUtilisateur() !== null));
        $contracts = $em->getRepository(ContratFormateur::class)->findBy(['entite'=>$entite, 'session'=>$session], ['id'=>'DESC']);
        $options = ['class'=>Formateur::class, 'choices'=>$trainers, 'choice_label'=>static fn(Formateur $f): string => trim($f->getUtilisateur()?->getPrenom().' '.$f->getUtilisateur()?->getNom()).' · '.$f->getUtilisateur()?->getEmail(), 'placeholder'=>'Choisir un formateur'];
        $form = $this->createFormBuilder()
            ->add('source', EntityType::class, array_replace($options, ['choices'=>$currentTrainers, 'data'=>count($currentTrainers) === 1 ? $currentTrainers[0] : null, 'label'=>'Formateur indiqué actuellement']))
            ->add('target', EntityType::class, $options + ['label'=>'Formateur ayant réellement assuré et signé la formation'])
            ->add('reason', TextareaType::class, ['label'=>'Motif de la correction', 'constraints'=>[new \Symfony\Component\Validator\Constraints\NotBlank(), new \Symfony\Component\Validator\Constraints\Length(max:2000)]])
            ->add('confirm', CheckboxType::class, ['label'=>'Je confirme que les signatures concernées ont été réalisées par le formateur remplaçant.', 'constraints'=>[new \Symfony\Component\Validator\Constraints\IsTrue()]])
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData(); $source = $data['source']; $target = $data['target'];
            if ($source === $target || !$source->getUtilisateur() || !$target->getUtilisateur() || !$session->hasFormateur($source)) {
                $form->addError(new FormError('Sélectionnez deux formateurs distincts, avec un compte utilisateur, dont le formateur actuel de la session.'));
            } else {
                $rows = $em->getRepository(Emargement::class)->findBy(['session'=>$session, 'entite'=>$entite, 'utilisateur'=>$source->getUtilisateur()]);
                $rows = array_filter($rows, static fn(Emargement $e): bool => in_array($e->getRole(), ['trainer','formateur'], true));
                $conflict = false;
                foreach ($rows as $row) {
                    if ($em->getRepository(Emargement::class)->findOneBy(['session'=>$session, 'utilisateur'=>$target->getUtilisateur(), 'dateJour'=>$row->getDateJour(), 'periode'=>$row->getPeriode()])) $conflict = true;
                }
                if ($conflict) {
                    $form->addError(new FormError('Le remplaçant possède déjà des émargements sur ces demi-journées. Aucun document n’a été modifié.'));
                } else {
                    $em->wrapInTransaction(function () use ($em, $rows, $session, $source, $target, $entite, $data): void {
                        $ids = [];
                        foreach ($rows as $row) { $ids[] = $row->getId(); $row->setUtilisateur($target->getUtilisateur()); }
                        foreach ($session->getJours() as $jour) if ($jour->getFormateur()?->getId() === $source->getId()) $jour->setFormateur($target);
                        if ($session->getFormateur()?->getId() === $source->getId()) $session->setFormateur($target);
                        $em->persist((new AuditLog())->setEvent('trainer_attendance_attribution_corrected')->setEntite($entite)->setActor($this->getUser())->setCreateur($this->getUser())->setCreatedAt(new \DateTimeImmutable())->setPayload([
                            'sessionId'=>$session->getId(), 'previousTrainerId'=>$source->getId(), 'actualTrainerId'=>$target->getId(),
                            'previousUserId'=>$source->getUtilisateur()->getId(), 'actualUserId'=>$target->getUtilisateur()->getId(),
                            'attendanceIds'=>$ids, 'reason'=>$data['reason'],
                        ]));
                    });
                    $this->addFlash('success', 'Intervenant et attribution des émargements corrigés. Les contrats initiaux restent rattachés à la session, sans modification. Vous pouvez télécharger la feuille actualisée.');
                    return $this->redirectToRoute('app_administrateur_session_show', ['entite'=>$entite->getId(), 'id'=>$session->getId()]);
                }
            }
        }
        return $this->render('administrateur/session/correct_trainer.html.twig', ['entite'=>$entite, 'session'=>$session, 'form'=>$form->createView(), 'contracts'=>$contracts]);
    }
}
