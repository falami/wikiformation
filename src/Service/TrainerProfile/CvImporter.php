<?php
declare(strict_types=1);
namespace App\Service\TrainerProfile;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
final class CvImporter
{
    public const FIELDS = ['headline'=>'Titre professionnel', 'summary'=>'Présentation', 'experience'=>'Expériences professionnelles', 'education'=>'Diplômes et certifications', 'skills'=>'Compétences', 'languages'=>'Langues', 'methods'=>'Approche pédagogique'];
    public static function normalize(array $input): array {
        $out=[];
        foreach(self::FIELDS as $key=>$label) {
            $value=$input[$key]??'';
            if(!is_string($value) || mb_strlen($value)>($key==='headline'?180:12000)) throw new \InvalidArgumentException('Vérifiez la longueur du champ « '.$label.' ».');
            $out[$key]=trim($value);
        }
        return $out;
    }
    public function import(string $path): array
    {
        if (!is_file($path) || filesize($path) > 10000000) {
            throw new \RuntimeException('Choisissez un PDF de 10 Mo maximum.');
        }
        try {
            $config = new Config();
            $config->setRetainImageContent(false);
            $config->setDecodeMemoryLimit(16000000);
            $pdf = (new Parser([], $config))->parseFile($path);
            if (count($pdf->getPages()) > 20) {
                throw new \RuntimeException();
            }
            $text = $pdf->getText();
        } catch (\Throwable $e) {
            throw new \RuntimeException('Impossible de lire ce PDF (protégé, endommagé ou trop volumineux). Le PDF et votre CV sont conservés. Complétez les rubriques manuellement ou déposez un PDF contenant du texte, de 20 pages maximum.');
        }
        if (mb_strlen(trim($text)) < 30) {
            throw new \RuntimeException('Ce PDF ne contient pas assez de texte exploitable : il peut s’agir d’un scan. Le PDF et votre CV sont conservés. Déposez une version avec du texte sélectionnable ou complétez le CV manuellement.');
        }
        return $this->fromText($text);
    }

    /** Conservative section detection: never infer a qualification or a job title. */
    public function fromText(string $text): array
    {
        $aliases = [
            'summary' => ['profil', 'profil professionnel', 'présentation', 'à propos', 'résumé', 'objectif professionnel', 'profile', 'summary'],
            'experience' => ['expérience', 'expériences', 'expérience professionnelle', 'expériences professionnelles', 'parcours professionnel', 'professional experience', 'work experience'],
            'education' => ['formation', 'formations', 'diplômes', 'certifications', 'diplômes et certifications', 'formation et diplômes', 'formations et certifications', 'parcours académique', 'education'],
            'skills' => ['compétences', 'compétences techniques', 'compétences professionnelles', 'expertises', 'savoir-faire', 'skills'],
            'languages' => ['langues', 'langues étrangères', 'languages'],
            'methods' => ['approche pédagogique', 'méthodes pédagogiques', 'pédagogie'],
        ];
        $fold = static fn(string $value): string => mb_strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($value)));
        $headings = [];
        foreach ($aliases as $field => $labels) {
            foreach ($labels as $label) $headings[$fold($label)] = $field;
        }
        $out = array_fill_keys(array_keys(self::FIELDS), '');
        $section = null;
        foreach (preg_split('/\R/u', mb_substr($text, 0, 150000)) ?: [] as $line) {
            $line = trim(preg_replace('/[\t\x{00A0} ]+/u', ' ', $line) ?? '');
            $parts = preg_split('/\s*[:：]\s*/u', $line, 2);
            $heading = $fold(rtrim($parts[0], ' :'));
            if (isset($headings[$heading])) {
                $section = $headings[$heading];
                $line = $parts[1] ?? '';
            } elseif (in_array($heading, ['contact', 'coordonnees', 'informations personnelles', 'centres d’interet', "centres d'interet", 'loisirs', 'references'], true)) {
                $section = null;
            }
            // Avoid copying contact details into the published professional sections.
            if ($section === null || preg_match('/@|https?:|www\.|(?:\+?\d[ .()-]*){9,}/u', $line)) continue;
            $out[$section] .= $line . "\n";
        }
        foreach ($out as $key => $value) $out[$key] = mb_substr(trim($value), 0, $key === 'headline' ? 180 : 12000);
        if (!array_filter($out)) {
            throw new \RuntimeException('Le texte est lisible, mais ses rubriques ne sont pas reconnues. Le PDF et votre CV sont conservés. Complétez les champs manuellement ou utilisez des titres comme « Expériences professionnelles », « Compétences » et « Diplômes ».');
        }
        return self::normalize($out);
    }
}
