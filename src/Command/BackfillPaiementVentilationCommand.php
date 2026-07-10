<?php
// src/Command/BackfillPaiementVentilationCommand.php
namespace App\Command;

use App\Entity\Paiement;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:paiement:backfill-ventilation')]
final class BackfillPaiementVentilationCommand extends Command
{
  public function __construct(private EM $em)
  {
    parent::__construct();
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $repo = $this->em->getRepository(Paiement::class);

    // On prend uniquement ceux non ventilés
    $q = $repo->createQueryBuilder('p')
      ->leftJoin('p.facture', 'f')->addSelect('f')
      ->andWhere('p.facture IS NOT NULL')
->andWhere('p.ventilationSource IS NULL OR p.ventilationSource = :auto')
->setParameter('auto', 'facture_auto')
      ->orderBy('p.id', 'ASC')
      ->getQuery();

    $i = 0;
    foreach ($q->toIterable() as $p) {
      /** @var Paiement $p */
      $this->hydrateVentilation($p); // recopie la même méthode que dans ton controller (ou mieux : dans un service)
      $i++;

      if (($i % 200) === 0) {
        $this->em->flush();
        $this->em->clear();
        $output->writeln("... $i paiements ventilés");
      }
    }

    $this->em->flush();
    $output->writeln("OK : $i paiements ventilés.");
    return Command::SUCCESS;
  }

  private function hydrateVentilation(Paiement $p): void
  {
      $paidTtc = (int) ($p->getMontantCents() ?? 0);
      $f = $p->getFacture();

      if (!$f || $paidTtc <= 0) {
          $p->setVentilationSource('non_ventile');
          $p->setVentilationHtHorsDeboursCents(null);
          $p->setVentilationTvaHorsDeboursCents(null);
          $p->setVentilationDeboursCents(null);
          return;
      }

      $ttcTotal = (int) $f->getTtcTotalCents();

      $ttcHd = (int) $f->getMontantTtcHorsDeboursCents();
      $htHd  = (int) $f->getMontantHtHorsDeboursCents();

      $debHt  = (int) $f->getMontantDeboursHtCents();
      $debTva = (int) $f->getMontantDeboursTvaCents();
      $debTtc = $debHt + $debTva;

      if ($ttcTotal <= 0) {
          $p->setVentilationSource('facture_auto');
          $p->setVentilationHtHorsDeboursCents(0);
          $p->setVentilationTvaHorsDeboursCents(0);
          $p->setVentilationDeboursCents(0);
          return;
      }

      $paidTtcHd  = $ttcHd > 0 ? (int) round($paidTtc * ($ttcHd / $ttcTotal)) : 0;
      $paidTtcDeb = max(0, $paidTtc - $paidTtcHd);

      $paidHtHd  = $ttcHd > 0 ? (int) round($paidTtcHd * ($htHd / $ttcHd)) : 0;
      $paidTvaHd = max(0, $paidTtcHd - $paidHtHd);

      $paidDebHt  = $debTtc > 0 ? (int) round($paidTtcDeb * ($debHt / $debTtc)) : 0;
      $paidDebTva = max(0, $paidTtcDeb - $paidDebHt);

      $p->setVentilationSource('facture_auto');
      $p->setVentilationHtHorsDeboursCents($paidHtHd);              // CA
      $p->setVentilationTvaHorsDeboursCents($paidTvaHd + $paidDebTva); // TVA totale
      $p->setVentilationDeboursCents($paidDebHt);                   // débours HT
  }
}
