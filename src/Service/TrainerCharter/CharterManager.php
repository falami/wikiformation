<?php
declare(strict_types=1);
namespace App\Service\TrainerCharter;
use App\Entity\{Entite, TrainerCharter, TrainerCharterVersion, TrainerCharterSignature, Formateur};
use Doctrine\ORM\EntityManagerInterface;
final class CharterManager
{
    public function __construct(private EntityManagerInterface $em) {}
    public function latest(TrainerCharter $charter): ?TrainerCharterVersion {
        return $this->em->getRepository(TrainerCharterVersion::class)->createQueryBuilder('v')->where('v.charter = :c')->andWhere('v.publishedAt IS NOT NULL')->setParameter('c',$charter)->orderBy('v.number','DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }
    public function forTrainer(Entite $entite, Formateur $trainer): array {
        return $this->em->getRepository(TrainerCharterSignature::class)->createQueryBuilder('s')->join('s.version','v')->join('v.charter','c')->where('s.trainer = :t')->andWhere('c.entite = :e')->setParameter('t',$trainer)->setParameter('e',$entite)->orderBy('s.requestedAt','DESC')->getQuery()->getResult();
    }
    public function pending(Entite $entite, Formateur $trainer): array {
        return array_values(array_filter($this->forTrainer($entite,$trainer), fn($s) => !$s->signedAt && $this->latest($s->version->charter)?->id === $s->version->id));
    }
    public function normalize(array $chapters): array {
        if (!$chapters || count($chapters)>40) throw new \InvalidArgumentException('Ajoutez entre 1 et 40 chapitres.');
        $out=[];
        foreach ($chapters as $chapter) {
            if (!is_array($chapter)) throw new \InvalidArgumentException('Chapitre invalide.');
            $title=trim((string)($chapter['title']??'')); $body=trim((string)($chapter['body']??''));
            if ($title==='' || mb_strlen($title)>180 || mb_strlen($body)>12000) throw new \InvalidArgumentException('Chaque chapitre doit avoir un titre (180 caractères maximum) et un texte de 12 000 caractères maximum.');
            $children=$chapter['children']??[];
            if (!is_array($children) || count($children)>30) throw new \InvalidArgumentException('Maximum 30 sous-chapitres par chapitre.');
            $subs=[];
            foreach ($children as $child) {
                if (!is_array($child)) throw new \InvalidArgumentException('Sous-chapitre invalide.');
                $ct=trim((string)($child['title']??''));$cb=trim((string)($child['body']??''));
                if (!$ct || !$cb || mb_strlen($ct)>180 || mb_strlen($cb)>12000) throw new \InvalidArgumentException('Complétez le titre et le texte de chaque sous-chapitre.');
                $subs[]=['title'=>$ct,'body'=>$cb];
            }
            if (!$body && !$subs) throw new \InvalidArgumentException('Ajoutez du contenu à chaque chapitre.');
            $out[]=['title'=>$title,'body'=>$body,'children'=>$subs];
        }
        if (strlen(json_encode($out,JSON_THROW_ON_ERROR))>150000) throw new \InvalidArgumentException('Le document est trop volumineux (150 Ko maximum).');
        return $out;
    }
    public static function starter(): array {
        $items=[
            'Objet et articulation avec le contrat'=>'Cette charte fixe les engagements qualité du formateur pour les missions confiées par l’organisme. Chaque intervention fait également l’objet d’un contrat précisant les missions, objectifs, moyens, dates, durée, rémunération et modalités de suivi. La charte ne remplace pas ce contrat.',
            'Qualifications et préparation'=>'Le formateur communique un CV à jour, ses diplômes, habilitations et justificatifs utiles à la mission. Il maintient ses compétences, prépare ses supports et vérifie les moyens nécessaires avant la formation.',
            'Adaptation pédagogique et accessibilité'=>'Le formateur prend connaissance des objectifs et des besoins des bénéficiaires. Il adapte ses méthodes et sollicite le référent handicap pour les aménagements nécessaires. Les difficultés d’accès ou d’apprentissage sont signalées rapidement à l’organisme.',
            'Présences et traçabilité'=>'Le formateur recueille les émargements au moment de la présence effective, sans signer à la place des stagiaires. Il signale les absences, retards, départs anticipés et incidents. Les justificatifs papier sont transmis selon les procédures de l’organisme.',
            'Évaluation et amélioration continue'=>'Le formateur réalise les évaluations prévues, transmet les résultats et son bilan d’intervention. Il remonte les réclamations, difficultés et propositions d’amélioration afin de contribuer au suivi qualité.',
            'Respect des personnes et sécurité'=>'Le formateur adopte une attitude professionnelle et respectueuse. Il respecte les consignes de sécurité et prévient les comportements violents, discriminatoires ou harcelants. Il alerte sans délai l’organisme en cas d’incident.',
            'Confidentialité et supports pédagogiques'=>'Les informations et données personnelles sont utilisées uniquement pour les besoins de la mission, selon les instructions de l’organisme et les dispositions contractuelles applicables. Les accès restent personnels. Les droits d’utilisation des supports sont définis dans le contrat ; cette charte ne transfère pas leur propriété.',
            'Engagement réciproque et suivi'=>'L’organisme communique les procédures utiles et les contacts référents, coordonne les missions et contrôle leur exécution. Le formateur s’engage à respecter les exigences qualité applicables à sa prestation et à signaler toute difficulté. Toute nouvelle version est présentée pour une nouvelle acceptation.'
        ];
        return array_map(fn($title,$body)=>['title'=>$title,'body'=>$body,'children'=>[]],array_keys($items),array_values($items));
    }
}
