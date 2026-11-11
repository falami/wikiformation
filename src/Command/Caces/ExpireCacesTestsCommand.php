<?php
declare(strict_types=1);
namespace App\Command\Caces;
use App\Entity\CacesAttempt;
use App\Service\Caces\CacesWorkflow;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name:'app:caces:expire',description:'Finalize expired CACES theory tests using only previously saved answers.')]
final class ExpireCacesTestsCommand extends Command
{
    public function __construct(private EntityManagerInterface $em,private CacesWorkflow $workflow){parent::__construct();}
    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        $now=new \DateTimeImmutable();$ids=$this->em->createQueryBuilder()->select('a.id')->from(CacesAttempt::class,'a')->where('a.startedAt <= :before AND a.submittedAt IS NULL')->setParameter('before',$now->modify('-60 minutes'))->getQuery()->getSingleColumnResult();$count=0;
        foreach($ids as $id)$this->em->wrapInTransaction(function()use($id,$now,&$count){$a=$this->em->find(CacesAttempt::class,$id,LockMode::PESSIMISTIC_WRITE);if($a){$this->em->refresh($a);if($this->workflow->expire($a,$now))++$count;}});
        $output->writeln($count.' test(s) clôturé(s).');return Command::SUCCESS;
    }
}
