<?php
declare(strict_types=1);
namespace App\Service\Recruitment;
use App\Entity\{TrainerApplication,RecruitmentPlacement,Entite,Utilisateur,UtilisateurEntite,Formateur,TrainerCv,TrainerProfile};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class Placement
{
    public function __construct(private EntityManagerInterface $em, private UserPasswordHasherInterface $hasher, private Applications $applications, #[Autowire('%kernel.project_dir%')] private string $root) {}
    /** Explicit super-admin operation. Existing user/profile information is never replaced. */
    public function transfer(TrainerApplication $application,array $entities,Utilisateur $actor,bool $confirmExisting=false): int
    {
        if(!$actor->isSuperAdmin()) throw new \LogicException('Action réservée au super-administrateur.');
        if(!$entities) throw new \InvalidArgumentException('Sélectionnez au moins une entité.');
        $copies=[];
        try {
            return $this->em->wrapInTransaction(function() use($application,$entities,$actor,$confirmExisting,&$copies) {
                $this->em->lock($application,LockMode::PESSIMISTIC_WRITE);
                $user=$application->account ?? $this->em->getRepository(Utilisateur::class)->findOneBy(['email'=>$application->email]);
                if($user && !$application->account && !$confirmExisting) throw new \InvalidArgumentException('Un compte porte déjà cet email. Vérifiez son identité puis confirmez le rattachement.');
                if(!$user) {
                    $user=(new Utilisateur())->setEmail($application->email)->setPrenom($application->firstName)->setNom($application->lastName)
                        ->setTelephone($application->phone)->setAdresse($application->details['address'])->setCodePostal($application->details['postalCode'])->setVille($application->details['city'])
                        ->setPays('France')->setSociete($application->details['company'])->setCreateur($actor)->setEntite(reset($entities));
                    $user->setPassword($this->hasher->hashPassword($user,bin2hex(random_bytes(32))));
                    $this->em->persist($user);
                }
                $count=0;
                foreach($entities as $entity) {
                    if(!$entity instanceof Entite) throw new \InvalidArgumentException('Entité invalide.');
                    if($this->em->getRepository(RecruitmentPlacement::class)->findOneBy(['application'=>$application,'entite'=>$entity])) continue;
                    $membership=$user->getId() ? $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur'=>$user,'entite'=>$entity]) : null;
                    if($membership && !$membership->isActive()) throw new \InvalidArgumentException('Ce compte dispose d’un accès suspendu ou invité dans '.$entity->getNom().'. Traitez cet accès avant le transfert.');
                    $membership ??= (new UtilisateurEntite())->setUtilisateur($user)->setEntite($entity)->setCreateur($actor)->setRoles([UtilisateurEntite::TENANT_FORMATEUR]);
                    $membership->setRoles(array_values(array_unique([...$membership->getRoles(),UtilisateurEntite::TENANT_FORMATEUR])));
                    $this->em->persist($membership);
                    $trainer=$user->getId() ? $this->em->getRepository(Formateur::class)->findOneBy(['utilisateur'=>$user,'entite'=>$entity]) : null;
                    if(!$trainer) {
                        $trainer=(new Formateur())->setUtilisateur($user)->setEntite($entity)->setCreateur($actor)
                            ->setAdresse($application->details['address'])->setCodePostal($application->details['postalCode'])->setVille($application->details['city'])->setPays('France')
                            ->setSiret($application->details['siret'])->setAssujettiTva($application->details['vat'])->setNumeroTvaIntra($application->details['vatNumber'] ?: null)
                            ->setTauxHoraireCents($application->hourlyRate)->setTauxJournalierCents($application->dailyRate)->setModeRemuneration($application->dailyRate ? 'JOUR' : 'HEURE')
                            ->setRecruitmentDetails(['company'=>$application->details['company'],'departments'=>$application->departments,'nationwide'=>$application->nationwide]);
                        $user->setFormateur($trainer);
                        $this->em->persist($trainer);
                        $profile=new TrainerProfile();$profile->formateur=$trainer;$profile->subjects=$application->subjects;$this->em->persist($profile);
                        $cv=new TrainerCv();$cv->formateur=$trainer;$cv->originalName=$application->cvOriginalName;
                        $cv->filename=$this->copy($application->cvFilename,'var/storage/trainer-cv',$copies);
                        $cv->content=\App\Service\TrainerProfile\CvImporter::normalize(['headline'=>'Formateur · '.implode(', ',array_slice($application->subjects,0,3)),'summary'=>$application->details['presentation']]);
                        $this->em->persist($cv);
                        if($application->photoFilename) $trainer->setPhoto($this->copy($application->photoFilename,'public/uploads/photos/formateur',$copies));
                    }
                    $placement=new RecruitmentPlacement();$placement->application=$application;$placement->entite=$entity;$placement->formateur=$trainer;$placement->actor=$actor;
                    $this->em->persist($placement); $count++;
                }
                $application->account=$user;$application->status='accepted';
                return $count;
            });
        } catch(\Throwable $e) { foreach($copies as $path) if(is_file($path)) unlink($path); throw $e; }
    }
    private function copy(string $source,string $directory,array &$copies): string
    {
        $dir=$this->root.'/'.$directory;
        if(!is_dir($dir)) mkdir($dir,0700,true);
        $name=bin2hex(random_bytes(24)).'.'.pathinfo($source,PATHINFO_EXTENSION);
        if(!copy($this->applications->directory().'/'.basename($source),$dir.'/'.$name)) throw new \RuntimeException('Impossible de transférer le document.');
        $copies[]=$dir.'/'.$name;return $name;
    }
}
