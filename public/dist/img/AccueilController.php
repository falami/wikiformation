<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Entity\Expertise;
use App\Form\ExpertiseType;

use Symfony\Component\HttpFoundation\Request;

class AccueilController extends AbstractController
{
    /**
     * @Route("/", name="accueil")
     */
    public function index(Request $request): Response
    {


        // just setup a fresh $task object (remove the example data)
        $expertise = new Expertise();

        $form = $this->createForm(ExpertiseType::class, $expertise);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            // $form->getData() holds the submitted values
            // but, the original `$task` variable has also been updated
            $expertise = $form->getData();

            $revenuLocatif = $expertise->getRevenuLocatif();
            $tauxImposition = $expertise->getTauxImposition();
            $type = $expertise->getType(); 
            
            $baseTaxable1 = $revenuLocatif * 0.5;
            $baseTaxable2 = $revenuLocatif  * 0.7;


            if ($type == "France")
            {
                
                $csg1 = $baseTaxable1 * 0.186;
                $csg2 = $baseTaxable2 * 0.186;
                $economie1an = $csg2 - $csg1;
                $economie5an = $economie1an * 5;


                //dd($csg2);


                if ($tauxImposition != 0)
                {
                    $ir1 = $baseTaxable1 * ($tauxImposition/100);
                    $ir2 = $baseTaxable2 * ($tauxImposition/100);
                    $economie1anIr = $ir2 - $ir1;

                    $resultat1an = $economie1anIr + $economie1an;
                    $resultat5an = $resultat1an * 5;
                }else{
                    $resultat1an = $csg2 - $csg1;
                    $ir1 = 0;
                }
                return $this->render('accueil/resultat.html.twig', [
                    'resultat1an' => $resultat1an,
                    'revenuLocatif' => $revenuLocatif,
                    'tauxImposition' => $tauxImposition,
                    'csg1' => $csg1,
                    'ir1' => $ir1,
                    'type' => $type,
                ]);
                
            }else{
                $csg1 = $baseTaxable1 * 0.075;
                $csg2 = $baseTaxable2 * 0.075;
                $economie1an = $csg2 - $csg1;
                $economie5an = $economie1an * 5;


                if ($tauxImposition != 0)
                {
                    $ir1 = $baseTaxable1 * (20/100);
                    $ir2 = $baseTaxable2 * (20/100);
                    $economie1anIr = $ir2 - $ir1;

                    $resultat1an = $economie1anIr + $economie1an;
                    $resultat5an = $resultat1an * 5;
                }else{
                    $resultat1an = $csg2 - $csg1;
                    $ir1 = 0;
                }
                return $this->render('accueil/resultat.html.twig', [
                    'resultat1an' => $resultat1an,
                    'revenuLocatif' => $revenuLocatif,
                    'tauxImposition' => 20,
                    'csg1' => $csg1,
                    'ir1' => $ir1,
                    'type' => $type,
                ]);
            }


            
            
            //dd($resultat2an);



            // ... perform some action, such as saving the task to the database
            // for example, if Task is a Doctrine entity, save it!
            // $entityManager = $this->getDoctrine()->getManager();
            // $entityManager->persist($task);
            // $entityManager->flush();

            //return $this->redirectToRoute('task_success');
        }


        return $this->render('accueil/index.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
