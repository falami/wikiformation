<?php
// Preview only: no persistence, no database queries, fixtures transcribed from supplied PDFs.
require dirname(__DIR__, 2).'/vendor/autoload.php';
use App\Entity\{Devis, Facture, Entite, Entreprise, LigneDevis, LigneFacture};
use App\Service\Billing\{InvoiceSnapshot, InvoiceTotals};
$root = dirname(__DIR__, 2);
(new Symfony\Component\Dotenv\Dotenv())->bootEnv($root.'/.env');
$kernel = new App\Kernel('test', true); $kernel->boot();
$twig = $kernel->getContainer()->get('test.service_container')->get('twig');
$pdf = new App\Service\Pdf\PdfManager($twig, $root.'/output/pdf');
$issuer = (new Entite())->setNom('AMF Conseil & Formation')->setVille('Lunel')->setTelephone('+33626252568')->setEmail('amartinflorian@gmail.com')->setSiret('10764660600015')->setCouleurPrincipal('#203747')->setCouleurSecondaire('#b29352');
$quote = (new Devis())->setEntite($issuer)->setNumero('DEV-E7-2026-0001')->setDateEmission(new DateTimeImmutable('2026-11-13'))->setDateValidite(new DateTimeImmutable('2026-12-13'))->setDevise('EUR')->setStatus(App\Enum\DevisStatus::SENT)->setEntrepriseDestinataire((new Entreprise())->setRaisonSociale('CYRENE'))->setMontantHtCents(19500)->setMontantTvaCents(0)->setMontantTtcCents(19500);
$descriptions = [
<<<'TEXT'
Accompagnement administratif initial – par dossier de formation (1h incluse)
- Vérification des informations administratives de l'entreprise et des participants.
- Contrôle de la complétude du dossier de formation.
- Vérification des conventions, convocations et documents administratifs.
- Assistance à la constitution et à l'organisation du dossier.
- Identification & relances des pièces manquantes.
- Etc.

Forfait comprenant jusqu'à 1 heure d'intervention par dossier. Toute intervention supplémentaire fait l'objet d'une facturation horaire complémentaire.
Les prestations énumérées ci-dessus sont indicatives et non exhaustives. Chaque intervention sera réalisée uniquement si elle s'avère nécessaire au traitement du dossier, en fonction des besoins identifiés et des démarches à accomplir.
TEXT,
<<<'TEXT'
Accompagnement administratif complémentaire – à l'heure
- Traitement des demandes administratives complémentaires.
- Gestion et régularisation des dossiers incomplets.
- Collecte, vérification et transmission de pièces justificatives.
- Échanges administratifs avec les entreprises, les stagiaires et les organismes concernés.
- Suivi des dossiers nécessitant des démarches supplémentaires.
- Assistance administrative ponctuelle au fonctionnement de l'organisme de formation.
- Etc.

Prestations réalisées sur demande, au temps réellement consacré, après validation préalable du client.
Les prestations énumérées ci-dessus sont indicatives et non exhaustives. Chaque intervention sera réalisée uniquement si elle s'avère nécessaire au traitement du dossier, en fonction des besoins identifiés et des démarches à accomplir.
TEXT,
<<<'TEXT'
Ingénierie pédagogique, financière et accompagnement – à l'heure
Ingénierie pédagogique :
- Appui à la structuration et à la formalisation des programmes de formation.
- Accompagnement dans la définition des objectifs pédagogiques et des modalités d'évaluation, en collaboration avec les formateurs.
- Vérification de la cohérence des documents pédagogiques et de leur présentation.
Ingénierie financière :
- Recherche et analyse des dispositifs de financement mobilisables (OPCO et autres financeurs).
- Étude des conditions d'éligibilité et des possibilités de prise en charge.
- Accompagnement à la préparation, au montage et au suivi des dossiers de financement courants.
Les financements restent soumis à l'acceptation des organismes financeurs.
Conseil et accompagnement :
- Appui au fonctionnement et à l'organisation administrative de l'organisme de formation.
- Accompagnement dans la mise en application et le suivi des procédures qualité existantes.
- Conseils pratiques et assistance ponctuelle sur les démarches liées à l'activité de formation.
Prestations réalisées selon les besoins exprimés, sur la base du temps consacré et après accord préalable.
TEXT,
];
foreach ($descriptions as $i => $label) $quote->addLigne((new LigneDevis())->setLabel($label)->setQte(1)->setPuHtCents([6000,4500,9000][$i])->setTva(0));
$invoiceIssuer = (new Entite())->setNom('I.T. For You')->setAdresse('88 Chemin de Russaud')->setCodePostal('30100')->setVille('Alès')->setPays('France')->setTelephone('+33627087699')->setEmail('contact@itforyou.fr')->setSiret('80813612100023')->setNumeroTva('FR75808136121')->setSiteInternet('http://itforyou.fr/')->setCouleurPrincipal('#203747')->setCouleurSecondaire('#b29352')->setBanque('Crédit Agricole')->setIban('FR76 1350 6100 0085 1060 1756 050')->setBic('AGRIFRPP835')->setCodeBanque('13506')->setNumeroCompte('85106017560')->setLogo('logo-itforyou-8cf8edcccd5d.png');
$company = (new Entreprise())->setRaisonSociale('Holding du Solaire')->setAdresse('210 RUE DE LA ROUSSATAIO')->setCodePostal('34740')->setVille('VENDARGUES')->setPays('France')->setSiret('81286415500039')->setEmail('rudy.gimalac@gdsolaire.com');
(new ReflectionProperty(Entreprise::class, 'id'))->setValue($company, 39);
$invoice = (new Facture())->setEntite($invoiceIssuer)->setEntrepriseDestinataire($company)->setNumero('FAC-E1-2026-0037')->setDateEmission(new DateTimeImmutable('2026-11-12'))->setDevise('EUR')->setStatus(App\Enum\FactureStatus::DUE)->setMeta(['invoice_terms'=>[
'dueDate'=>'2026-12-11','serviceDate'=>'2026-10-06','paymentMeans'=>'Virement bancaire','earlyPaymentDiscount'=>'Escompte pour paiement anticipé : néant.',
'latePaymentPenalty'=>'Pour les clients professionnels, pénalités exigibles dès le lendemain de l’échéance, sans rappel, au taux de refinancement de la BCE en vigueur au 1er janvier ou au 1er juillet du semestre concerné, majoré de 10 points.',
'recoveryCompensationCents'=>4000,'legalIdentity'=>'Jeroen Popot - Auto-Entrepreneur - Nom commercial : I.T. For You - SIREN : 808136121 - SIRET : 80813612100023','vatCategory'=>'S','vatOnDebits'=>false]]);
$invoice->addLigne((new LigneFacture())->setLabel('Habilitation Electrique Non Electricien H0(V)-B0 — CONV-E1-2026-0008 — SES-E1-2026-0087')->setQte(1)->setPuHtCents(8800)->setTva(20));
$inscription=new App\Entity\Inscription(); (new ReflectionProperty(App\Entity\Inscription::class, 'id'))->setValue($inscription,138); $invoice->addInscription($inscription);
InvoiceTotals::finalizeNewInvoice($invoice); InvoiceSnapshot::capture($invoice);
@mkdir($root.'/output/pdf',0777,true);
$render = function(string $template, array $context, string $path) use ($twig,$pdf,$root): void {
 $html=$twig->render($template, $context+['studioBypass'=>true]);
 // Embed local logo files; do not send preview documents to remote endpoints.
 $html=preg_replace_callback('~src="[^"]*/uploads/photos/entite/logo/([^"/]+)"~',static function($m)use($root){$p=$root.'/public/uploads/photos/entite/logo/'.rawurldecode($m[1]);if(!is_file($p))return $m[0];return 'src="data:'.(new finfo(FILEINFO_MIME_TYPE))->file($p).';base64,'.base64_encode(file_get_contents($p)).'"';},$html);
 file_put_contents($root.'/tmp/pdfs/'.basename($path,'.pdf').'.html',$html);
 file_put_contents($path,$pdf->createPortraitBytes($html));
 echo $path.PHP_EOL;
};
$render('pdf/devis.html.twig',['devis'=>$quote,'entite'=>$issuer],$root.'/output/pdf/devis-professionnel-apercu.pdf');
$render('pdf/facture.html.twig',['facture'=>$invoice,'entite'=>$invoiceIssuer],$root.'/output/pdf/facture-professionnelle-apercu.pdf');
if (in_array('--stress', $argv, true)) {
 $stress=(new Devis())->setEntite($issuer)->setNumero('APERCU-PAGINATION')->setDateEmission(new DateTimeImmutable('2026-11-13'))->setDevise('EUR')->setStatus(App\Enum\DevisStatus::DRAFT)->setEntrepriseDestinataire($company)->setMontantHtCents(400000)->setMontantTvaCents(80000)->setMontantTtcCents(480000);
 for($i=1;$i<=40;$i++) $stress->addLigne((new LigneDevis())->setLabel('Prestation '.$i.' — Vérification de la pagination' ."\n".str_repeat("- Détail de prestation à conserver dans le document, avec des accents et des informations complémentaires.\n",12))->setQte(1)->setPuHtCents(10000)->setTva(20));
 $render('pdf/devis.html.twig',['devis'=>$stress,'entite'=>$issuer],$root.'/tmp/pdfs/devis-pagination.pdf');
 $long=(new Devis())->setEntite($issuer)->setNumero('APERCU-TEXTE-LONG')->setDateEmission(new DateTimeImmutable('2026-11-13'))->setDevise('EUR')->setStatus(App\Enum\DevisStatus::DRAFT)->setEntrepriseDestinataire($company)->setMontantHtCents(10000)->setMontantTvaCents(2000)->setMontantTtcCents(12000);
 $long->addLigne((new LigneDevis())->setLabel("Description longue sans retour à la ligne\n".str_repeat('Paragraphe de vérification comportant un contenu long sans retour manuel à la ligne. ',150))->setQte(1)->setPuHtCents(10000)->setTva(20));
 $render('pdf/devis.html.twig',['devis'=>$long,'entite'=>$issuer],$root.'/tmp/pdfs/devis-long-description.pdf');
}
