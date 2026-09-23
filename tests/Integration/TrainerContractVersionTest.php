<?php

namespace App\Tests\Integration;

use App\Entity\{ContratFormateur, Entite, Formateur, Session, SessionJour, Site, Utilisateur, UtilisateurEntite};
use App\Enum\ContratFormateurStatus;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};

final class TrainerContractVersionTest extends KernelTestCase
{
    private array $originalDatabase;
    private EntityManagerInterface $em;
    private Entite $entite;
    private Utilisateur $user;
    private ContratFormateur $contract;
    private array $files = [];

    protected function setUp(): void
    {
        $this->originalDatabase = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        self::getContainer()->set(\Symfony\Component\Mailer\MailerInterface::class, $this->createMock(\Symfony\Component\Mailer\MailerInterface::class));
        $pdf = $this->createMock(\App\Service\Pdf\PdfManager::class);
        $pdf->method('createPortraitBytes')->willReturn('%PDF-1.4 fixture draft');
        self::getContainer()->set(\App\Service\Pdf\PdfManager::class, $pdf);
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->user = (new Utilisateur())->setEmail('signature@example.test')->setPassword('unused')->setPrenom('Test')->setNom('Signature')->setRoles(['ROLE_SUPER_ADMIN']);
        $this->entite = (new Entite())->setNom('Organisme signature')->setPublic(false)->setCreateur($this->user);
        $this->em->persist($this->user); $this->em->persist($this->entite); $this->em->flush();
        $this->user->setEntite($this->entite);
        $this->user->addUtilisateurEntite((new UtilisateurEntite())->setUtilisateur($this->user)->setEntite($this->entite)->setCreateur($this->user)->setRoles(['TENANT_FORMATEUR', 'TENANT_ADMIN']));
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->entite)->setStatus('active'));
        $trainer = (new Formateur())->setUtilisateur($this->user)->setEntite($this->entite)->setCreateur($this->user);
        $this->user->setFormateur($trainer);
        $site = (new Site())->setNom('Salle test')->setSlug('signature-test')->setEntite($this->entite)->setCreateur($this->user);
        $session = (new Session())->setEntite($this->entite)->setCreateur($this->user)->setCode('SIGN-TEST')->setSite($site)->setTypeFinancement(\App\Enum\TypeFinancement::OUI)->setFormationIntituleLibre('Mission sur mesure');
        foreach ([['09:00', '12:30', null], ['13:30', '17:00', $trainer]] as [$start, $end, $assigned]) {
            $session->addJour((new SessionJour())->setEntite($this->entite)->setCreateur($this->user)->setDateDebut(new \DateTimeImmutable('2026-10-05 ' . $start))->setDateFin(new \DateTimeImmutable('2026-10-05 ' . $end))->setFormateur($assigned));
        }
        $this->contract = (new ContratFormateur())->setEntite($this->entite)->setCreateur($this->user)->setSession($session)->setFormateur($trainer)->setNumero('CF-SIGN-TEST');
        foreach ([$trainer, $site, $session, $this->contract] as $entity) $this->em->persist($entity);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if ($this->em->isOpen()) {
            foreach ($this->em->getRepository(\App\Entity\ContratFormateurRevision::class)->findAll() as $revision) {
                $this->files[] = self::getContainer()->getParameter('kernel.project_dir') . '/var/storage/contrat-formateur-versions/' . $revision->getPdfFilename();
            }
        }
        foreach ($this->files as $file) if (is_file($file)) unlink($file);
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $type) {
            if ($this->originalDatabase[$index] === null) unset($GLOBALS['_' . $type]['DATABASE_URL']);
            else $GLOBALS['_' . $type]['DATABASE_URL'] = $this->originalDatabase[$index];
        }
    }

    public function testEditingDraftArchivesThePreviousValuesAndRequiresAReason(): void
    {
        $this->contract->setMontantPrevuCents(90000)->setConditionsParticulieres('Avant modification');
        $this->em->flush();
        $client = $this->client();
        $crawler = $client->request('GET', $this->url('edit'));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $form = $crawler->filter('form#contratForm')->form([
            'contrat_formateur[montantPrevuCents]' => '1200',
            'contrat_formateur[conditionsParticulieres]' => 'Après modification',
            'contrat_formateur[motifModification]' => 'Ajustement du périmètre de mission',
        ]);
        $client->submit($form);
        self::assertSame(302, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $this->reloadContract();
        self::assertSame(2, $this->contract->getVersionNumero());
        self::assertSame(120000, $this->contract->getMontantPrevuCents());
        self::assertNull($this->contract->getPdfPath());
        $revision = $this->revision();
        self::assertSame(1, $revision->getNumero());
        self::assertSame(90000, $revision->getDonnees()['montantPrevuCents']);
        self::assertSame('Avant modification', $revision->getDonnees()['conditionsParticulieres']);
        self::assertSame($this->user->getId(), $revision->getAuteur()->getId());
        $client->followRedirect();
        self::assertStringContainsString('Historique des versions', $client->getResponse()->getContent());
        self::assertStringContainsString('Ajustement du périmètre de mission', $client->getResponse()->getContent());
        self::assertSame('%PDF-1.4 fixture draft', file_get_contents(self::getContainer()->get(\App\Service\Contrat\ContratFormateurVersioning::class)->path($revision)));
    }

    public function testSignedRevisionKeepsExactPdfAndAuditAndRejectsAnOldSignatureForm(): void
    {
        $client = $this->client();
        $crawler = $client->request('GET', $this->signUrl());
        $oldSignatureToken = $crawler->filter('form#form-signature input[name="_token"]')->attr('value');
        $oldPath = $this->signedOriginal();
        $crawler = $client->request('GET', $this->url('show'));
        $form = $crawler->filter('#nouvelle-version form')->form(['motif' => 'Ajout d’une clause de mission']);
        $client->submit($form);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('/modifier', $client->getResponse()->headers->get('Location'));
        $this->reloadContract();
        self::assertSame(2, $this->contract->getVersionNumero());
        self::assertSame(ContratFormateurStatus::BROUILLON, $this->contract->getStatus());
        self::assertNull($this->contract->getSignatureDataUrl());
        self::assertNull($this->contract->getSignatureAt());
        self::assertNull($this->contract->getSignatureOrganismeAt());
        self::assertNull($this->contract->getSignatureIp());
        $revision = $this->revision();
        self::assertSame('SIGNE', $revision->getDonnees()['status']);
        self::assertSame('data:image/png;base64,original', $revision->getDonnees()['signatureDataUrl']);
        self::assertSame('127.0.0.1', $revision->getDonnees()['signatureIp']);
        $path = self::getContainer()->get(\App\Service\Contrat\ContratFormateurVersioning::class)->path($revision);
        self::assertNotSame($oldPath, $path);
        self::assertSame(file_get_contents($oldPath), file_get_contents($path));
        self::assertSame(hash_file('sha256', $oldPath), $revision->getPdfSha256());
        $client->request('POST', $this->signUrl(), ['_token' => $oldSignatureToken, 'signature_data' => 'data:image/png;base64,old-tab']);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertNull($this->contract->getSignatureAt());
        $client->request('GET', $this->url('revision_pdf', ['revision' => $revision->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('application/pdf', $client->getResponse()->headers->get('Content-Type'));
        file_put_contents($path, 'changed archive');
        $client->request('GET', $this->url('revision_pdf', ['revision' => $revision->getId()]));
        self::assertSame(409, $client->getResponse()->getStatusCode());
    }

    public function testRevisionRequiresCsrfReasonAndCurrentVersionAndProtectsOtherTenants(): void
    {
        $this->contract->setStatus(ContratFormateurStatus::ENVOYE);
        $this->em->flush();
        $client = $this->client();
        $crawler = $client->request('GET', $this->url('show'));
        $form = $crawler->filter('#nouvelle-version form')->form(['motif' => 'Changement demandé']);
        $data = $form->getPhpValues();
        $client->request('POST', $this->url('revise'), $data + ['unused' => 'value']);
        $this->reloadContract();
        self::assertSame(2, $this->contract->getVersionNumero());
        $this->contract->setStatus(ContratFormateurStatus::ENVOYE);
        $this->em->flush();
        $client->request('POST', $this->url('revise'), $data);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertCount(1, $this->em->getRepository(\App\Entity\ContratFormateurRevision::class)->findAll());
        $client->request('POST', $this->url('revise'), ['_token' => 'bad', 'motif' => 'Changement demandé', 'versionAttendue' => $this->contract->getLockVersion()]);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $crawler = $client->request('GET', $this->url('show'));
        $client->submit($crawler->filter('#nouvelle-version form')->form(['motif' => '   ']));
        self::assertCount(1, $this->em->getRepository(\App\Entity\ContratFormateurRevision::class)->findAll());
        $other = (new Entite())->setNom('Autre organisme')->setPublic(false)->setCreateur($this->em->find(Utilisateur::class, $this->user->getId()));
        $this->em->persist($other); $this->em->flush();
        $revision = $this->revision();
        foreach ([['show', 'GET', []], ['revise', 'POST', []], ['revision_pdf', 'GET', ['revision' => $revision->getId()]]] as [$route, $method, $params]) {
            $client->request($method, $this->url($route, ['entite' => $other->getId()] + $params), $data);
            self::assertSame(404, $client->getResponse()->getStatusCode());
        }
    }

    public function testAnOutdatedEditDoesNotOverwriteTheLatestDraft(): void
    {
        $client = $this->client();
        $crawler = $client->request('GET', $this->url('edit'));
        $form = $crawler->filter('form#contratForm')->form(['contrat_formateur[motifModification]' => 'Ancien onglet', 'contrat_formateur[conditionsParticulieres]' => 'Anciennes valeurs']);
        $this->reloadContract();
        // Another browser has updated the contract after the form was rendered.
        $this->contract->setConditionsParticulieres('Mise à jour récente');
        $this->em->flush();
        $client->submit($form);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('autre fenêtre', $client->getResponse()->getContent());
        $this->reloadContract();
        $this->em->refresh($this->contract);
        self::assertSame('Mise à jour récente', $this->contract->getConditionsParticulieres());
        self::assertSame(1, $this->contract->getVersionNumero());
        self::assertCount(0, $this->em->getRepository(\App\Entity\ContratFormateurRevision::class)->findAll());
    }

    public function testSignedOriginalCannotBeRevisedIfItsPdfIsMissingAndHistoryCannotBeDeleted(): void
    {
        $client = $this->client();
        $this->contract->setStatus(ContratFormateurStatus::SIGNE)->setSignatureAt(new \DateTimeImmutable());
        $this->em->flush();
        $crawler = $client->request('GET', $this->url('show'));
        $client->submit($crawler->filter('#nouvelle-version form')->form(['motif' => 'Correction']));
        self::assertSame(1, $this->contract->getVersionNumero());
        self::assertSame(ContratFormateurStatus::SIGNE, $this->contract->getStatus());
        self::assertCount(0, $this->em->getRepository(\App\Entity\ContratFormateurRevision::class)->findAll());
        $this->signedOriginal();
        $crawler = $client->request('GET', $this->url('show'));
        $client->submit($crawler->filter('#nouvelle-version form')->form(['motif' => 'Correction']));
        $crawler = $client->request('GET', $this->url('list'));
        // Obtain the normal CSRF token from the AJAX action renderer.
        $client->request('POST', $this->url('ajax'), ['draw' => 1, 'start' => 0, 'length' => 10]);
        $json = json_decode($client->getResponse()->getContent(), true);
        preg_match('/data-token="([^"]+)"/', $json['data'][0]['actions'], $match);
        self::assertNotEmpty($match[1] ?? null);
        $client->request('POST', $this->url('supprimer'), ['_token' => html_entity_decode($match[1])]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertNotNull($this->em->find(ContratFormateur::class, $this->contract->getId()));
    }

    private function signedOriginal(): string
    {
        $this->reloadContract();
        $relative = 'uploads/pdf/test-version-' . bin2hex(random_bytes(10)) . '.pdf';
        $path = self::getContainer()->getParameter('kernel.project_dir') . '/public/' . $relative;
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0770, true);
        file_put_contents($path, "%PDF-1.4\nSigned original exact bytes\n");
        $this->files[] = $path;
        $this->contract->setStatus(ContratFormateurStatus::SIGNE)->setSignatureDataUrl('data:image/png;base64,original')->setSignatureAt(new \DateTimeImmutable('2026-09-22 10:00'))
            ->setSignatureOrganismeAt(new \DateTimeImmutable('2026-09-21 10:00'))->setSignatureIp('127.0.0.1')->setPdfPath($relative);
        $this->em->flush();
        return $path;
    }
    private function reloadContract(): void
    {
        $this->contract = $this->em->find(ContratFormateur::class, $this->contract->getId());
    }
    private function revision(): \App\Entity\ContratFormateurRevision
    {
        return $this->em->getRepository(\App\Entity\ContratFormateurRevision::class)->findOneBy(['contrat' => $this->contract]);
    }
    private function client(): KernelBrowser
    {
        $client = new KernelBrowser(self::$kernel); $client->disableReboot(); $client->loginUser($this->user); return $client;
    }
    private function url(string $suffix, array $parameters = []): string
    {
        return self::getContainer()->get('router')->generate('app_administrateur_formateurs_contrats_' . $suffix, $parameters + ['entite' => $this->entite->getId(), 'id' => $this->contract->getId()]);
    }
    private function signUrl(): string
    {
        return self::getContainer()->get('router')->generate('app_formateur_contrat_sign', ['entite' => $this->entite->getId(), 'contrat' => $this->contract->getId()]);
    }
}
