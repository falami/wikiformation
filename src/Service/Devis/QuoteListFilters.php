<?php

declare(strict_types=1);
namespace App\Service\Devis;

use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\InputBag;

final class QuoteListFilters
{
    public static function apply(QueryBuilder $qb, string $alias, InputBag $input): void
    {
        $recipient = trim((string) $input->get('recipientFilter', ''));
        if ($recipient !== '') {
            $qb->leftJoin($alias.'.destinataire','filterUser')->leftJoin($alias.'.entrepriseDestinataire','filterCompany')->leftJoin($alias.'.prospect','filterProspect');
            $qb->andWhere("(filterCompany.raisonSociale LIKE :recipient OR filterCompany.email LIKE :recipient OR CONCAT(CONCAT(filterUser.prenom, ' '), filterUser.nom) LIKE :recipient OR filterUser.email LIKE :recipient OR CONCAT(CONCAT(filterProspect.prenom, ' '), filterProspect.nom) LIKE :recipient OR filterProspect.societe LIKE :recipient OR filterProspect.email LIKE :recipient)")->setParameter('recipient', '%'.$recipient.'%');
        }
        $training = trim((string) $input->get('trainingFilter', ''));
        $start = self::date($input->get('trainingFrom'));
        $end = self::date($input->get('trainingTo'));
        $sessionCriteria = ['fs.entite = '.$alias.'.entite'];
        if ($training !== '') {
            $qb->setParameter('training', '%'.$training.'%');
            $sessionCriteria[] = '(ff.titre LIKE :training OR fs.formationIntituleLibre LIKE :training)';
        }
        if ($start || $end) {
            $day = 'fj.session = fs AND fj.entite = '.$alias.'.entite';
            if ($start) { $day .= ' AND fj.dateFin >= :trainingFrom'; $qb->setParameter('trainingFrom', $start); }
            if ($end) { $day .= ' AND fj.dateDebut < :trainingTo'; $qb->setParameter('trainingTo', $end->modify('+1 day')); }
            $sessionCriteria[] = 'EXISTS (SELECT fj.id FROM App\Entity\SessionJour fj WHERE '.$day.')';
        }
        if ($training !== '' || $start || $end) {
            $criteria = implode(' AND ', $sessionCriteria);
            $conventionCriteria = $criteria;
            if ($training !== '') $conventionCriteria = str_replace('(ff.titre LIKE :training OR fs.formationIntituleLibre LIKE :training)', '(fc.intituleFormation LIKE :training OR ff.titre LIKE :training OR fs.formationIntituleLibre LIKE :training)', $criteria);
            $parts = [
                'EXISTS (SELECT fc.id FROM App\Entity\ConventionContrat fc JOIN fc.session fs LEFT JOIN fs.formation ff WITH ff.entite = '.$alias.'.entite WHERE fc.devis = '.$alias.' AND fc.entite = '.$alias.'.entite AND '.$conventionCriteria.')',
                'EXISTS (SELECT fi.id FROM App\Entity\Inscription fi JOIN fi.devis fd JOIN fi.session fs LEFT JOIN fs.formation ff WITH ff.entite = '.$alias.'.entite WHERE fd = '.$alias.' AND fi.entite = '.$alias.'.entite AND '.$criteria.')',
            ];
            $parts[1] = strtr($parts[1], ['fs.'=>'fis.', ' fs '=>' fis ', 'ff.'=>'fif.', ' ff '=>' fif ', 'fj.'=>'fij.', ' fj '=>' fij ', ' = fs)'=>' = fis)', ' = fs AND'=>' = fis AND']);
            if ($training !== '' && !$start && !$end) $parts[] = 'EXISTS (SELECT directFormation.id FROM App\Entity\Formation directFormation WHERE directFormation = '.$alias.'.formation AND directFormation.entite = '.$alias.'.entite AND directFormation.titre LIKE :training)';
            $qb->andWhere('('.implode(' OR ', $parts).')');
        }
        foreach (['emissionFrom'=>'>=', 'emissionTo'=>'<'] as $key=>$operator) {
            if ($date = self::date($input->get($key))) {
                $qb->andWhere($alias.'.dateEmission '.$operator.' :'.$key)->setParameter($key, $operator === '<' ? $date->modify('+1 day') : $date);
            }
        }
    }

    private static function date(?string $value): ?\DateTimeImmutable
    {
        if (!$value) return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }
}
