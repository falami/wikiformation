<?php

declare(strict_types=1);

namespace App\Tests\Unit\Habilitation;

use App\Entity\{Entite, Entreprise, HabilitationDossier, HabilitationRevision};
use App\Service\Habilitation\{HabilitationDocument, HabilitationSchema};
use App\Service\Pdf\OrganisationBranding;
use PHPUnit\Framework\TestCase;
use Smalot\PdfParser\Parser;
use Twig\{Environment, Loader\FilesystemLoader};

final class HabilitationDocumentTest extends TestCase
{
    public function testTitleUsesSignedEmployerIdentityScopeAndTrainerRestrictions(): void
    {
        $revision = $this->revision();
        $snapshot = $revision->getSnapshot();
        $snapshot['identity']['companyBranding'] = ['name'=>'Employeur signé', 'address'=>"10 rue Exemple\n75001 Paris", 'siret'=>'12345678900001', 'logoDataUri'=>'file:///etc/passwd'];
        $snapshot['trainerReference'] = ['restrictions'=>'Aucune intervention sous tension.', 'observations'=>'Périmètre limité au site évalué.'];
        $revision->setSnapshot($snapshot);
        $pdf = (new Parser())->parseContent($this->document()->bytes($revision));
        $text = $pdf->getText();
        self::assertStringContainsString('Titre d’habilitation', $text);
        self::assertStringContainsString('Employeur signé', $text);
        self::assertStringContainsString('10 rue Exemple', $text);
        self::assertStringContainsString('12345678900001', $text);
        self::assertStringContainsString('Aucune intervention sous tension.', $text);
        self::assertStringContainsString('Périmètre limité au site évalué.', $text);
        self::assertStringContainsString('B0', $text);
        self::assertStringNotContainsString('Chargé de consignation', $text);
        self::assertStringNotContainsString('Identité vivante', $text);
        self::assertStringNotContainsString('VERSION ARCHIVÉE', $text);
        self::assertSame($snapshot, $revision->getSnapshot(), 'Rendering must not modify the signed snapshot.');
        $page = $pdf->getPages()[0]->getDetails()['MediaBox'];
        self::assertLessThan((float) $page[3], (float) $page[2], 'The employer title must use portrait A4.');
    }

    public function testOlderTitleWithoutBrandingRemainsReadableAndClearlyArchived(): void
    {
        $revision = $this->revision();
        $revision->getDossier()->setTitreToken(null)->setActive(false);
        $text = (new Parser())->parseContent($this->document()->bytes($revision))->getText();
        self::assertStringContainsString('Employeur historique', $text);
        self::assertStringContainsString('VERSION ARCHIVÉE', $text);
        self::assertStringContainsString('DOSSIER DÉSACTIVÉ', $text);
        self::assertStringNotContainsString('Identité vivante', $text);
    }

    private function document(): HabilitationDocument
    {
        $root = dirname(__DIR__, 3);
        return new HabilitationDocument(new Environment(new FilesystemLoader($root.'/templates'), ['strict_variables'=>true]), new OrganisationBranding($root));
    }

    private function revision(): HabilitationRevision
    {
        $schema = new HabilitationSchema();
        $data = $schema->answers($schema->example(), ['function'=>'Technicien', 'assignment'=>'Site de Paris', 'issuedAt'=>'2026-10-29', 'validUntil'=>'2029-10-29', 'signerFunction'=>'Directrice', 'place'=>'Paris', 'rows'=>['row_0'=>['verdict'=>'favorable', 'symbols'=>['B0'], 'voltage'=>['BT'], 'works'=>'Installations du site de Paris']]], false);
        $dossier = (new HabilitationDossier())->setEntite((new Entite())->setNom('Organisme vivant'))
            ->setEntreprise((new Entreprise())->setRaisonSociale('Identité vivante'))
            ->setTitreToken(str_repeat('a', 32));
        return (new HabilitationRevision())->setDossier($dossier)->setKind('titre')->setToken(str_repeat('a', 32))
            ->setCreatedAt(new \DateTimeImmutable('2026-10-29 10:30:00 Europe/Paris'))->setSnapshot([
                'schema'=>$schema->example(), 'data'=>$data, 'signature'=>'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a3ioAAAAASUVORK5CYII=',
                'signer'=>'Camille Martin', 'avisToken'=>str_repeat('b', 32),
                'identity'=>['company'=>'Employeur historique', 'trainee'=>'Alex Dupont', 'formation'=>'Habilitation électrique B0', 'session'=>'SES-EXEMPLE', 'organisme'=>'Centre de formation historique'],
            ]);
    }
}
