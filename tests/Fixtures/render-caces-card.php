<?php
declare(strict_types=1);
// Deterministic, fictitious data for browser print regression tests. No database.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$root = dirname(__DIR__, 2);
$twig = new Twig\Environment(new Twig\Loader\FilesystemLoader($root.'/templates'), ['strict_variables'=>true]);
$twig->addFunction(new Twig\TwigFunction('asset', static fn(string $path): string => 'file://'.$root.'/public/'.$path));
$twig->addFunction(new Twig\TwigFunction('path', static fn(string $name, array $params = []): string => '#'));
$scenario = $argv[2] ?? 'single';
$c = ['learner'=>'Stagiaire Test','number'=>'2026.489.C7.011','practiceTester'=>'Formateur Test','obtainedAt'=>'2026-11-12','expiresAt'=>'2031-11-02','issuedAt'=>'2026-11-12','issuedBy'=>'OF Test'];
$e = ['recommendation'=>'489','category'=>'7','session'=>['code'=>'SES-E1-2026-0006']];
$d = ['id'=>11,'certificate'=>$c,'exam'=>$e,'learner'=>['prenom'=>'Stagiaire','nom'=>'Test','photo'=>null],'profile'=>['birthDate'=>'1990-03-21','birthPlace'=>'Paris'],'signatures'=>[]];
$certificates = [$d];
$categories = ['1A','1B','2A','2B','3','4','5','6','7'];
if ($scenario === 'all') {
    $certificates = [];
    foreach ($categories as $category) {
        $a = $d;
        $a['exam']['category'] = $category;
        $a['certificate']['number'] = '2026.489.'.$category.'.00011';
        $a['certificate']['practiceTester'] = 'Charles-Henri Dupont de Neuilly';
        $certificates[] = $a;
    }
}
if ($scenario === 'long') {
    $d['certificate']['learner'] = $c['learner'] = 'Alexandre Jean-Baptiste de La Rochefoucauld';
    $certificates[0] = $d;
}
$html = $twig->render('caces/document.html.twig', [
    'type'=>'carte', 'dossier'=>$d,
    'entite'=>['id'=>1,'nom'=>'PRÉPRODUCTION — Organisme fictif','adresse'=>'12 rue des Ateliers et de la Formation','codePostal'=>'75011','ville'=>'Paris','siret'=>'12345678900010','email'=>'contact@example.test','telephone'=>'01 23 45 67 89','logo'=>null,'siteInternet'=>'https://example.test'],
    'cardCertificates'=>$certificates,'cardCategories'=>$categories,'cardFamily'=>'Chariots à conducteur porté'
]);
file_put_contents($argv[1], $html);
