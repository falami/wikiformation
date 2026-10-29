<?php

declare(strict_types=1);

namespace App\Service\Habilitation;

use App\Entity\{HabilitationDossier, HabilitationRevision, Utilisateur};
use App\Service\PersonalSignatureImage;
use App\Service\Pdf\CompanyBranding;
use Doctrine\ORM\EntityManagerInterface;

/** Approval snapshots are append-only; no document is signed implicitly. */
final class HabilitationWorkflow
{
    public function __construct(
        private HabilitationSchema $schema,
        private PersonalSignatureImage $images,
        private EntityManagerInterface $em,
        private CompanyBranding $companyBranding,
    ) {}

    /** The employer's ceiling comes from the immutable, currently transmitted opinion. */
    public function signedTrainerData(HabilitationDossier $dossier): array
    {
        if (!$dossier->getActive() || $dossier->getDeleted() || !$dossier->getAvisToken()
            || !in_array($dossier->getState(), ['awaiting_company', 'published'], true)) {
            throw new \InvalidArgumentException('L’avis du formateur doit être signé et transmis avant la décision de l’entreprise.');
        }
        $revision = $this->em->getRepository(HabilitationRevision::class)->findOneBy([
            'dossier' => $dossier, 'token' => $dossier->getAvisToken(), 'kind' => 'avis',
        ]);
        $snapshot = $revision?->getSnapshot() ?? [];
        $identity = $snapshot['identity'] ?? [];
        if (empty($snapshot['signature']) || !is_array($snapshot['data'] ?? null)
            || ($snapshot['schema'] ?? null) !== $dossier->getSchema()
            || ($identity['traineeId'] ?? null) !== $dossier->getInscription()?->getStagiaire()?->getId()
            || ($identity['companyId'] ?? null) !== $dossier->getEntreprise()?->getId()) {
            throw new \InvalidArgumentException('L’avis signé de référence est indisponible ou ne correspond plus à ce dossier. Une nouvelle évaluation est nécessaire.');
        }

        return $snapshot['data'];
    }

    /** Copy evaluated answers without reusing the trainer's signature details. */
    public function initialEmployerData(HabilitationDossier $dossier): array
    {
        $data = $this->signedTrainerData($dossier);
        foreach (['issuedAt', 'validUntil', 'signerFunction', 'place'] as $key) $data[$key] = '';
        foreach ($dossier->getSchema()['sections'] as $section) foreach ($section['rows'] as $row) {
            if (($data['rows'][$row['id']]['verdict'] ?? 'not_evaluated') === 'favorable') continue;
            foreach ($row['fields'] as $field) {
                if ($field['type'] === 'checkbox') $data['rows'][$row['id']][$field['id']] = [];
            }
        }

        return $data;
    }

    public function save(HabilitationDossier $dossier, string $stage, array $input, bool $sign, Utilisateur $actor, string $image = ''): HabilitationRevision
    {
        if (!$dossier->getActive() || $dossier->getDeleted()) throw new \InvalidArgumentException('Ce dossier est désactivé.');
        if (!in_array($stage, ['avis', 'titre'], true)) throw new \InvalidArgumentException('Étape inconnue.');
        $trainerReference = $stage === 'titre' ? $this->signedTrainerData($dossier) : null;
        $data = $this->schema->answers($dossier->getSchema(), $input, $sign, $trainerReference);
        $signature = $sign ? $this->images->normalize($image) : null;
        $identity = $this->identity($dossier);
        if ($stage === 'titre' && $sign) $identity['companyBranding'] = $this->companyBranding->snapshot($dossier->getEntreprise());
        if ($stage === 'avis') {
            $dossier->setTrainerData($data)->setEmployerData([])->setAvisToken(null)->setTitreToken(null)->setState('draft');
        } else {
            $dossier->setEmployerData($data)->setTitreToken(null)->setState('awaiting_company');
        }
        $revision = $this->record($dossier, $actor, $sign ? $stage : 'edit_'.$stage, [
            'data' => $data, 'signature' => $signature, 'schema' => $trainerReference !== null ? $this->schema->employerSchema($dossier->getSchema(), $trainerReference) : $dossier->getSchema(),
            'identity' => $identity, 'signer' => trim($actor->getPrenom().' '.$actor->getNom()),
            'avisToken' => $dossier->getAvisToken(),
            'trainerReference' => $trainerReference,
        ]);
        if ($sign && $stage === 'avis') $dossier->setAvisToken($revision->getToken())->setState('pending_admin');
        if ($sign && $stage === 'titre') $dossier->setTitreToken($revision->getToken())->setState('published');
        return $revision;
    }

    public function transmit(HabilitationDossier $dossier, Utilisateur $actor): HabilitationRevision
    {
        if (!$dossier->getActive() || $dossier->getDeleted() || !$dossier->getAvisToken() || $dossier->getState() !== 'pending_admin') {
            throw new \InvalidArgumentException('Seul un avis validé en attente de transmission peut être transmis.');
        }
        $dossier->setState('awaiting_company');
        return $this->record($dossier, $actor, 'transmit', ['avisToken'=>$dossier->getAvisToken()]);
    }

    public function record(HabilitationDossier $dossier, Utilisateur $actor, string $kind, array $snapshot = []): HabilitationRevision
    {
        return (new HabilitationRevision())->setDossier($dossier)->setActor($actor)->setKind($kind)
            ->setToken(bin2hex(random_bytes(16)))->setSnapshot($snapshot);
    }

    public function identity(HabilitationDossier $d): array
    {
        $i = $d->getInscription(); $s = $i->getSession(); $u = $i->getStagiaire();
        return ['trainee' => trim($u->getPrenom().' '.$u->getNom()), 'traineeId' => $u->getId(),
            'company' => $d->getEntreprise()->getRaisonSociale(), 'companyId' => $d->getEntreprise()->getId(),
            'session' => $s->getCode(), 'formation' => $s->getFormationLabel(),
            'dates' => array_map(static fn ($j) => $j->getDateDebut()?->format('d/m/Y H:i').' – '.$j->getDateFin()?->format('d/m/Y H:i'), $s->getJours()->toArray()),
            'organisme' => $d->getEntite()->getNom(), 'trainer' => implode(', ', array_map(static fn ($u) => trim($u->getPrenom().' '.$u->getNom()), $d->getFormateurs())),
            'model' => $d->getTemplate()->getTitre(),
            'durationHours' => $s->getDureeFormationHeures(),
            'organismeAddress' => trim(implode(' ', array_filter([$d->getEntite()->getAdresse(), $d->getEntite()->getCodePostal(), $d->getEntite()->getVille()]))),
            'organismeDeclaration' => $d->getEntite()->getNumeroDeclarant(),
        ];
    }
}
