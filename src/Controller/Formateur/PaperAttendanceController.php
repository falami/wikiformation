<?php
namespace App\Controller\Formateur;

use App\Entity\{Entite, Session, SessionPiece, Inscription};
use App\Enum\SessionPieceType;
use App\Service\AssiduiteCalculator;
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/formateur/{entite}/session/{id}/presences-papier', name: 'app_formateur_paper_', requirements: ['entite'=>'\d+', 'id'=>'\d+'])]
#[IsGranted(TenantPermission::FORMATEUR_ESPACE_MANAGE, subject: 'entite')]
final class PaperAttendanceController extends AbstractController
{
    public function __construct(#[Autowire('%session_piece_dir%')] private string $uploadDir) {}

    #[Route('', name: 'manage', methods: ['GET', 'POST'])]
    public function manage(Entite $entite, Session $session, Request $request, EntityManagerInterface $em, AssiduiteCalculator $calculator): Response
    {
        if ($session->getEntite()?->getId() !== $entite->getId() || !$session->hasFormateurUtilisateur($this->getUser())) throw $this->createAccessDeniedException();
        $pieces = $em->getRepository(SessionPiece::class)->findBy(['session'=>$session, 'entite'=>$entite, 'type'=>SessionPieceType::EMARGEMENT_SIGNE]);
        $rows = [];
        foreach ($session->getInscriptions() as $ins) {
            if ($ins->getEntite()?->getId() !== $entite->getId()) continue;
            $periods = array_filter($calculator->periods($ins), fn($p, $key) => $session->isFormateurUtilisateurSurPeriode($this->getUser(), new \DateTimeImmutable($p['date']), explode(':', $key)[1]), ARRAY_FILTER_USE_BOTH);
            $rows[$ins->getId()] = ['ins'=>$ins, 'periods'=>$periods, 'online'=>$calculator->online($ins)];
        }
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('trainer_paper_'.$session->getId(), $request->request->get('_token'))) throw $this->createAccessDeniedException('Formulaire expiré.');
            if ($request->request->get('action') === 'upload') {
                $file = $request->files->get('file');
                $mime = $file?->isValid() ? $file->getMimeType() : null;
                $ext = ['application/pdf'=>'pdf', 'image/jpeg'=>'jpg', 'image/png'=>'png'][$mime ?? ''] ?? null;
                if (!$ext || $file->getSize() > 15*1024*1024) {
                    $this->addFlash('danger', 'Choisissez un PDF, JPG ou PNG de 15 Mo maximum.');
                } else {
                    if (!is_dir($this->uploadDir)) mkdir($this->uploadDir, 0775, true);
                    $name = 'emargement-'.bin2hex(random_bytes(16)).'.'.$ext;
                    $file->move($this->uploadDir, $name);
                    $piece = (new SessionPiece())->setSession($session)->setEntite($entite)->setCreateur($this->getUser())->setType(SessionPieceType::EMARGEMENT_SIGNE)->setFilename($name)->setMimeType($mime);
                    $em->persist($piece); $em->flush();
                    $this->addFlash('success', 'Feuille déposée. Vous pouvez maintenant renseigner les présences.');
                }
            } else {
                $row = $rows[$request->request->getInt('inscription')] ?? null;
                $piece = null;
                foreach ($pieces as $candidate) if ($candidate->getId() === $request->request->getInt('piece')) $piece = $candidate;
                if (!$row || !$piece || !is_file($this->uploadDir.'/'.$piece->getFilename())) throw $this->createAccessDeniedException('Sélectionnez une feuille de cette session.');
                $submitted = $request->request->all('presence');
                $today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris')))->format('Y-m-d');
                foreach ($submitted as $key=>$status) {
                    if (!isset($row['periods'][$key]) || isset($row['online'][$key]) || $row['periods'][$key]['date'] > $today || !in_array($status, ['unknown','paper','absent'], true)) throw $this->createAccessDeniedException('Demi-journée non modifiable.');
                }
                foreach ($submitted as $key=>$status) $row['ins']->enregistrerPresenceManuelle($key, $status, 'Feuille session #'.$piece->getId().' · '.$piece->getFilename(), $this->getUser());
                $calculator->computeForInscription($row['ins']); $em->flush();
                $this->addFlash('success', 'Présences enregistrées et historisées.');
            }
            return $this->redirectToRoute('app_formateur_paper_manage', ['entite'=>$entite->getId(), 'id'=>$session->getId()]);
        }
        return $this->render('formateur/paper_attendance.html.twig', ['session'=>$session, 'entite'=>$entite, 'rows'=>$rows, 'pieces'=>$pieces]);
    }

    #[Route('/document/{piece}', name: 'document', methods: ['GET'])]
    public function document(Entite $entite, Session $session, SessionPiece $piece): Response
    {
        if ($session->getEntite()?->getId() !== $entite->getId() || !$session->hasFormateurUtilisateur($this->getUser()) || $piece->getSession()?->getId() !== $session->getId() || $piece->getEntite()?->getId() !== $entite->getId() || $piece->getType() !== SessionPieceType::EMARGEMENT_SIGNE) throw $this->createAccessDeniedException();
        return $this->file($this->uploadDir.'/'.$piece->getFilename());
    }
}
