<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__,2).'/.env');
$kernel=new App\Kernel('dev',true); $kernel->boot();
$c=$kernel->getContainer(); $em=$c->get('doctrine')->getManager();
$r=$em->getRepository(App\Entity\HabilitationRevision::class)->createQueryBuilder('r')->where('r.token LIKE :token')->setParameter('token','7c4c43e3%')->getQuery()->getOneOrNullResult();
if(!$r)throw new RuntimeException('Revision introuvable');
$brand=(new App\Service\Pdf\OrganisationBranding(dirname(__DIR__,2)))->forEntity($r->getDossier()->getEntite());
$pdf=new Dompdf\Dompdf(new Dompdf\Options(['isRemoteEnabled'=>false,'isPhpEnabled'=>false,'defaultFont'=>'DejaVu Sans']));$pdf->setPaper('A4');
$pdf->loadHtml((new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__,2).'/templates')))->render('habilitation/attestation.html.twig',['revision'=>$r,'snapshot'=>$r->getSnapshot(),'brand'=>$brand,'archived'=>false,'inactive'=>false]));$pdf->render();
$pdf->getCanvas()->page_text(500,811,'{PAGE_NUM} / {PAGE_COUNT}',$pdf->getFontMetrics()->getFont('DejaVu Sans'),8,[.4,.45,.5]);
file_put_contents(dirname(__DIR__,2).'/output/pdf/attestation-electrique-apercu.pdf',$pdf->output());
echo 'Pages: '.$pdf->getCanvas()->get_page_count().' Logo: '.($brand['logo']?'oui':'non').' Couleurs: '.$brand['primary'].' '.$brand['accent'].PHP_EOL;
