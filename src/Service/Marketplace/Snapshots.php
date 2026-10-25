<?php
namespace App\Service\Marketplace;
use App\Entity\Elearning\ElearningCourse;
use App\Service\DocumentStudio\Content;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
final class Snapshots {
 public function __construct(private Content $html, #[Autowire('%kernel.project_dir%')] private string $root) {}
 public function capture(ElearningCourse $course, bool $paid = true): array {
  $nodes=[];$ordered=[];$children=[];$seen=[];
  foreach ($course->getNodes() as $node) $children[$node->getParent()?->getId() ?? 0][]=$node;
  $visit=function(int $parent) use (&$visit,&$ordered,&$children,&$seen): void {
   foreach ($children[$parent] ?? [] as $node) {
    if (isset($seen[$node->getId()])) throw new \DomainException('La structure du cours contient une boucle.');
    $seen[$node->getId()]=true;$ordered[]=$node;$visit($node->getId());
   }
  };
  $visit(0);
  if (count($ordered)!==count($course->getNodes())) throw new \DomainException('Vérifiez le rattachement des chapitres avant la publication.');
  foreach ($ordered as $node) {
   if (!$node->isPublished()) continue;
   for ($parent=$node->getParent();$parent;$parent=$parent->getParent()) if (!$parent->isPublished()) continue 2;
   $blocks=[];
   foreach ($node->getBlocks() as $block) {
    $type=$block->getType()->value; $file=null; $url=$block->getMediaUrl();
    if (in_array($type,['image','video','fichier'],true) && !$block->getMediaFilename() && $paid) throw new \DomainException('Pour préserver les achats, importez les médias du cours au lieu de liens externes avant sa mise en vente.');
    if ($filename=$block->getMediaFilename()) {
     $source=realpath($this->root.'/public/uploads/elearning/'.$filename);
     $base=realpath($this->root.'/public/uploads/elearning');
     if (!$source || !$base || !str_starts_with($source,$base.DIRECTORY_SEPARATOR) || !is_file($source)) throw new \DomainException('Un fichier du cours est introuvable.');
     $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($source);
     if (!in_array($mime,['application/pdf','image/jpeg','image/png','image/webp','video/mp4','video/webm','audio/mpeg','text/plain'],true)) throw new \DomainException('Format de document non pris en charge dans le catalogue. Utilisez PDF, image, MP4 ou WebM.');
     $key=hash_file('sha256',$source);$dir=$this->root.'/var/marketplace/media';
     if (!is_dir($dir)) mkdir($dir,0700,true);
     if (!is_file($dir.'/'.$key) && !copy($source,$dir.'/'.$key)) throw new \RuntimeException('Archivage du média impossible.');
     $file=['key'=>$key,'name'=>basename($filename),'mime'=>$mime];
    }
    $quiz=[];
    foreach ($block->getQuiz()?->getQuestions() ?? [] as $question) {
     $choices=[];foreach ($question->getChoices() as $choice) $choices[]=['label'=>$choice->getLabel(),'correct'=>$choice->isCorrect()];
     $quiz[]=['text'=>$question->getText(),'choices'=>$choices,'expected'=>$question->getExpectedText(),'explanation'=>$question->getExplanation()];
    }
    $blocks[]=['type'=>$type,'html'=>$this->html->sanitize($block->getContent() ?? ''),'file'=>$file,'url'=>$url && filter_var($url,FILTER_VALIDATE_URL) && str_starts_with($url,'https://') ? $url : null,'quiz'=>$quiz];
   }
   $nodes[]=['id'=>$node->getId(),'title'=>$node->getTitre(),'parent'=>$node->getParent()?->getId(),'blocks'=>$blocks];
  }
  if (!$nodes || !array_filter($nodes,fn($n)=>count($n['blocks'])>0)) throw new \DomainException('Publiez au moins un chapitre contenant votre cours avant de le proposer.');
  return ['title'=>$course->getTitre(),'description'=>strip_tags($course->getDescription() ?? ''),'minutes'=>$course->getDureeMinutes(),'nodes'=>$nodes,'capturedAt'=>(new \DateTimeImmutable())->format(DATE_ATOM)];
 }
 public function media(string $key): string {
  if (!preg_match('/^[a-f0-9]{64}$/D',$key)) throw new \InvalidArgumentException();
  return $this->root.'/var/marketplace/media/'.$key;
 }
}
