<?php
declare(strict_types=1);
namespace App\Controller\Marketplace;

use App\Entity\Marketplace\Invoice;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class InvoiceController extends AbstractController
{
    #[Route('/cours-en-ligne/factures',name:'app_market_invoices',methods:['GET'])]
    public function index(Request $request,EntityManagerInterface $em): Response
    {
        $page=max(1,$request->query->getInt('page',1));
        $qb=$em->createQueryBuilder()->select('i','p','s')->from(Invoice::class,'i')->join('i.purchase','p')->join('p.seller','s');
        if (!$this->isGranted('ROLE_SUPER_ADMIN')) $qb->where('s.user = :user OR (p.buyer = :user AND i.kind IN (:kinds))')->setParameter('user',$this->getUser())->setParameter('kinds',['sale','sale_credit']);
        $invoices=$qb->orderBy('i.id','DESC')->setFirstResult(($page-1)*30)->setMaxResults(31)->getQuery()->getResult();
        $response=$this->render('marketplace/invoices.html.twig',['invoices'=>array_slice($invoices,0,30),'page'=>$page,'next'=>count($invoices)>30]);
        $response->headers->set('Cache-Control','private, no-store');return $response;
    }
    #[Route('/cours-en-ligne/factures/{id}/pdf',name:'app_market_invoice_pdf',methods:['GET'],requirements:['id'=>'\d+'])]
    public function download(Invoice $invoice): Response
    {
        $p=$invoice->purchase;
        if (!$this->isGranted('ROLE_SUPER_ADMIN') && $p->seller->user!==$this->getUser() && !($p->buyer===$this->getUser() && str_starts_with($invoice->kind,'sale'))) throw $this->createNotFoundException();
        $bytes=base64_decode($invoice->pdf,true);
        if ($bytes===false || !hash_equals($invoice->sha256,hash('sha256',$bytes))) throw new \RuntimeException('Intégrité du document à vérifier.');
        return new Response($bytes,200,['Content-Type'=>'application/pdf','Content-Disposition'=>'attachment; filename="'.$invoice->number.'.pdf"','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
    #[Route('/cours-en-ligne/factures/{id}/contester',name:'app_market_invoice_contest',methods:['POST'],requirements:['id'=>'\d+'])]
    public function contest(Invoice $invoice,Request $request,EntityManagerInterface $em): Response
    {
        if ($invoice->purchase->seller->user!==$this->getUser() || $invoice->purchase->platformSale || !str_starts_with($invoice->kind,'sale')) throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('invoice_'.$invoice->id,$request->request->getString('_token'))) throw $this->createAccessDeniedException();
        $reason=trim($request->request->getString('reason'));
        if (mb_strlen($reason)<10 || mb_strlen($reason)>4000) $this->addFlash('danger','Décrivez la rectification demandée (10 à 4 000 caractères).');
        elseif (!$invoice->contestedAt) {
            $invoice->contestedAt=new \DateTimeImmutable();$invoice->contestation=$reason;$em->flush();$this->addFlash('success','Contestation enregistrée dans le journal super administrateur. Le document original est conservé.');
        }
        return $this->redirectToRoute('app_market_invoices');
    }
}
