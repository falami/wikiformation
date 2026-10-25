<?php
namespace App\EventSubscriber;
use App\Entity\{Entite,Utilisateur};
use App\Entity\Elearning\{ElearningCourse,ElearningNode,ElearningBlock};
use App\Service\Marketplace\Policy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
#[AsEventListener(event:'kernel.controller')]
final class MarketplaceEditorGuard {
 public function __construct(private Security $security, private EntityManagerInterface $em,private Policy $policy) {}
 public function __invoke(ControllerEvent $event): void {
  $r=$event->getRequest(); $route=(string)$r->attributes->get('_route');
  $editor=str_starts_with($route,'app_administrateur_elearning_content_');
  if (!$editor && !str_starts_with($route,'app_administrateur_elearning_enrollment_')) return;
  $course=$r->attributes->get('course'); if (!$course instanceof ElearningCourse) $course=$this->em->find(ElearningCourse::class,$course);
  $entite=$r->attributes->get('entite'); if (!$entite instanceof Entite) $entite=$this->em->find(Entite::class,$entite);
  $user=$this->security->getUser();
  if (!$course || !$entite || !$user instanceof Utilisateur || $course->getEntite()?->getId()!==$entite->getId()) throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
  if ($editor && !$this->security->isGranted('ENTITE_ADMIN',$entite)) $this->policy->owns($user,$entite,$course);
  // Check nested resources before rendering forms, not only after a valid POST.
  foreach (['node'=>ElearningNode::class,'block'=>ElearningBlock::class] as $name=>$class) {
   $value=$r->attributes->get($name);
   if ($value===null) continue;
   $item=$value instanceof $class ? $value : $this->em->find($class,$value);
   $owner=$item instanceof ElearningBlock ? $item->getNode()?->getCourse() : $item?->getCourse();
   if (!$owner || $owner->getId()!==$course->getId()) throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
  }
 }
}
