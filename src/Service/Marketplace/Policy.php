<?php
namespace App\Service\Marketplace;
use App\Entity\{Entite,Utilisateur,UtilisateurEntite};
use App\Entity\Elearning\ElearningCourse;
use App\Entity\Marketplace\{Settings,Seller};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
final class Policy {
 public function __construct(private EntityManagerInterface $em) {}
 public function settings(): Settings { return $this->em->find(Settings::class,1) ?? new Settings(); }
 public function platformSeller(Utilisateur $user): bool { return in_array('ROLE_SUPER_ADMIN',$user->getRoles(),true); }
 public function trainer(Utilisateur $user,Entite $entite): void {
  if ($this->platformSeller($user)) return;
  $m=$this->em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur'=>$user,'entite'=>$entite]);
  if (!$m || !$m->isActive() || !$m->hasRole(UtilisateurEntite::TENANT_FORMATEUR)) throw new AccessDeniedException('Cet espace est réservé aux formateurs rattachés à cet organisme.');
 }
 public function owns(Utilisateur $user,Entite $entite,ElearningCourse $course): void {
  $this->trainer($user,$entite);
  if ($course->getEntite()?->getId()!==$entite->getId() || $course->getCreateur()?->getId()!==$user->getId()) throw new AccessDeniedException('Ce cours ne vous appartient pas dans cet espace.');
 }
 public function learners(Entite $entite): array {
  return array_values(array_filter($this->em->getRepository(UtilisateurEntite::class)->findBy(['entite'=>$entite]),fn($m)=>$m->isActive() && $m->hasRole(UtilisateurEntite::TENANT_STAGIAIRE)));
 }
 public function seller(Utilisateur $user): Seller {
  $seller=$this->em->getRepository(Seller::class)->findOneBy(['user'=>$user]);
  if (!$seller) { $seller=new Seller(); $seller->user=$user; $seller->email=$user->getEmail(); $this->em->persist($seller); $this->em->flush(); }
  return $seller;
 }
}
