<?php

declare(strict_types=1);
namespace App\Service\Caces;

/** Pure rules, independent of the training evaluation module. */
final class CacesRules
{
    public const FAMILIES = ['482'=>'Engins de chantier', '483'=>'Grues mobiles', '484'=>'Ponts roulants et portiques', '485'=>'Gerbeurs à conducteur accompagnant', '486'=>'Plates-formes élévatrices mobiles', '487'=>'Grues à tour', '489'=>'Chariots à conducteur porté', '490'=>'Grues de chargement'];
    public const CATEGORIES = ['482'=>['A','B1','B2','B3','C1','C2','C3','D','E','F','G'], '483'=>['A','B'], '484'=>['1','2'], '485'=>['1','2'], '486'=>['A','B','C'], '487'=>['1','2','3'], '489'=>['1A','1B','2A','2B','3','4','5','6','7'], '490'=>['Unique']];

    public function validate(array $schema, string $kind, bool $publishing = true): array
    {
        if (!in_array($kind, ['theory','practice'], true)) throw new \InvalidArgumentException('Type de test inconnu.');
        $themes = $schema['themes'] ?? [];
        if (!is_array($themes) || ($publishing && count($themes) < 1) || count($themes) > 15) throw new \InvalidArgumentException('Précisez les thèmes du test.');
        $total = 0; $ids = []; $assessments = [];
        foreach ($themes as &$theme) {
            if (!is_array($theme) || !is_string($theme['title'] ?? null) || ($publishing && trim($theme['title']) === '') || mb_strlen($theme['title']) > 255) throw new \InvalidArgumentException('Chaque thème doit avoir un titre.');
            if (!is_array($theme['questions'] ?? null) || ($publishing && !$theme['questions'])) throw new \InvalidArgumentException('Un thème ne peut pas être vide.');
            $assessment=$theme['assessment']??'main';
            if(!is_string($assessment) || !preg_match('/^[a-zA-Z0-9_-]{1,50}$/D',$assessment) || ($kind==='theory' && $assessment!=='main')) throw new \InvalidArgumentException('Épreuve pratique invalide.');
            if(isset($theme['assessmentLabel']) && (!is_string($theme['assessmentLabel']) || mb_strlen($theme['assessmentLabel'])>180)) throw new \InvalidArgumentException('Libellé d’engin invalide.');
            $assessments[$assessment]??=0;
            foreach ($theme['questions'] as &$q) {
                if (!is_array($q) || !is_string($q['id'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,50}$/D', $q['id']) || isset($ids[$q['id']])) throw new \InvalidArgumentException('Les identifiants doivent être uniques.');
                $ids[$q['id']] = true;
                if (!is_string($q['label'] ?? null) || ($publishing && trim($q['label']) === '') || mb_strlen($q['label']) > 4000) throw new \InvalidArgumentException('Chaque question doit avoir un énoncé.');
                if ($kind === 'theory') {
                    $q['points'] = 1;
                    if ($publishing && !in_array($q['correct'] ?? null, ['A','B'], true)) throw new \InvalidArgumentException('Choisissez une seule bonne réponse A ou B.');
                } elseif (!is_int($q['points'] ?? null) || $q['points'] < 1 || $q['points'] > 100) throw new \InvalidArgumentException('Le barème d’un critère doit être compris entre 1 et 100.');
                if (!empty($q['image']) && (!is_string($q['image']) || !preg_match('~^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$~D', $q['image']) || strlen($q['image']) > 2000000)) throw new \InvalidArgumentException('Illustration invalide (PNG, JPEG ou WebP, 1,5 Mo maximum).');
                $total += $q['points'];
                $assessments[$assessment]+=$q['points'];
            }
            unset($q);
        }
        unset($theme);
        if ($publishing && (!$assessments || count(array_filter($assessments,fn($max)=>$max!==100)))) throw new \InvalidArgumentException('Chaque épreuve (ou engin évalué séparément) doit totaliser exactement 100 points.');
        $duration = $schema['durationMinutes'] ?? 60;
        if (!is_int($duration) || $duration < 1 || $duration > 480 || ($kind === 'theory' && $duration !== 60)) throw new \InvalidArgumentException('La théorie dure 60 minutes. La durée pratique doit être comprise entre 1 et 480 minutes.');
        return ['themes'=>$themes, 'durationMinutes'=>$duration, 'source'=>$schema['source'] ?? null, 'reference'=>$schema['reference'] ?? null];
    }

    public function grade(array $schema, array $answers, bool $practice = false, int $durationSeconds = 0): array
    {
        $total=0; $results=[]; $reasons=[]; $assessments=[];
        foreach ($schema['themes'] as $theme) {
            $score=0; $max=0;
            $assessment=$practice?($theme['assessment']??'main'):'main';
            $label=$theme['assessmentLabel']??$assessment;
            $title=isset($theme['assessment'])?$label.' · '.$theme['title']:$theme['title'];
            $assessments[$assessment]??=['title'=>$label,'score'=>0,'max'=>0];
            foreach ($theme['questions'] as $q) {
                $max += $q['points'];
                if ($practice) {
                    $raw=$answers[$q['id']] ?? null;
                    if (!(is_int($raw) || (is_string($raw) && ctype_digit($raw))) || (int)$raw > $q['points'] || (int)$raw < 0) throw new \InvalidArgumentException('Renseignez chaque critère dans son barème.');
                    $points=(int)$raw;
                    if ($points===0) $reasons[]='Critère à zéro : '.$q['label'];
                } else $points=($answers[$q['id']] ?? null)===$q['correct'] ? 1 : 0;
                $score += $points;
            }
            $threshold=(int)ceil($max/2);
            if ($score<$threshold) $reasons[]='Seuil non atteint : '.$title;
            $results[]=['title'=>$title,'score'=>$score,'max'=>$max,'threshold'=>$threshold,'passed'=>$score >= $threshold];
            $total+=$score;
            $assessments[$assessment]['score']+=$score;$assessments[$assessment]['max']+=$max;
        }
        foreach($assessments as $id=>$assessment) {
            if($assessment['score']<70) $reasons[]='Note inférieure à 70/100'.($id==='main'?'':' : '.$assessment['title']).'.';
        }
        // A mean is a display summary only: each required engine must pass separately.
        $total=count($assessments)>1?round($total/count($assessments),2):$total;
        if ($practice && $durationSeconds > $schema['durationMinutes']*60) $reasons[]='Durée maximale dépassée.';
        return ['score'=>$total,'passed'=>!$reasons,'themes'=>$results,'reasons'=>$reasons,'durationSeconds'=>$durationSeconds,'assessments'=>array_values($assessments)];
    }

    /** Resolve selected snapshot themes; never trust submitted titles or eligibility. */
    public function remediationThemes(array $schema, array $scores, array $selected): array
    {
        $result = $this->grade($schema, $scores, true);
        $titles = [];
        foreach ($selected as $index) {
            if (!(is_int($index) || (is_string($index) && ctype_digit($index))) || !isset($result['themes'][(int)$index])) {
                throw new \InvalidArgumentException('Thème à revoir invalide.');
            }
            $index = (int)$index;
            $hasZero = false;
            foreach ($schema['themes'][$index]['questions'] as $question) {
                if ((int)$scores[$question['id']] === 0) $hasZero = true;
            }
            if ($result['themes'][$index]['passed'] && !$hasZero) {
                throw new \InvalidArgumentException('Seuls les thèmes non réussis peuvent être sélectionnés.');
            }
            $titles[$index] = $result['themes'][$index]['title'];
        }
        return array_values($titles);
    }

    public function dates(string $recommendation, \DateTimeImmutable $theory, \DateTimeImmutable $practice, ?\DateTimeImmutable $anchor = null): array
    {
        $obtained=max($theory,$practice); $anchor ??= $obtained;
        if ($anchor>$obtained) throw new \InvalidArgumentException('La date initiale ne peut pas être postérieure à l’obtention.');
        return ['obtainedAt'=>$obtained->format('Y-m-d'), 'expiresAt'=>$anchor->modify('+'.($recommendation==='482' ? 10 : 5).' years')->modify('-1 day')->format('Y-m-d'),'anchorAt'=>$anchor->format('Y-m-d')];
    }
}
