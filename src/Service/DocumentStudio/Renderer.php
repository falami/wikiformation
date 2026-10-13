<?php
declare(strict_types=1);
namespace App\Service\DocumentStudio;
use App\Entity\{Entite,DocumentTemplateBinding,Facture};
use App\Twig\PrivateDocumentImageExtension;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class Renderer extends AbstractExtension
{
    public function __construct(private EntityManagerInterface $em, private Content $content, private PrivateDocumentImageExtension $images) {}
    public function getFilters(): array { return [new TwigFilter('document_studio',[$this,'filter'],['is_safe'=>['html']])]; }
    public function filter(string $html, string $kind, array $context): string {
        if (!empty($context['studioBypass'])) return $html;
        $tenant=$context['entite'] ?? ($context['meta']['entite'] ?? null);
        if (!$tenant instanceof Entite) $tenant=($context['convention'] ?? null)?->getEntite();
        if (!$tenant instanceof Entite || !$tenant->getId()) return $html;
        // A draft never affects delivery. Billing uses the configuration frozen at issuance.
        if (($context['facture'] ?? null) instanceof Facture && $context['facture']->getId()) {
            $snapshot=\App\Service\Billing\InvoiceSnapshot::read($context['facture']);
            $config=$snapshot['invoice']['meta']['document_studio'][$kind] ?? null;
        } else {
            $binding=$this->em->getRepository(DocumentTemplateBinding::class)->findOneBy(['entite'=>$tenant,'kind'=>$kind]);
            $config=$binding?->getConfig();
        }
        return $config ? $this->render($html,$kind,$config,$context) : $html;
    }
    public function values(array $context): array {
        $tenant=$context['entite'] ?? ($context['meta']['entite'] ?? null);
        $c=$context['convention'] ?? null; $session=$context['session'] ?? $c?->getSession();
        $tenant ??= $c?->getEntite();
        $formation=$context['formation'] ?? $session?->getFormation();
        $learner=$context['stagiaire'] ?? ($context['inscription'] ?? null)?->getStagiaire();
        $company=$context['entreprise'] ?? $c?->getEntreprise();
        $trainer=($context['contrat'] ?? null)?->getFormateur()?->getUtilisateur() ?? $session?->getFormateur()?->getUtilisateur();
        $preferences=$tenant?->getPreferences();
        $values=[
            'organisme.nom'=>$tenant?->getNom(), 'organisme.adresse'=>trim(($tenant?->getAdresse() ?? '').' '.($tenant?->getCodePostal() ?? '').' '.($tenant?->getVille() ?? '')),
            'organisme.siret'=>$tenant?->getSiret(), 'organisme.email'=>$tenant?->getEmail(), 'organisme.telephone'=>$tenant?->getTelephone(),
            'organisme.nda'=>$tenant?->getNumeroDeclarant(), 'organisme.signataire'=>$preferences?->getSignatureOrganismeNom(),
            'organisme.fonction'=>$preferences?->getSignatureOrganismeFonction(),
            'session.code'=>$session?->getCode(), 'formation.titre'=>$session?->getFormationLabel() ?? $formation?->getTitre(),
            'formation.objectifs'=>$formation?->getObjectifs(), 'formation.prerequis'=>$formation?->getConditionPrealable(),
            'stagiaire.prenom'=>$learner?->getPrenom(), 'stagiaire.nom'=>$learner?->getNom(),
            'entreprise.nom'=>$company?->getRaisonSociale(), 'formateur.prenom'=>$trainer?->getPrenom(), 'formateur.nom'=>$trainer?->getNom(),
        ];
        foreach (['convention','contrat','facture','devis'] as $key) if (isset($context[$key])) { $values['document.numero']=$context[$key]->getNumero(); break; }
        $invoice=$context['facture'] ?? null;
        if ($invoice instanceof Facture && ($snapshot=\App\Service\Billing\InvoiceSnapshot::read($invoice))) {
            foreach (['nom','siret','email','telephone'] as $key) $values['organisme.'.$key]=$snapshot['issuer'][$key] ?? null;
            $issuer=$snapshot['issuer'];
            $values['organisme.adresse']=trim(($issuer['adresse'] ?? '').' '.($issuer['codePostal'] ?? '').' '.($issuer['ville'] ?? ''));
            $values['organisme.nda']=$issuer['numeroDeclarant'] ?? null;
            $values['organisme.signataire']=$values['organisme.fonction']=null;
            $values['entreprise.nom']=$snapshot['customer']['raisonSociale'] ?? null;
            $preferences=null; // Never substitute a newly configured signature into an issued invoice.
        }
        foreach ($values as $key=>$value) $values[$key]=htmlspecialchars((string)($value ?: 'Non renseigné'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        foreach (['signature'=>'getSignatureOrganismePath','cachet'=>'getTamponOrganismePath'] as $key=>$getter) {
            $uri=$this->images->dataUri($preferences?->$getter());
            $values['organisme.'.$key]=$uri ? '<img alt="'.ucfirst($key).' organisme" style="max-width:180px;max-height:75px" src="'.$uri.'">' : '<span>('.ucfirst($key).' non configuré)</span>';
        }
        return $values;
    }
    public function render(string $html, string $kind, array $config, array $context): string {
        $config=$this->content->normalize($config,$kind);
        if ($config===Catalog::defaults()) return $html;
        if (($config['base'] ?? 'original') === 'blank') $html='<!DOCTYPE html><html><head><meta charset="UTF-8"><style>@page{margin:36pt}body{font-family:DejaVu Sans;font-size:10pt;color:#203746}</style></head><body></body></html>';
        $values=$this->values($context);
        foreach ($config['fields'] as $field) $values['custom.'.$field['key']]=htmlspecialchars($field['value'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $replace=static fn(string $text):string=>preg_replace_callback('/\[\[([^\]]+)\]\]/u',static fn($m)=>$values[$m[1]] ?? 'Non renseigné',$text);
        $dom=new \DOMDocument('1.0','UTF-8'); $old=libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET);
        libxml_clear_errors();libxml_use_internal_errors($old);
        $xpath=new \DOMXPath($dom); $body=$dom->getElementsByTagName('body')->item(0);
        if (!$body) return $html;
        // End blocks appear before the existing signing area, preserving signature coordinates discovery.
        $signature=null;
        foreach ($xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," section-title ")]') as $heading) {
            if (trim($heading->textContent)==='Signatures') { $signature=$heading->parentNode; break; }
        }
        $first=$body->firstChild;
        foreach ($config['blocks'] as $block) {
            $fragment='<section class="studio-block"'.($block['pageBreak']?' style="page-break-before:always"':'').'>';
            if($block['title']) $fragment.='<h2>'.$replace(htmlspecialchars($block['title'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')).'</h2>';
            $fragment.=$replace($block['html']).'</section>';
            $part=new \DOMDocument('1.0','UTF-8');$old=libxml_use_internal_errors(true);
            $part->loadHTML('<?xml encoding="UTF-8"><body>'.$fragment.'</body>',LIBXML_NONET);libxml_clear_errors();libxml_use_internal_errors($old);
            $node=$dom->importNode($part->getElementsByTagName('section')->item(0),true);
            if ($block['position']==='section') {
                $matched=false;
                foreach ($xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," section-title ")]') as $heading) {
                    if (trim($heading->textContent)===$block['section']) {
                        $anchor=$heading;
                        if ($heading->parentNode instanceof \DOMElement && str_contains($heading->parentNode->getAttribute('class'),'section-head')) $anchor=$heading->parentNode;
                        $card=$anchor->parentNode;
                        // Replace prose only; sections in Catalog never contain monetary/signing controls.
                        while ($anchor->nextSibling) $card->removeChild($anchor->nextSibling);
                        $card->appendChild($node); $matched=true;break;
                    }
                }
                if (!$matched) $body->appendChild($node);
            } elseif ($block['position']==='start') $body->insertBefore($node,$first);
            elseif ($signature) $signature->parentNode->insertBefore($node,$signature);
            else $body->appendChild($node);
        }
        $css='.studio-block{page-break-inside:avoid;margin:12pt 0;padding:12pt 14pt;border:1pt solid #dce4ed;line-height:1.5}.studio-block h2{font-size:14pt;page-break-after:avoid}.studio-block h3{font-size:12pt;page-break-after:avoid}.studio-block p{margin:0 0 8pt}.studio-block li{margin-bottom:4pt}';
        if ($config['primary']) $css.='.section-title,.studio-block h2,.studio-block h3,.brand-name,.doc-meta strong{color:'.$config['primary'].'!important}.card,.header{border-left-color:'.$config['primary'].'!important}';
        if ($config['accent']) $css.='.section-title-accent,.header-bar{background:'.$config['accent'].'!important}.studio-block{border-left:3pt solid '.$config['accent'].'}';
        if ($config['font']!=='original') $css.='body{font-family:'.($config['font']==='sans'?'DejaVu Sans':'DejaVu Serif').'!important}';
        $style=$dom->createElement('style',$css);($dom->getElementsByTagName('head')->item(0) ?? $body)->appendChild($style);
        foreach (iterator_to_array($dom->childNodes) as $node) if ($node instanceof \DOMProcessingInstruction) $dom->removeChild($node);
        return $dom->saveHTML();
    }
}
