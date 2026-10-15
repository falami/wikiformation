<?php
declare(strict_types=1);
namespace App\Controller\Administrateur;
use App\Entity\{Entite,ExpenseImportRule,DepenseCategorie,DepenseFournisseur};
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
final class ExpenseImportRuleController extends AbstractController
{
    #[Route('/administrateur/{entite}/depense/regles-import',name:'app_expense_import_rules',methods:['GET','POST'])]
    #[IsGranted(TenantPermission::DEPENSE_MANAGE,subject:'entite')]
    public function index(Entite $entite,Request $request,EntityManagerInterface $em): Response
    {
        $edit=null;
        $id=$request->isMethod('POST')?$request->request->getInt('id'):$request->query->getInt('edit');
        if ($id) { $edit=$em->find(ExpenseImportRule::class,$id); if (!$edit || $edit->entite->getId()!==$entite->getId()) throw $this->createNotFoundException(); }
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('expense_rules_'.$entite->getId(),$request->request->getString('_token'))) throw $this->createAccessDeniedException();
            if ($request->request->getString('action')==='delete') {
                if (!$edit) throw $this->createNotFoundException();
                $em->remove($edit);$em->flush();$this->addFlash('success','Règle supprimée.');
            } else {
                $keyword=trim($request->request->getString('keyword'));
                $category=$em->find(DepenseCategorie::class,$request->request->getInt('category'));
                $supplier=$em->find(DepenseFournisseur::class,$request->request->getInt('supplier'));
                $pct=filter_var(str_replace(',','.',$request->request->getString('percentage')),FILTER_VALIDATE_FLOAT);
                $priority=filter_var($request->request->getString('priority'),FILTER_VALIDATE_INT);
                if (!$keyword || mb_strlen($keyword)>180 || !\App\Service\Depense\ExpenseImportRules::normalize($keyword) || !$category || !$supplier || $category->getEntite()?->getId()!==$entite->getId() || $supplier->getEntite()?->getId()!==$entite->getId() || $pct===false || $pct<0 || $pct>100 || $priority===false || $priority<0 || $priority>9999) {
                    $this->addFlash('danger','Vérifiez le libellé, la catégorie, le fournisseur, la priorité et le pourcentage (0 à 100).');
                    return $this->render('administrateur/depense/rules.html.twig',$this->data($em,$entite,$edit)+['submitted'=>$request->request->all()],new Response(status:422));
                }
                $rule=$edit??new ExpenseImportRule();$rule->entite=$entite;$rule->keyword=$keyword;$rule->category=$category;$rule->supplier=$supplier;$rule->percentage=$pct;$rule->priority=$priority;$rule->deductible=$request->request->getBoolean('deductible');$rule->enabled=$request->request->getBoolean('enabled');
                $em->persist($rule);$em->flush();$this->addFlash('success','Règle enregistrée pour les prochains aperçus d’import.');
            }
            return $this->redirectToRoute('app_expense_import_rules',['entite'=>$entite->getId()]);
        }
        return $this->render('administrateur/depense/rules.html.twig',$this->data($em,$entite,$edit));
    }
    #[Route('/administrateur/{entite}/depense/regles-import/classement/{kind}',name:'app_expense_rule_choice_create',methods:['POST'],requirements:['kind'=>'category|supplier'])]
    #[IsGranted(TenantPermission::DEPENSE_MANAGE,subject:'entite')]
    public function createChoice(Entite $entite,string $kind,Request $request,EntityManagerInterface $em): Response {
        if (!$this->isCsrfTokenValid('expense_rules_'.$entite->getId(),$request->request->getString('_token'))) throw $this->createAccessDeniedException();
        $name=trim($request->request->getString('name'));
        if ($name==='' || mb_strlen($name)>($kind==='category'?120:160)) return $this->json(['message'=>'Renseignez un nom de longueur valide.'],422);
        $class=$kind==='category'?DepenseCategorie::class:DepenseFournisseur::class;
        $field=$kind==='category'?'libelle':'nom';
        $existing=$em->getRepository($class)->findOneBy(['entite'=>$entite,$field=>$name]);
        if ($existing) return $this->json(['id'=>$existing->getId(),'text'=>$name,'created'=>false]);
        $choice=$kind==='category'?(new DepenseCategorie())->setEntite($entite)->setLibelle($name):(new DepenseFournisseur())->setEntite($entite)->setNom($name);
        $em->persist($choice);
        try { $em->flush(); } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            return $this->json(['message'=>'Ce nom vient déjà d’être ajouté. Rechargez la liste avant de réessayer.'],409);
        }
        return $this->json(['id'=>$choice->getId(),'text'=>$name,'created'=>true],201);
    }
    private function data(EntityManagerInterface $em,Entite $entite,?ExpenseImportRule $edit): array {
        return ['entite'=>$entite,'edit'=>$edit,'rules'=>$em->getRepository(ExpenseImportRule::class)->findBy(['entite'=>$entite],['priority'=>'ASC','id'=>'ASC']),'categories'=>$em->getRepository(DepenseCategorie::class)->findBy(['entite'=>$entite]),'suppliers'=>$em->getRepository(DepenseFournisseur::class)->findBy(['entite'=>$entite])];
    }
}
