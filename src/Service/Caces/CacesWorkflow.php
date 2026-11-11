<?php

declare(strict_types=1);
namespace App\Service\Caces;
use App\Entity\CacesAttempt;

final class CacesWorkflow
{
    public function __construct(private CacesRules $rules) {}
    public function deadline(CacesAttempt $a): ?\DateTimeImmutable { return $a->getStartedAt()?->modify('+60 minutes'); }
    public function expire(CacesAttempt $a, \DateTimeImmutable $now): bool
    {
        if (!$a->getSubmittedAt() && $this->deadline($a) && $now >= $this->deadline($a)) {
            $a->setTheoryResult($this->rules->grade($a->getExam()->getTheorySnapshot()['schema'],$a->getAnswers()))->setSubmittedAt($this->deadline($a));
            return true;
        }
        return false;
    }
    public function saveAnswers(CacesAttempt $a,array $answers,\DateTimeImmutable $now,bool $submit=false): void
    {
        if ($this->expire($a,$now) || $a->getSubmittedAt()) return;
        if (!$a->getStartedAt()) throw new \DomainException('Démarrez le test avant de répondre.');
        $valid=[];
        foreach ($a->getExam()->getTheorySnapshot()['schema']['themes'] as $t) foreach ($t['questions'] as $q) {
            $answer=$answers[$q['id']] ?? null;
            if ($answer!==null && !in_array($answer,['A','B'],true)) throw new \InvalidArgumentException('Une seule réponse A ou B est autorisée.');
            if ($answer!==null) $valid[$q['id']]=$answer;
        }
        $a->setAnswers($valid);
        if ($submit) $a->setTheoryResult($this->rules->grade($a->getExam()->getTheorySnapshot()['schema'],$valid))->setSubmittedAt($now);
    }
}
