<?php

declare(strict_types=1);

namespace App\Service\Habilitation;

use App\Entity\{HabilitationDossier, HabilitationRevision, Utilisateur};
use App\Service\PersonalSignatureImage;

/** Approval snapshots are append-only; no document is signed implicitly. */
final class HabilitationWorkflow
{
    public function __construct(private HabilitationSchema $schema, private PersonalSignatureImage $images) {}

    public function save(HabilitationDossier $dossier, string $stage, array $input, bool $sign, Utilisateur $actor, string $image = ''): HabilitationRevision
    {
        if (!$dossier->getActive() || $dossier->getDeleted()) throw new \InvalidArgumentException('Ce dossier est désactivé.');
        if (!in_array($stage, ['avis', 'titre'], true)) throw new \InvalidArgumentException('Étape inconnue.');
        if ($stage === 'titre' && (!$dossier->getAvisToken() || !in_array($dossier->getState(), ['awaiting_company', 'published'], true))) throw new \InvalidArgumentException('L’avis du formateur doit être signé avant la décision de l’entreprise.');
        $data = $this->schema->answers($dossier->getSchema(), $input, $sign, $stage === 'titre' ? $dossier->getTrainerData() : null);
        $signature = $sign ? $this->images->normalize($image) : null;
        if ($stage === 'avis') {
            $dossier->setTrainerData($data)->setEmployerData([])->setAvisToken(null)->setTitreToken(null)->setState('draft');
        } else {
            $dossier->setEmployerData($data)->setTitreToken(null)->setState('awaiting_company');
        }
        $revision = $this->record($dossier, $actor, $sign ? $stage : 'edit_'.$stage, [
            'data' => $data, 'signature' => $signature, 'schema' => $dossier->getSchema(),
            'identity' => $this->identity($dossier), 'signer' => trim($actor->getPrenom().' '.$actor->getNom()),
            'avisToken' => $dossier->getAvisToken(),
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
        ];
    }
}
