<?php
declare(strict_types=1);
namespace App\Controller\Administrateur;
use App\Entity\{Entite,DocumentTemplate,DocumentTemplateBinding};
use App\Service\DocumentStudio\{Catalog,Content,Preview,Renderer};
use App\Service\Pdf\PdfManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response,JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;

#[Route('/administrateur/{entite}/documents/modeles',name:'app_administrateur_document_studio_',requirements:['entite'=>'\d+'])]
#[IsGranted(TenantPermission::ENTITE_PREFERENCE_MANAGE,subject:'entite')]
final class DocumentStudioController extends AbstractController
{
    public function __construct(private EntityManagerInterface $em,private Content $content,private Preview $preview,private Renderer $renderer,private PdfManager $pdf) {}
    #[Route('',name:'index',methods:['GET'])]
    public function index(Entite $entite): Response {
        $bindings=[];foreach($this->em->getRepository(DocumentTemplateBinding::class)->findBy(['entite'=>$entite]) as $binding)$bindings[$binding->getKind()]=$binding;
        return $this->render('administrateur/document_studio/index.html.twig',['entite'=>$entite,'section'=>'documents','catalog'=>Catalog::TYPES,'models'=>$this->em->getRepository(DocumentTemplate::class)->findBy(['entite'=>$entite],['updatedAt'=>'DESC']),'bindings'=>$bindings]);
    }
    #[Route('/create',name:'create',methods:['POST'])]
    public function create(Entite $entite,Request $request): Response {
        $this->csrf($request,$entite);
        $kind=$request->request->getString('kind');Catalog::requireKind($kind);
        $config=Catalog::defaults();$name=Catalog::TYPES[$kind]['label'].' · Ma version';
        if($id=$request->request->getInt('source')) {
            $source=$this->model($entite,$id);
            if($source->getKind()!==$kind)throw $this->createNotFoundException();
            $config=$source->getConfig();$name=mb_substr($source->getName(),0,140).' · Copie';
        }
        if ($request->request->getString('base')==='blank' && !$request->request->getInt('source')) {
            $config['base']='blank';$name=Catalog::TYPES[$kind]['label'].' · Modèle vierge';
        }
        $model=new DocumentTemplate($entite,$kind,$name,$config);$this->em->persist($model);$this->em->flush();
        return $this->redirectToRoute('app_administrateur_document_studio_edit',['entite'=>$entite->getId(),'id'=>$model->getId()]);
    }
    #[Route('/{id}/edit',name:'edit',methods:['GET'],requirements:['id'=>'\d+'])]
    public function edit(Entite $entite,int $id): Response {
        $model=$this->model($entite,$id);
        return $this->render('administrateur/document_studio/editor.html.twig',['entite'=>$entite,'model'=>$model,'type'=>Catalog::TYPES[$model->getKind()],'fields'=>Catalog::FIELDS,'sections'=>Catalog::SECTIONS[$model->getKind()] ?? [],'sources'=>$this->preview->sources($entite,$model->getKind()),'binding'=>$this->em->getRepository(DocumentTemplateBinding::class)->findOneBy(['entite'=>$entite,'kind'=>$model->getKind()])]);
    }
    #[Route('/{id}/save',name:'save',methods:['POST'],requirements:['id'=>'\d+'])]
    public function save(Entite $entite,int $id,Request $request): Response {
        $this->csrf($request,$entite);$model=$this->model($entite,$id);
        try {
            $data=$request->toArray();$this->version($model,$data);
            $name=trim((string)($data['name'] ?? ''));
            if(!$name || mb_strlen($name)>160)throw new \DomainException('Donnez un nom au modèle (160 caractères maximum).');
            $config=$this->content->normalize($data['config'] ?? [],$model->getKind());
            $model->save($name,$config);$this->em->flush();
            return $this->json(['version'=>$model->getVersion(),'message'=>'Brouillon enregistré']);
        } catch(\DomainException $e) { return $this->json(['error'=>$e->getMessage()],422); }
        catch(\Doctrine\ORM\OptimisticLockException $e) { return $this->json(['error'=>'Ce modèle a été modifié ailleurs. Rechargez la page.'],409); }
    }
    #[Route('/{id}/preview',name:'preview',methods:['POST'],requirements:['id'=>'\d+'])]
    public function preview(Entite $entite,int $id,Request $request): Response {
        $this->csrf($request,$entite);$model=$this->model($entite,$id);
        try {
            $data=$request->toArray();$config=$this->content->normalize($data['config'] ?? [],$model->getKind());
            [$html,$context]=$this->preview->source($entite,$model->getKind(),(int)($data['source'] ?? 0),$request);
            $html=$this->renderer->render($html,$model->getKind(),$config,$context);
            $html=preg_replace('~<body([^>]*)>~i','<body$1><div style="position:fixed;top:-15pt;right:0;font-size:8pt;color:#798999">APERÇU DE MODÈLE - NON CONTRACTUEL</div>',$html,1);
            $response=$model->getKind()==='emargement_sheet'?$this->pdf->createLandscape($html,'Apercu'):$this->pdf->createPortrait($html,'Apercu');
            $response->headers->set('Content-Disposition','inline; filename="Apercu-modele.pdf"');$response->headers->set('Cache-Control','private, no-store');
            return $response;
        } catch(\DomainException $e) { return $this->json(['error'=>$e->getMessage()],422); }
    }
    #[Route('/{id}/publish',name:'publish',methods:['POST'],requirements:['id'=>'\d+'])]
    public function publish(Entite $entite,int $id,Request $request): Response {
        $this->csrf($request,$entite);$model=$this->model($entite,$id);
        $data=$request->toArray();
        try {
            $this->em->wrapInTransaction(function()use($entite,$model,$data) {
                if (!$this->em->getConnection()->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform) $this->em->lock($entite,\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                $this->version($model,$data);
                $model->recordPublication($this->getUser()->getUserIdentifier());
                $binding=$this->em->getRepository(DocumentTemplateBinding::class)->findOneBy(['entite'=>$entite,'kind'=>$model->getKind()]);
                if($binding)$binding->publish($model);else $this->em->persist(new DocumentTemplateBinding($model));
            });
            return $this->json(['version'=>$model->getVersion(),'message'=>'Version activée pour les prochains documents']);
        } catch(\DomainException|\Doctrine\ORM\OptimisticLockException $e) { return $this->json(['error'=>'Le modèle a changé. Rechargez la page avant de l’activer.'],409); }
    }
    #[Route('/{id}/restore/{revision}',name:'restore',methods:['POST'],requirements:['id'=>'\d+','revision'=>'\d+'])]
    public function restore(Entite $entite,int $id,int $revision,Request $request): Response {
        $this->csrf($request,$entite);$model=$this->model($entite,$id);$entry=$model->getHistory()[$revision] ?? null;
        if(!$entry)throw $this->createNotFoundException();
        try { $this->version($model,['version'=>$request->request->getInt('version')]); }
        catch(\DomainException|\Doctrine\ORM\OptimisticLockException $e) { throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('Le brouillon a changé. Rechargez la page avant de restaurer une version.'); }
        $model->save($entry['name'],$entry['config']);$this->em->flush();
        $this->addFlash('success','Version restaurée en brouillon. La version active reste inchangée.');
        return $this->redirectToRoute('app_administrateur_document_studio_edit',['entite'=>$entite->getId(),'id'=>$id]);
    }
    #[Route('/default/{kind}',name:'default',methods:['POST'])]
    public function useDefault(Entite $entite,string $kind,Request $request): Response {
        $this->csrf($request,$entite);Catalog::requireKind($kind);
        $binding=$this->em->getRepository(DocumentTemplateBinding::class)->findOneBy(['entite'=>$entite,'kind'=>$kind]);
        if($binding){$this->em->remove($binding);$this->em->flush();}
        $this->addFlash('success','Le modèle d’origine est de nouveau utilisé.');
        return $this->redirectToRoute('app_administrateur_document_studio_index',['entite'=>$entite->getId()]);
    }
    #[Route('/{id}/delete',name:'delete',methods:['POST'],requirements:['id'=>'\d+'])]
    public function delete(Entite $entite,int $id,Request $request): Response {
        $this->csrf($request,$entite);
        $this->em->wrapInTransaction(function()use($entite,$id) {
            if (!$this->em->getConnection()->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform) $this->em->lock($entite,\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
            $model=$this->model($entite,$id);$this->em->refresh($model);
            if ($model->getHistory() || $this->em->getRepository(DocumentTemplateBinding::class)->findOneBy(['template'=>$model])) throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('Un modèle déjà activé ne peut pas être supprimé.');
            $this->em->remove($model);
        });
        $this->addFlash('success','Le modèle inutilisé a été supprimé.');
        return $this->redirectToRoute('app_administrateur_document_studio_index',['entite'=>$entite->getId()]);
    }
    private function model(Entite $tenant,int $id): DocumentTemplate { return $this->em->getRepository(DocumentTemplate::class)->findOneBy(['id'=>$id,'entite'=>$tenant]) ?? throw $this->createNotFoundException(); }
    private function csrf(Request $request,Entite $tenant): void {
        if(strlen($request->getContent())>220000)throw $this->createAccessDeniedException('Document trop volumineux.');
        if(!$this->isCsrfTokenValid('document_studio_'.$tenant->getId(),$request->headers->get('X-CSRF-Token') ?? $request->request->getString('_token')))throw $this->createAccessDeniedException();
    }
    private function version(DocumentTemplate $model,array $data): void {
        if((int)($data['version'] ?? 0)!==$model->getVersion())throw new \DomainException('Ce modèle a été modifié dans une autre fenêtre. Rechargez la page.');
        $this->em->lock($model,\Doctrine\DBAL\LockMode::OPTIMISTIC,$model->getVersion());
    }
}
