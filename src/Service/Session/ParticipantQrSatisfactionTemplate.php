<?php

declare(strict_types=1);

namespace App\Service\Session;

use App\Entity\{SatisfactionChapter, SatisfactionQuestion, SatisfactionTemplate, Session};
use App\Enum\SatisfactionQuestionType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** A stable questionnaire per organisation for sessions without a configured catalogue model. */
final class ParticipantQrSatisfactionTemplate
{
    public const SYSTEM_KEY = 'participant_qr_standard_v1';

    public function __construct(private readonly EntityManagerInterface $em) {}

    public function forSession(Session $session): SatisfactionTemplate
    {
        $entite = $session->getEntite();
        if (!$entite?->getId() || !$session->getCreateur()) throw new \InvalidArgumentException('La session doit appartenir à un organisme enregistré.');
        $configured = $session->getFormation()?->getSatisfactionTemplate();
        if ($configured && $configured->getEntite()?->getId() === $entite->getId() && $configured->isActive() && $this->hasQuestions($configured)) return $configured;

        // Serialise first-time creation across sessions and PHP workers for this organisation.
        return $this->em->wrapInTransaction(function () use ($session, $entite): SatisfactionTemplate {
            $this->em->lock($entite, LockMode::PESSIMISTIC_WRITE);
            $existing = $this->em->getRepository(SatisfactionTemplate::class)->createQueryBuilder('t')
                ->where('t.entite = :entite')->andWhere('t.systemKey = :key')
                ->setParameter('entite', $entite)->setParameter('key', self::SYSTEM_KEY)
                ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();
            if ($existing) return $existing;

            $template = (new SatisfactionTemplate())->setEntite($entite)->setCreateur($session->getCreateur())
                ->setSystemKey(self::SYSTEM_KEY)->setTitre('Appréciation de la formation — questionnaire standard');
            $chapter = (new SatisfactionChapter())->setEntite($entite)->setCreateur($session->getCreateur())->setTitre('Votre avis sur la formation')->setPosition(1);
            $template->addChapter($chapter);
            foreach ([
                ['Globalement, êtes-vous satisfait(e) de cette formation ?', 'overall_rating'],
                ['Comment évaluez-vous l’organisation de la formation ?', 'organism_rating'],
                ['Comment évaluez-vous la pédagogie du formateur ?', 'trainer_rating'],
            ] as $position => [$label, $metric]) {
                $chapter->addQuestion((new SatisfactionQuestion())->setLibelle($label)->setType(SatisfactionQuestionType::SCALE)
                    ->setMaxStars(null)->setMetricKey($metric)->setMetricMax(10)->setMinValue(0)->setMaxValue(10)
                    ->setPosition($position + 1)->setRequired(true));
            }
            $chapter->addQuestion((new SatisfactionQuestion())->setLibelle('Vos remarques et suggestions')->setType(SatisfactionQuestionType::TEXTAREA)
                ->setMaxStars(null)->setPosition(4)->setRequired(false)->setPlaceholder('Ce que vous avez apprécié, ce qui pourrait être amélioré…'));
            $this->em->persist($template);
            return $template;
        });
    }

    private function hasQuestions(SatisfactionTemplate $template): bool
    {
        foreach ($template->getChapters() as $chapter) {
            if (!$chapter->getQuestions()->isEmpty()) return true;
        }
        return false;
    }
}
