<?php

declare(strict_types=1);
namespace App\Service\Caces;
use App\Entity\{CacesExam,CacesTemplate,Entite};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class CacesLibrary
{
    public function __construct(private EntityManagerInterface $em, #[Autowire('%kernel.project_dir%')] private string $root) {}
    public function official(): array
    {
        $items=json_decode(file_get_contents($this->root.'/resources/caces/catalog.json'),true,512,JSON_THROW_ON_ERROR);
        // Keep the original key readable for existing snapshots and copied models.
        foreach($items as &$item) if($item['key']==='r489-cat3-pratique-202607') $item['status']='archived';
        unset($item);
        return array_merge($items,json_decode(file_get_contents($this->root.'/resources/caces/practice-2026.json'),true,512,JSON_THROW_ON_ERROR));
    }
    public function catalog(Entite $entite): array
    {
        $items=$this->official();
        foreach ($this->em->getRepository(CacesTemplate::class)->createQueryBuilder('t')->where('t.entite = :e OR t.entite IS NULL')->setParameter('e',$entite)->orderBy('t.id','DESC')->getQuery()->getResult() as $t) $items[]=$this->view($t);
        // Central revisions retire their predecessor from future random draws only.
        $retired=[];
        foreach($items as $item) if(($item['global']??false) && ($item['status']??'active')!=='draft' && isset($item['schema']['supersedes'])) $retired[]=$item['schema']['supersedes'];
        foreach($items as &$item) if(in_array($item['key'],$retired,true))$item['status']='archived';
        unset($item);
        return $items;
    }
    public function view(CacesTemplate $t): array { return ['key'=>'custom-'.$t->getId(),'global'=>$t->getEntite()===null,'title'=>$t->getTitle(),'recommendation'=>$t->getRecommendation(),'category'=>$t->getCategory(),'kind'=>$t->getKind(),'versionLabel'=>$t->getVersionLabel(),'status'=>$t->getStatus(),'schema'=>$t->getSchema()]; }
    public function find(Entite $entite,string $key): array
    {
        foreach ($this->catalog($entite) as $item) if ($item['key']===$key) return $item;
        throw new \InvalidArgumentException('Modèle introuvable.');
    }
    /** Snapshots are retained even before a candidate starts an evaluation. */
    public function protectedKeys(): array
    {
        $keys = [];
        foreach ($this->em->createQueryBuilder()->select('e.theorySnapshot', 'e.practiceSnapshot')->from(CacesExam::class, 'e')->getQuery()->toIterable() as $exam) {
            foreach ($exam as $snapshot) {
                if (isset($snapshot['key'])) $keys[$snapshot['key']] = true;
            }
        }
        foreach ($this->em->createQueryBuilder()->select('t.schema')->from(CacesTemplate::class, 't')->getQuery()->toIterable() as $row) {
            if (isset($row['schema']['supersedes'])) $keys[$row['schema']['supersedes']] = true;
        }
        return $keys;
    }

    public function image(string $key,int $page): string
    {
        foreach($this->official() as $item) {
            if($item['key']!==$key || $item['kind']!=='practice' || !isset($item['schema']['reference'])) continue;
            $reference=$item['schema']['reference'];
            if(!in_array($page,$reference['pages'],true)) throw new \InvalidArgumentException('Page introuvable.');
            $path=$this->root.'/resources/caces/reference-'.pathinfo($reference['file'],PATHINFO_FILENAME).'/page-'.$page.'.png';
            if(!is_file($path)) throw new \InvalidArgumentException('Page introuvable.');
            return $path;
        }
        if (!preg_match('/^r(482|483|484|485|486|487|489|490)-v1-202412-g[1-5]$/D',$key) || $page<1 || $page>20) throw new \InvalidArgumentException('Page introuvable.');
        $path=$this->root.'/resources/caces/'.$key.'/page-'.$page.'.png';
        if (!is_file($path)) throw new \InvalidArgumentException('Page introuvable.');
        return $path;
    }
}
