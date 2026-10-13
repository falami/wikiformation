<?php
declare(strict_types=1);
namespace App\Service\DocumentStudio;

final class Content
{
    public function normalize(array $input, string $kind): array {
        Catalog::requireKind($kind);
        $out=Catalog::defaults();
        if (($input['base'] ?? 'original') === 'blank') {
            $out['base']='blank';
        }
        foreach (['primary','accent'] as $key) {
            $value=$input[$key] ?? '';
            if (!is_string($value) || ($value !== '' && !preg_match('/^#[0-9a-f]{6}$/iD',$value))) throw new \DomainException('Choisissez une couleur valide.');
            $out[$key]=$value;
        }
        $out['font']=in_array($input['font'] ?? '', ['original','sans','serif'],true) ? $input['font'] : 'original';
        if (!is_array($input['fields'] ?? []) || count($input['fields'] ?? []) > 40) throw new \DomainException('Maximum 40 champs personnalisés.');
        foreach (($input['fields'] ?? []) as $field) {
            if (!is_array($field)) throw new \DomainException('Champ invalide.');
            $key=$field['key'] ?? ''; $label=trim((string)($field['label'] ?? '')); $value=(string)($field['value'] ?? '');
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/D',$key) || !$label || mb_strlen($label)>100 || mb_strlen($value)>4000) throw new \DomainException('Chaque champ nécessite un identifiant simple, un libellé et une valeur de moins de 4 000 caractères.');
            if (isset($out['fields'][$key])) throw new \DomainException('Deux champs portent le même identifiant.');
            $out['fields'][$key]=['key'=>$key,'label'=>$label,'value'=>$value];
        }
        $out['fields']=array_values($out['fields']);
        if (!is_array($input['blocks'] ?? []) || count($input['blocks'] ?? [])>60) throw new \DomainException('Maximum 60 blocs par modèle.');
        $sections=[];
        foreach (($input['blocks'] ?? []) as $block) {
            if (!is_array($block)) throw new \DomainException('Bloc invalide.');
            $position=$block['position'] ?? 'end';
            if (!in_array($position,['start','end','section'],true)) throw new \DomainException('Emplacement inconnu.');
            $section=$position==='section' ? (string)($block['section'] ?? '') : '';
            if ($position==='section' && (!in_array($section,Catalog::SECTIONS[$kind] ?? [],true) || isset($sections[$section]))) throw new \DomainException('Rubrique inconnue ou déjà personnalisée.');
            if ($section) $sections[$section]=true;
            $title=trim((string)($block['title'] ?? ''));
            $html=(string)($block['html'] ?? '');
            if (mb_strlen($title)>160 || strlen($html)>30000) throw new \DomainException('Le bloc est trop long.');
            $html=$this->sanitize($html);
            preg_match_all('/\[\[([^\]]+)\]\]/u',$html.' '.$title,$tokens);
            foreach ($tokens[1] as $token) {
                $custom=str_starts_with($token,'custom.') ? substr($token,7) : null;
                if (!isset(Catalog::FIELDS[$token]) && !in_array($custom,array_column($out['fields'],'key'),true)) throw new \DomainException('Champ inconnu : '.$token);
            }
            $out['blocks'][]=['title'=>$title,'html'=>$html,'position'=>$position,'section'=>$section,'pageBreak'=>!empty($block['pageBreak'])];
        }
        return $out;
    }

    /** Rebuild an allowlist tree, dropping active content and all external resources. */
    public function sanitize(string $html): string {
        $dom=new \DOMDocument('1.0','UTF-8'); $old=libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>',LIBXML_NONET);
        libxml_clear_errors(); libxml_use_internal_errors($old);
        $render=function(\DOMNode $node) use (&$render): string {
            if ($node instanceof \DOMText) return htmlspecialchars($node->textContent,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            if (!$node instanceof \DOMElement) return '';
            $tag=strtolower($node->tagName);
            if (in_array($tag,['script','style','iframe','object','embed','svg','math','img','link','meta','form','input'],true)) return '';
            $children=''; foreach ($node->childNodes as $child) $children.=$render($child);
            if (!in_array($tag,['p','div','span','strong','b','em','i','u','s','h2','h3','h4','ul','ol','li','br','hr','blockquote'],true)) return $children;
            $styles=[];
            foreach (explode(';',$node->getAttribute('style')) as $style) {
                $parts=explode(':',$style,2); if(count($parts)!==2)continue;
                [$key,$value]=array_map('trim',$parts);
                if (in_array($key,['color','background-color'],true) && preg_match('/^(#[a-f0-9]{3,6}|rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\))$/iD',$value)) $styles[]=$key.':'.$value;
                if ($key==='text-align' && in_array($value,['left','center','right','justify'],true)) $styles[]=$key.':'.$value;
            }
            $attr=$styles ? ' style="'.htmlspecialchars(implode(';',$styles),ENT_QUOTES).'"' : '';
            return '<'.$tag.$attr.'>'.(in_array($tag,['br','hr'],true)?'':$children.'</'.$tag.'>');
        };
        $out=''; foreach ($dom->getElementsByTagName('body')->item(0)?->childNodes ?? [] as $node) $out.=$render($node);
        return $out;
    }
}
