<?php
namespace App\Command;
use App\Entity\Session;
use App\Enum\StatusSession;
use App\Service\Document\SessionCertificates;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;
#[AsCommand(name:'app:session-certificates:sync',description:'Créer les attestations manquantes des sessions terminées avec présences justifiées.')]
final class SyncSessionCertificatesCommand extends Command {
 public function __construct(private EntityManagerInterface $em,private SessionCertificates $certificates,private LockFactory $locks) { parent::__construct(); }
 protected function execute(InputInterface $input,OutputInterface $output): int {
  $lock=$this->locks->createLock('session-certificates-sync'); if (!$lock->acquire()) return Command::SUCCESS;
  try {
   $count=0;
   foreach ($this->em->getRepository(Session::class)->findBy(['status'=>StatusSession::DONE]) as $session) {
    foreach ($session->getInscriptions() as $i) if ($att=$this->certificates->prepare($i)) { $this->em->persist($att); ++$count; }
    $this->em->flush();
   }
   $output->writeln($count.' attestation(s) créée(s).'); return Command::SUCCESS;
  } finally { $lock->release(); }
 }
}
