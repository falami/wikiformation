<?php

declare(strict_types=1);
namespace App\Service\Bpf;

use App\Entity\{Entite, Session, SessionJour, Inscription, Facture, Avoir, Depense, Emargement, SessionParticipantAccess};
use App\Enum\{StatusSession, StatusInscription, FactureStatus};
use Doctrine\ORM\EntityManagerInterface;

/** Builds a traceable preparation; never substitutes missing evidence with planned attendance. */
final class BpfPrefill
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function generate(Entite $entite, \DateTimeImmutable $start, \DateTimeImmutable $end, array $rules = []): array
    {
        $start = $start->setTime(0, 0);
        $end = $end->setTime(23, 59, 59);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $cutoff = min($end, $now);
        $data = []; $issues = []; $fields = []; $evidence = []; $nsf = []; $trainers = []; $incomplete = [];
        foreach (BpfSchema::fields() as $key => [, $type]) {
            if (in_array($type, ['integer', 'money'], true)) $data[$key] = 0;
        }
        for ($n = 1; $n <= 5; ++$n) { $data['f4_'.$n.'_code'] = null; $data['f4_'.$n.'_label'] = null; }
        $data += [
            'a_name' => $entite->getNom(), 'a_nda' => $entite->getNumeroDeclarant(), 'a_legal' => $entite->getFormeJuridique(),
            'a_siret' => $entite->getSiret(), 'a_address' => implode(', ', array_filter([$entite->getAdresse(), $entite->getComplement(), trim(($entite->getCodePostal() ?? '').' '.($entite->getVille() ?? '')), $entite->getPays()])),
            'a_phone' => $entite->getTelephone(), 'a_email' => $entite->getEmail(),
            'h_name' => trim(($entite->getPrenomRepresentant() ?? '').' '.($entite->getNomRepresentant() ?? '')), 'h_role' => $entite->getFonction(),
            'h_place' => $entite->getVille(), 'h_date' => $now->format('Y-m-d'),
        ];
        $rule = function(string $key, string $label, array $choices, ?string $default = null, string $help = '') use (&$fields, $rules): ?string {
            $fields[$key] = ['label' => $label, 'choices' => $choices, 'help' => $help, 'default' => $default];
            $value = array_key_exists($key, $rules) ? $rules[$key] : $default;
            return in_array($value, array_values($choices), true) ? $value : null;
        };
        $publicChoices = ['Salarié privé (hors apprenti)' => 'a', 'Apprenti' => 'b', 'Demandeur d’emploi' => 'c', 'Particulier à ses frais' => 'd', 'Autre public' => 'e'];
        $objectives = [];
        foreach (BpfSchema::sections()['F3']['rows'] as $key => $label) if ($key !== 'f3_a') $objectives[$label] = $key;
        $nsfChoices = BpfNsf::choices();
        $sessions = $this->em->createQueryBuilder()->select('DISTINCT s')->from(Session::class, 's')->join('s.jours', 'j')
            ->where('s.entite = :entity')->andWhere('j.dateDebut <= :end AND j.dateFin >= :start')
            ->setParameter('entity', $entite)->setParameter('start', $start)->setParameter('end', $cutoff)->getQuery()->getResult();
        foreach ($sessions as $session) {
            if (in_array($session->getStatus(), [StatusSession::DRAFT, StatusSession::CANCELED], true)) continue;
            $sid = $session->getId();
            $formationLabel = $session->getFormation()?->getTitre() ?: ($session->getFormationIntituleLibre() ?: 'Formation sans intitulé');
            $label = $session->getCode().' · '.$formationLabel;
            $days = [];
            foreach ($session->getJours() as $jour) {
                if (!$jour->getDateDebut() || !$jour->getDateFin()) continue;
                foreach ($jour->getDureeFormationMinutesParDate() as $date => $minutes) {
                    $lastTime = $jour->getDateFin()->format('H:i:s');
                    if ($date < $start->format('Y-m-d') || $date > $cutoff->format('Y-m-d') || new \DateTimeImmutable($date.' '.$lastTime) > $cutoff) continue;
                    if ($minutes > 0) $days[] = [$jour, $date, $minutes];
                }
            }
            if (!$days) continue;
            $signed = [];
            foreach ($this->em->getRepository(Emargement::class)->findBy(['session' => $session, 'entite' => $entite]) as $sign) {
                if (in_array($sign->getRole(), ['trainer', 'formateur'], true) || !($sign->getSignedAt() || $sign->getSignaturePath() || $sign->getSignatureDataUrl()) || !$sign->getDateJour()) continue;
                $uid = $sign->getUtilisateur()?->getId() ?? $sign->getParticipantAccess()?->getInscription()?->getStagiaire()?->getId();
                if ($uid) $signed[$uid][$sign->getDateJour()->format('Y-m-d').':'.$sign->getPeriode()->value] = true;
            }
            $formationKey = 'rule_formation_'.($session->getFormation()?->getId() ?? 'session_'.$sid);
            $objective = $code = null;
            if (!$session->isSousTraitance()) {
                $objective = $rule($formationKey.'_objective', $formationLabel.' · Objectif BPF', $objectives);
                $code = $rule($formationKey.'_nsf', $formationLabel.' · Code NSF', $nsfChoices);
            }
            $remote = $rule('rule_session_'.$sid.'_remote', $label.' · Formation à distance', ['Présentiel intégral' => 'no', 'Tout ou partie à distance' => 'yes'], self::remoteDefault($session), 'Repris du champ « Enseignement à distance (%) » de la session : 0 % = présentiel intégral. Les modalités d’évaluation de la formation ne déterminent pas ce choix.');
            if ($remote === 'yes') $data['b_remote'] = 'yes';
            elseif ($remote === null) $issues[] = $label.' : renseignez « Enseignement à distance (%) » dans la session (0 % pour le présentiel), ou choisissez la modalité dans les règles de génération du BPF. Le champ « Modalités d’évaluation » de la formation est distinct.';
            $outsourced = $rule('rule_session_'.$sid.'_outsourced', $label.' · Activité confiée à un autre organisme (F2)', ['Aucune' => 'no', 'Intégralement confiée' => 'yes', 'Partiellement confiée : ajuster F2' => 'partial']);
            if (!$session->isSousTraitance() && !in_array($outsourced, ['no','yes'], true)) $incomplete['f2'] = true;
            if (!$session->isSousTraitance() && !in_array($outsourced, ['no','yes'], true)) $issues[] = $label.' : sous-traitance confiée à préciser pour F2 (ou ventiler manuellement si partielle).';
            $externalAttendance = $session->isSousTraitance() && $rule(
                'rule_session_'.$sid.'_external_attendance',
                $label.' · Présences attestées par le donneur d’ordre (G)',
                ['Utiliser les présences renseignées dans la plateforme' => 'local', 'Je confirme la présence complète des inscrits selon les justificatifs du donneur d’ordre' => 'complete'],
                'local',
                'La confirmation reprend les heures planifiées passées de cet exercice pour les inscrits actifs. Conservez les justificatifs. En cas de présence partielle, renseignez les présences réelles.'
            ) === 'complete';
            $participations = 0; $actualMinutes = 0; $unknown = 0; $delivered = [];
            foreach ($session->getInscriptions() as $inscription) {
                if ($inscription->getEntite()?->getId() !== $entite->getId() || !$inscription->getStagiaire() || in_array($inscription->getStatus(), [StatusInscription::ANNULE, StatusInscription::ABSENT, StatusInscription::PREINSCRIT], true)) continue;
                [$minutes, $missing] = $this->attendance($inscription, $days, $signed[$inscription->getStagiaire()->getId()] ?? [], $externalAttendance);
                $unknown += $missing;
                if ($missing) foreach ($session->isSousTraitance() ? ['g'] : ['f1', 'f2', 'f3', 'f4'] as $section) $incomplete[$section] = true;
                if ($missing) $issues[] = $label.' · inscription #'.$inscription->getId().' : '.$missing.' créneau(x) sans durée de présence vérifiable. '.($session->isSousTraitance() ? 'Confirmez les présences attestées par le donneur d’ordre dans les correspondances BPF, ou renseignez les présences réelles.' : 'Complétez les émargements puis regénérez.');
                if ($minutes <= 0) continue;
                // A presence somewhere in the session is not evidence for its other dates.
                foreach ($days as $index => $day) {
                    [$attended] = $this->attendance($inscription, [$day], $signed[$inscription->getStagiaire()->getId()] ?? [], $externalAttendance);
                    $delivered[$index] = max($delivered[$index] ?? 0, $attended);
                }
                ++$participations; $actualMinutes += $minutes;
                if ($session->isSousTraitance()) { ++$data['g_count']; $data['g_hours'] += $minutes / 60; continue; }
                $u = $inscription->getStagiaire();
                $category = $rule('rule_inscription_'.$inscription->getId().'_public', $label.' · '.$u->getPrenom().' '.$u->getNom().' · Public', $publicChoices,
                    null,
                    'Le mode de financement ne prouve pas le statut du stagiaire : confirmez notamment salarié privé, apprenti ou agent public.');
                if ($category) { ++$data['f1_'.$category.'_count']; $data['f1_'.$category.'_hours'] += $minutes / 60; }
                else { $incomplete['f1'] = true; $issues[] = $label.' · inscription #'.$inscription->getId().' : public non classé dans F1.'; }
                if ($objective) {
                    ++$data[$objective.'_count']; $data[$objective.'_hours'] += $minutes / 60;
                    if (str_starts_with($objective, 'f3_a')) { ++$data['f3_a_count']; $data['f3_a_hours'] += $minutes / 60; }
                } else { $incomplete['f3'] = true; $issues[] = $formationLabel.' : objectif non classé dans F3.'; }
                if ($code) { $nsf[$code] ??= ['count' => 0, 'minutes' => 0]; ++$nsf[$code]['count']; $nsf[$code]['minutes'] += $minutes; }
                else { $incomplete['f4'] = true; $issues[] = $formationLabel.' : code NSF manquant pour F4.'; }
                if ($outsourced === 'yes') { ++$data['f2_count']; $data['f2_hours'] += $minutes / 60; }
            }
            $anonymous = $this->em->getRepository(SessionParticipantAccess::class)->count(['session' => $session, 'entite' => $entite, 'inscription' => null]);
            if ($anonymous) foreach ($session->isSousTraitance() ? ['g'] : ['f1', 'f2', 'f3', 'f4'] as $section) $incomplete[$section] = true;
            if ($anonymous) $issues[] = $label.' : '.$anonymous.' participant(s) sans inscription, à rattacher pour intégrer leurs présences au BPF.';
            // Only delivered slots evidenced by trainees are candidates for trainer hours.
            if ($participations > 0) foreach ($days as $index => [$jour, $date, $minutes]) {
                $minutes = $delivered[$index] ?? 0;
                if ($minutes <= 0) continue;
                $trainer = $jour->getFormateur() ?? $session->getFormateur();
                if (!$trainer || $trainer->getEntite()?->getId() !== $entite->getId()) { $incomplete['e'] = true; $issues[] = $label.' : intervenant manquant pour E.'; continue; }
                $id = $trainer->getId(); $u = $trainer->getUtilisateur();
                $type = $rule('rule_trainer_'.$id, 'Formateur #'.$id.' · '.trim(($u?->getPrenom() ?? '').' '.($u?->getNom() ?? '')), ['Personne de l’organisme' => 'internal', 'Intervenant extérieur / prestataire' => 'external']);
                if (!$type) { $incomplete['e'] = true; $issues[] = 'Formateur #'.$id.' : statut interne / externe à préciser pour E.'; continue; }
                $trainers[$type][$id] = true;
                $data['e_'.$type.'_hours'] += $minutes / 60;
            }
            if ($participations > 0) $issues[] = $label.' : les heures du cadre E sont proposées par créneau avec une présence stagiaire documentée, dans la limite de la durée suivie. Vérifiez les heures réellement dispensées par chaque intervenant (notamment les présences partielles et la coanimation).';
            if ($session->isSousTraitance() && $session->getInscriptions()->isEmpty()) {
                $incomplete['g'] = true;
                $issues[] = $label.' : aucun stagiaire inscrit. Renseignez les inscriptions ou les effectifs et heures-stagiaires réels du cadre G ; la capacité de la session ne constitue pas un effectif.';
            }
            $evidence[] = ['kind' => $session->isSousTraitance() ? 'Sous-traitance reçue · G' : 'Session', 'label' => $label, 'detail' => $participations.' participation(s), '.round($actualMinutes / 60, 2).' heures-stagiaires justifiées'.($externalAttendance ? ' · présence complète confirmée selon le donneur d’ordre' : '').($unknown ? ' · présences incomplètes' : '')];
        }
        foreach (['internal','external'] as $type) $data['e_'.$type.'_count'] = count($trainers[$type] ?? []);
        if (!isset($data['b_remote']) && $sessions && !array_filter($fields, fn($f, $k) => str_ends_with($k, '_remote') && (($rules[$k] ?? $f['default']) === null), ARRAY_FILTER_USE_BOTH)) $data['b_remote'] = 'no';
        uasort($nsf, fn($a, $b) => $b['count'] <=> $a['count'] ?: $b['minutes'] <=> $a['minutes']);
        $i = 0;
        foreach ($nsf as $code => $values) {
            $key = ++$i <= 5 ? 'f4_'.$i : 'f4_other';
            if ($i <= 5) { $data[$key.'_code'] = (string) $code; $data[$key.'_label'] = BpfNsf::LABELS[$code]; }
            $data[$key.'_count'] += $values['count']; $data[$key.'_hours'] += $values['minutes'] / 60;
        }
        $financialChoices = [];
        foreach (BpfSchema::sections()['C']['fields'] as $k => [$label]) if ($k !== 'c_share') $financialChoices[$label] = $k;
        $financialChoices['Hors activité de formation (exclure)'] = 'exclude';
        $financial = []; $invoiceBuckets = []; $allInvoiceCents = 0; $classifiedFinance = true;
        $periodRecords = fn(string $class, string $date) => $this->em->createQueryBuilder()->select('r')->from($class, 'r')->where('r.entite = :entity')->andWhere('r.'.$date.' BETWEEN :start AND :end')->setParameter('entity', $entite)->setParameter('start', $start)->setParameter('end', $cutoff)->getQuery()->getResult();
        foreach ($periodRecords(Facture::class, 'dateEmission') as $invoice) {
            if ($invoice->getStatus() === FactureStatus::CANCELED) continue;
            if ($invoice->getDevise() !== 'EUR') { $classifiedFinance = false; $issues[] = $invoice->getNumero().' : devise étrangère à convertir dans C.'; continue; }
            $allInvoiceCents += $this->invoiceHt($invoice);
            $default = null;
            $bucket = $rule('rule_invoice_'.$invoice->getId(), $invoice->getNumero().' · '.number_format($this->invoiceHt($invoice)/100,2,',',' ').' € HT · Origine des produits', $financialChoices, $default,
                'Pour une facture mixte, ventilez ses montants directement dans le cadre C.');
            $invoiceBuckets[$invoice->getId()] = $bucket;
            if (!$bucket) { $classifiedFinance = false; $issues[] = $invoice->getNumero().' : financeur / dispositif à classer dans C.'; }
            elseif ($bucket !== 'exclude') $financial[$bucket] = ($financial[$bucket] ?? 0) + $this->invoiceHt($invoice);
            $evidence[] = ['kind' => 'Facture', 'label' => $invoice->getNumero(), 'detail' => number_format($this->invoiceHt($invoice)/100,2,',',' ').' € HT · '.($bucket ?? 'À classer')];
        }
        foreach ($periodRecords(Avoir::class, 'dateEmission') as $credit) {
            $invoice = $credit->getFactureOrigine();
            if (!$invoice || $invoice->getEntite()?->getId() !== $entite->getId() || $invoice->getDevise() !== 'EUR') { $classifiedFinance = false; $issues[] = 'Avoir '.$credit->getNumeroOrNull().' : facture d’origine non exploitable.'; continue; }
            if ($invoice->getStatus() === FactureStatus::CANCELED) { $classifiedFinance = false; $issues[] = 'Avoir '.$credit->getNumeroOrNull().' : facture annulée, vérifiez le traitement comptable du couple facture / avoir.'; continue; }
            $bucket = $invoiceBuckets[$invoice->getId()] ?? $rule('rule_invoice_'.$invoice->getId(), $invoice->getNumero().' · Origine de l’avoir', $financialChoices, null);
            // A full reversal has an exact HT amount. Partial mixed-VAT credits cannot be prorated safely.
            $ht = $credit->getMontantTtcCents() === $invoice->getMontantTtcCents() ? $this->invoiceHt($invoice) : null;
            if ($ht !== null) $allInvoiceCents -= $ht;
            if ($ht === null) $classifiedFinance = false;
            if ($bucket === 'exclude') continue;
            if ($ht === null || !$bucket) { $classifiedFinance = false; $issues[] = 'Avoir '.$credit->getNumeroOrNull().' : montant HT ou ventilation à déduire manuellement dans C.'; continue; }
            $financial[$bucket] = ($financial[$bucket] ?? 0) - $ht;
            $evidence[] = ['kind' => 'Avoir', 'label' => $credit->getNumeroOrNull(), 'detail' => '-'.number_format($ht/100,2,',',' ').' € HT · '.$bucket];
        }
        foreach ($financial as $key => $cents) { if ($cents < 0) { $data[$key] = null; $issues[] = $key.' : solde net négatif, à rapprocher de la comptabilité.'; } else $data[$key] = (int) round($cents / 100); }
        if ($classifiedFinance && $allInvoiceCents > 0 && array_sum($financial) >= 0 && array_sum($financial) <= $allInvoiceCents) {
            $data['c_share'] = array_sum($financial) > 0 ? max(1, (int) round(100 * array_sum($financial) / $allInvoiceCents)) : 0;
            $issues[] = 'La part formation du chiffre d’affaires est proposée sur les seules factures nettes classées de la plateforme ; confirmez-la avec le chiffre d’affaires comptable global.';
        }
        $expenses = ['d_total' => 0, 'd_salary' => 0, 'd_purchase' => 0];
        foreach ($periodRecords(Depense::class, 'dateDepense') as $expense) {
            $key = $expense->getCategorie() ? 'rule_expense_category_'.$expense->getCategorie()->getId() : 'rule_expense_'.$expense->getId();
            $bucket = $rule($key, 'Charges · '.($expense->getCategorie()?->getLibelle() ?? $expense->getLibelle()), ['Salaires formateurs (6411)' => 'd_salary', 'Achats / honoraires de formation (604 / 6226)' => 'd_purchase', 'Autres charges de formation' => 'd_total', 'Hors formation / investissement : exclure' => 'exclude']);
            if ($bucket === 'exclude') continue;
            if (!$bucket || $expense->getDevise() !== 'EUR') { $incomplete['d'] = true; $issues[] = 'Dépense #'.$expense->getId().' : catégorie BPF ou conversion en euros à préciser pour D.'; continue; }
            $expenses['d_total'] += $expense->getMontantHtCents();
            if ($bucket !== 'd_total') $expenses[$bucket] += $expense->getMontantHtCents();
            $evidence[] = ['kind' => 'Dépense', 'label' => '#'.$expense->getId().' · '.$expense->getLibelle(), 'detail' => number_format($expense->getMontantHtCents()/100,2,',',' ').' € HT · '.$bucket];
        }
        foreach ($expenses as $key => $cents) $data[$key] = (int) round($cents / 100);
        // Preserve the official equality F1 = F3 = F4 when fractional hours are
        // distributed differently between publics, objectives and NSF codes.
        foreach (['f1' => ['a','b','c','d','e'], 'f3' => ['a','b','c','d','e','f'], 'f4' => ['1','2','3','4','5','other']] as $prefix => $suffixes) {
            $keys = array_map(fn($suffix) => $prefix.'_'.$suffix.'_hours', $suffixes);
            $data = array_replace($data, BpfRounding::distribution(array_intersect_key($data, array_flip($keys))));
        }
        $rncpKeys = array_map(fn($suffix) => 'f3_a'.$suffix.'_hours', ['68','5','4','3','2','cqp']);
        $data = array_replace($data, BpfRounding::distribution(array_intersect_key($data, array_flip($rncpKeys)), $data['f3_a_hours']));
        foreach ($data as $key => $value) if (str_ends_with($key, '_hours')) $data[$key] = (int) round($value);
        if (!$classifiedFinance) $incomplete['c'] = true;
        // Unknown classifications must not turn untouched categories into declared zeroes.
        // Known subtotals remain visible, while blanks keep totals visibly incomplete.
        foreach (array_keys($incomplete) as $section) {
            foreach ($data as $key => $value) {
                if (str_starts_with($key, $section.'_') && $value === 0) $data[$key] = null;
            }
            $issues[] = 'Cadre '.strtoupper($section).' incomplet : les valeurs connues sont partielles ; les rubriques sans résultat restent à compléter, et ne valent pas zéro.';
        }
        if (isset($incomplete['d'])) $data['d_total'] = null;
        $issues[] = 'F1 : confirmez le statut réel des publics. C : classez le payeur et le dispositif de chaque facture, y compris les restes à charge et financements mixtes. Les choix enregistrés sont réutilisés. C et D : propositions calculées par date de pièce ; rapprochez les écritures de clôture, la paie, les charges externes et les produits hors plateforme. Les zéros ne prouvent pas, à eux seuls, l’absence d’activité.';
        if (!$sessions) $issues[] = 'Aucune session avec créneau passé dans la période : confirmez l’absence d’activité ou complétez les données importées.';
        $issues[] = 'Le code NAF, le consentement de publication, la part formation du chiffre d’affaires et le signataire restent à confirmer lorsqu’ils ne sont pas renseignés.';
        return ['data' => $data, 'rules' => $fields, 'issues' => array_values(array_unique($issues)), 'evidence' => $evidence, 'incompleteSections' => array_map('strtoupper', array_keys($incomplete)), 'at' => $now->format(DATE_ATOM)];
    }

    private function invoiceHt(Facture $invoice): int
    {
        return $invoice->getLignes()->isEmpty() ? $invoice->getMontantHtCents() : $invoice->getMontantHtHorsDeboursCents();
    }

    /** @return array{float,int} Known minutes and number of unresolved slots. */
    public static function remoteDefault(Session $session): ?string
    {
        $rate = $session->getTauxDistanciel();
        return $rate === null || $rate < 0 || $rate > 100 ? null : ($rate > 0 ? 'yes' : 'no');
    }

    public function attendance(Inscription $i, array $days, array $online, bool $externalComplete = false): array
    {
        $minutes = 0; $missing = 0; $manual = $i->getPresencesManuelles();
        foreach ($days as [$jour, $date, $net]) {
            $start = $jour->getDateDebut()->format('H:i'); $end = $jour->getDateFin()->format('H:i');
            // Ambiguous overnight planning cannot be mapped to the current half-day attendance model.
            if ($end <= $start) { ++$missing; continue; }
            $periods = [];
            if ($start < '13:00') $periods[] = 'AM';
            if ($end > '13:00') $periods[] = 'PM';
            $states = [];
            foreach ($periods as $period) {
                $key = $date.':'.$period; $status = $manual[$key]['status'] ?? null;
                $states[] = isset($online[$key]) || $status === 'paper' ? true : ($status === 'absent' || $i->getStatus() === StatusInscription::ABSENT ? false : ($externalComplete ? true : null));
            }
            if (in_array(null, $states, true)) { ++$missing; continue; }
            if (!in_array(false, $states, true)) { $minutes += $net; continue; }
            if (!in_array(true, $states, true)) continue;
            // Split presence is exact only when the slot has no unallocated break.
            $gross = (strtotime($date.' '.$end) - strtotime($date.' '.$start)) / 60;
            if (abs($gross - $net) > 0.001) { ++$missing; continue; }
            $am = max(0, (strtotime($date.' 13:00') - strtotime($date.' '.$start)) / 60);
            $minutes += $states[0] ? $am : $net - $am;
        }
        return [$minutes, $missing];
    }

    /** Preserve user corrections; refresh values still equal to the last generated baseline. */
    public static function merge(array $current, array $generated, array $previous): array
    {
        foreach ($generated as $key => $value) {
            if (!isset($current[$key]) || $current[$key] === '' || (array_key_exists($key, $previous) && (string) $current[$key] === (string) $previous[$key])) $current[$key] = $value;
        }
        return $current;
    }
}
