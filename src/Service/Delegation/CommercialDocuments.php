<?php

declare (strict_types=1);
namespace App\Service\Delegation;

use App\Entity\{UtilisateurEntite, Utilisateur, Entite, Devis, Facture, ConventionContrat, LigneDevis, LigneFacture};
use App\Form\Portail\DocumentLineType;
use App\Service\Sequence\{DevisNumberGenerator, FactureNumberGenerator, ConventionContratNumberGenerator};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\{FormFactoryInterface, FormInterface};
use Symfony\Component\Form\Extension\Core\Type\{FormType, ChoiceType, DateType, CollectionType, TextType, TextareaType, NumberType, MoneyType};
use Symfony\Component\Validator\Constraints as Assert;
final class CommercialDocuments
{
    public function __construct(private readonly FormFactoryInterface $factory, private readonly DossierAccess $access, private readonly DossierRegistry $registry, private readonly EntityManagerInterface $em, private readonly DevisNumberGenerator $quotes, private readonly FactureNumberGenerator $invoices, private readonly ConventionContratNumberGenerator $contracts)
    {
    }
    public function form(UtilisateurEntite $membership, string $module, ?object $existing = null): FormInterface
    {
        $initial = $existing ? $this->initial($existing, $membership) : ['date' => new \DateTimeImmutable(), 'validity' => new \DateTimeImmutable('+30 days'), 'lines' => [['quantity' => 1, 'price' => 0, 'vat' => 20]]];
        $builder = $this->factory->createNamedBuilder('document', FormType::class, $initial, ['csrf_token_id' => 'issue_' . $membership->getId() . '_' . $module]);
        foreach (['company' => ['entreprises', 'Entreprise destinataire'], 'learner' => ['clients', 'Client destinataire']] as $field => [$related, $label]) {
            $builder->add($field, ChoiceType::class, ['label' => $label, 'choices' => $this->access->editableRows($membership, $related), 'choice_value' => fn($r) => $r?->getId(), 'choice_label' => fn($r) => $this->registry->title($r), 'required' => false, 'placeholder' => 'Sélectionner un dossier attribué']);
        }
        $builder->add('date', DateType::class, ['label' => 'Date d’émission', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'constraints' => [new Assert\NotNull()]]);
        if ($module === 'devis') {
            $builder->add('discountPercent', NumberType::class, ['label' => 'Remise globale (%)', 'required' => false, 'constraints' => [new Assert\Range(min: 0, max: 100)]])->add('discountAmount', MoneyType::class, ['label' => 'Ou remise globale HT (€)', 'required' => false, 'divisor' => 100, 'input' => 'integer', 'constraints' => [new Assert\Range(min: 0, max: 2000000000)]]);
            $builder->add('validity', DateType::class, ['label' => 'Date de validité', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'constraints' => [new Assert\NotNull()]]);
        }
        if ($module === 'conventions') {
            $builder->add('session', ChoiceType::class, ['label' => 'Session', 'choices' => $this->access->editableRows($membership, 'sessions'), 'choice_value' => fn($r) => $r?->getId(), 'choice_label' => fn($r) => $this->registry->title($r), 'placeholder' => 'Sélectionner une session attribuée', 'constraints' => [new Assert\NotNull()]])->add('participants', ChoiceType::class, ['label' => 'Inscriptions couvertes', 'choices' => $this->access->editableRows($membership, 'inscriptions'), 'multiple' => true, 'choice_value' => fn($r) => $r?->getId(), 'choice_label' => fn($r) => $this->registry->title($r), 'constraints' => [new Assert\Count(min: 1)]])->add('terms', TextareaType::class, ['label' => 'Conditions financières', 'required' => false, 'constraints' => [new Assert\Length(max: 10000)]]);
        }
        $builder->add('lines', CollectionType::class, ['label' => 'Prestations', 'entry_type' => DocumentLineType::class, 'entry_options' => ['quote' => $module === 'devis'], 'allow_add' => true, 'allow_delete' => true, 'prototype' => true, 'constraints' => [new Assert\Count(min: 1, max: 50)]]);
        return $builder->getForm();
    }
    private function initial(object $record, UtilisateurEntite $membership): array
    {
        $company = $record instanceof ConventionContrat ? $record->getEntreprise() : $record->getEntrepriseDestinataire();
        $user = $record instanceof ConventionContrat ? $record->getStagiaire() : $record->getDestinataire();
        $learner = $user ? $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur' => $user, 'entite' => $membership->getEntite()]) : null;
        if ($record instanceof ConventionContrat) {
            return ['company' => $company, 'learner' => $learner, 'date' => new \DateTimeImmutable(), 'session' => $record->getSession(), 'participants' => $record->getInscriptions()->toArray(), 'terms' => $record->getConditionsFinancieres(), 'lines' => [['label' => $record->getIntituleFormation() ?: 'Formation', 'quantity' => 1, 'price' => $record->getMontantHtCents(), 'vat' => $record->getTauxTva()]]];
        }
        return ['company' => $company, 'learner' => $learner, 'date' => $record->getDateEmission(), 'validity' => $record->getDateValidite(), 'discountPercent' => $record->getRemiseGlobalePourcent(), 'discountAmount' => $record->getRemiseGlobaleMontantCents(), 'lines' => array_map(fn($l) => ['label' => $l->getLabel(), 'quantity' => $l->getQte(), 'price' => $l->getPuHtCents(), 'vat' => $l->getTva(), 'discountPercent' => $l->getRemisePourcent(), 'discountAmount' => $l->getRemiseMontantCents(), 'debours' => $l->isDebours()], $record->getLignes()->toArray())];
    }
    public function validate(array $data, string $module): ?string
    {
        if ((bool) ($data['company'] ?? null) === (bool) ($data['learner'] ?? null)) {
            return 'Choisissez une entreprise ou un client destinataire.';
        }
        if ($module === 'devis' && $data['validity'] < $data['date']) {
            return 'La date de validité doit être postérieure à la date d’émission.';
        }
        if ($module === 'conventions') {
            foreach ($data['participants'] as $i) {
                if ($i->getSession() !== $data['session']) {
                    return 'Toutes les inscriptions doivent appartenir à la session sélectionnée.';
                }
                if ($data['company'] && $i->getEntreprise() !== $data['company']) {
                    return 'Les inscriptions doivent être financées par l’entreprise destinataire.';
                }
                if ($data['learner'] && $i->getStagiaire() !== $data['learner']->getUtilisateur()) {
                    return 'Les inscriptions doivent concerner le client destinataire.';
                }
            }
            if (count(array_unique(array_column($data['lines'], 'vat'))) > 1) {
                return 'La convention utilise un seul taux de TVA : choisissez le même taux pour toutes les prestations.';
            }
        }
        if ($module === 'devis') {
            foreach ([$data, ...$data['lines']] as $discount) {
                if (($discount['discountPercent'] ?? 0) > 0 && ($discount['discountAmount'] ?? 0) > 0) {
                    return 'Choisissez une remise en pourcentage ou en euros, pas les deux.';
                }
            }
        }
        $total = 0;
        foreach ($data['lines'] as $line) {
            $total += (int) round($line['quantity'] * $line['price'] * (1 + $line['vat'] / 100));
        }
        if ($total > 2000000000) {
            return 'Le montant total dépasse la limite autorisée.';
        }
        return null;
    }
    public function issue(UtilisateurEntite $membership, string $module, array $data, Utilisateur $actor, ?object $existing = null): object
    {
        $data['lines'] = array_values($data['lines']);
        $entite = $membership->getEntite();
        $year = (int) $data['date']->format('Y');
        $ht = $vat = 0;
        foreach ($data['lines'] as $line) {
            $base = $line['quantity'] * $line['price'];
            $ht += $base;
            $vat += (int) round($base * $line['vat'] / 100);
        }
        if ($module === 'conventions') {
            $record = ($existing ?? new ConventionContrat())->setEntite($entite)->setCreateur($existing?->getCreateur() ?? $actor)->setNumero($existing?->getNumero() ?? $this->contracts->nextForEntite($entite->getId(), $year))->setSession($data['session'])->setEntreprise($data['company'])->setStagiaire($data['learner']?->getUtilisateur())->setIntituleFormation($data['session']->getFormation()?->getTitre() ?: $data['session']->getFormationIntituleLibre())->setMontantHtCents($ht)->setTauxTva((float) $data['lines'][0]['vat'])->setConditionsFinancieres(($existing ? '' : implode("\n", array_map(fn($l) => $l['label'] . ' · ' . $l['quantity'] . ' × ' . number_format($l['price'] / 100, 2, ',', ' ') . ' € HT', $data['lines'])) . "\n") . ($data['terms'] ?? ''));
            foreach ($record->getInscriptions()->toArray() as $previous) {
                $record->removeInscription($previous);
            }
            foreach ($data['participants'] as $i) {
                $record->addInscription($i);
            }
        } else {
            $record = $existing ?? ($module === 'devis' ? new Devis() : new Facture());
            foreach ($record->getLignes()->toArray() as $previous) {
                $record->removeLigne($previous);
                $this->em->remove($previous);
            }
            $number = $existing?->getNumero() ?? ($module === 'devis' ? $this->quotes->nextForEntite($entite->getId(), $year) : $this->invoices->nextForEntite($entite->getId(), $year));
            $record->setEntite($entite)->setCreateur($existing?->getCreateur() ?? $actor)->setNumero($number)->setDateEmission($data['date'])->setDevise('EUR')->setEntrepriseDestinataire($data['company'])->setDestinataire($data['learner']?->getUtilisateur())->setMontantHtCents($ht)->setMontantTvaCents($vat)->setMontantTtcCents($ht + $vat);
            if ($record instanceof Devis) {
                $record->setDateValidite($data['validity'])->setRemiseGlobalePourcent($data['discountPercent'] ?? null)->setRemiseGlobaleMontantCents($data['discountAmount'] ?? null);
            }
            foreach ($data['lines'] as $line) {
                $item = $module === 'devis' ? new LigneDevis() : new LigneFacture();
                $item->setEntite($entite)->setCreateur($actor)->setLabel($line['label'])->setQte($line['quantity'])->setPuHtCents($line['price']);
                if ($item instanceof LigneDevis) {
                    $item->setTva((float) $line['vat'])->setRemisePourcent($line['discountPercent'] ?? null)->setRemiseMontantCents($line['discountAmount'] ?? null)->setIsDebours($line['debours'] ?? false);
                } else {
                    $item->setTvaBp((int) round($line['vat'] * 100));
                }
                $record->addLigne($item);
                $this->em->persist($item);
            }
        }
        if ($record instanceof Devis) {
            $lines = array_values(array_filter($record->getLignes()->toArray(), fn($line) => $line->getTotalHtNetCents() > 0));
            $base = array_sum(array_map(fn($line) => $line->getTotalHtNetCents(), $lines));
            $discount = $record->getRemiseGlobaleCents();
            $remaining = $discount;
            $ht = $vat = 0;
            foreach ($lines as $index => $line) {
                $net = $line->getTotalHtNetCents();
                $share = $index === count($lines) - 1 ? $remaining : (int) round($discount * $net / $base);
                $share = min($net, $remaining, max(0, $share));
                $remaining -= $share;
                $net -= $share;
                $ht += $net;
                $vat += (int) round($net * $line->getTva() / 100);
            }
            $record->setMontantHtCents($ht)->setMontantTvaCents($vat)->setMontantTtcCents($ht + $vat);
        }
        $this->em->persist($record);
        $this->em->flush();
        $this->access->assign($membership, $module, $record->getId(), 'edit', $actor);
        return $record;
    }
}
