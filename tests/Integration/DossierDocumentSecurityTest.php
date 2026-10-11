<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{DossierInscription, Entite, Formation, Inscription, PieceDossier, Session, Site, Utilisateur, UtilisateurEntite};
use App\Enum\PieceType;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class DossierDocumentSecurityTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Entite $entite;
    private Utilisateur $admin;
    private Utilisateur $learner;
    private Inscription $inscription;
    private Inscription $foreign;
    private PieceDossier $piece;
    private PieceDossier $foreignPiece;
    private array $database;
    private array $files = [];

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = (new Utilisateur())->setEmail('docs-admin@example.test')->setPassword('unused')->setPrenom('Admin')->setNom('Test');
        $this->learner = (new Utilisateur())->setEmail('docs-learner@example.test')->setPassword('unused')->setPrenom('Camille')->setNom('Test');
        $this->entite = (new Entite())->setNom('Organisme A')->setPublic(false)->setCreateur($this->admin);
        $other = (new Entite())->setNom('Organisme B')->setPublic(false)->setCreateur($this->admin);
        foreach ([$this->admin, $this->learner, $this->entite, $other] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $this->admin->setEntite($this->entite);
        $this->learner->setEntite($this->entite);
        foreach ([[$this->admin, 'TENANT_ADMIN'], [$this->learner, 'TENANT_STAGIAIRE']] as [$user, $role]) {
            $this->em->persist((new UtilisateurEntite())->setCreateur($this->admin)->setUtilisateur($user)->setEntite($this->entite)->setRoles([$role])->setStatus('active'));
        }
        $plan = (new \App\Entity\Billing\Plan())->setCode('doc-security')->setName('Test');
        $this->em->persist($plan);
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->entite)->setStatus('active')->setPlan($plan));
        $this->inscription = $this->inscription($this->entite, 'A');
        $this->foreign = $this->inscription($other, 'B');
        $this->piece = $this->piece($this->inscription);
        $this->foreignPiece = $this->piece($this->foreign);
        $this->em->flush();
        self::assertFalse($this->admin->isSuperAdmin());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->files as $file) if (is_file($file)) unlink($file);
        foreach (['ENV', 'SERVER'] as $i => $type) {
            if ($this->database[$i] === null) unset($GLOBALS['_'.$type]['DATABASE_URL']);
            else $GLOBALS['_'.$type]['DATABASE_URL'] = $this->database[$i];
        }
    }

    public function testForeignDossiersAndPiecesCannotBeReadOrChanged(): void
    {
        $client = $this->client();
        $client->catchExceptions(true);
        foreach ([
            ['GET', 'app_administrateur_dossier_edit', ['id' => $this->foreign->getId()]],
            ['POST', 'app_administrateur_dossier_edit', ['id' => $this->foreign->getId()]],
            ['GET', 'app_administrateur_dossier_piece_download', ['piece' => $this->foreignPiece->getId()]],
            ['POST', 'app_administrateur_dossier_piece_toggle_valid', ['piece' => $this->foreignPiece->getId()]],
            ['POST', 'app_administrateur_dossier_piece_delete', ['piece' => $this->foreignPiece->getId()]],
        ] as [$method, $route, $params]) {
            $client->request($method, $this->url($route, $params));
            self::assertSame(404, $client->getResponse()->getStatusCode(), $route);
        }
        self::assertSame(2, $this->em->getRepository(PieceDossier::class)->count([]));
        self::assertFalse($this->em->find(PieceDossier::class, $this->foreignPiece->getId())->isValide());
    }

    public function testInconsistentPieceRelationsAndTraversalAreRejected(): void
    {
        $client = $this->client(); $client->catchExceptions(true);
        $this->piece->setDossier($this->foreign->getDossier()); $this->em->flush();
        $url = $this->url('app_administrateur_dossier_piece_download', ['piece' => $this->piece->getId()]);
        $client->request('GET', $url);
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $this->piece->setDossier($this->inscription->getDossier())->setFilename('../../.env'); $this->em->flush();
        $client->request('GET', $url);
        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('DATABASE_URL', $client->getResponse()->getContent());
    }

    public function testOpeningTheFormDoesNotCreateADossierAndDownloadIsPrivate(): void
    {
        $empty = $this->inscription($this->entite, 'EMPTY');
        $this->em->flush();
        $count = $this->em->getRepository(DossierInscription::class)->count([]);
        $client = $this->client();
        $client->request('GET', $this->url('app_administrateur_dossier_edit', ['id' => $empty->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame($count, $this->em->getRepository(DossierInscription::class)->count([]));
        $client->request('GET', $this->url('app_administrateur_dossier_piece_download', ['piece' => $this->piece->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('fixture', $client->getInternalResponse()->getContent());
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    public function testAjaxUploadRejectsExecutableFilesWithoutChangingTheDossier(): void
    {
        $client = $this->client();
        $session = $this->inscription->getSession();
        $crawler = $client->request('GET', $this->url('app_administrateur_session_show', ['id' => $session->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $token = $crawler->filter('#stagUploadForm input[name="_token"]');
        $csrf = $token->attr('value');
        self::assertNotSame('', $csrf);
        $path = tempnam(sys_get_temp_dir(), 'wf-upload'); $this->files[] = $path;
        file_put_contents($path, '<?php echo "unsafe";');
        $client->request('POST', $this->url('app_administrateur_session_stagiaire_piece_upload', ['session' => $session->getId()]), [
            '_token' => $csrf, 'inscriptionId' => $this->inscription->getId(), 'type' => PieceType::CNI_RECTO->value,
        ], ['file' => new UploadedFile($path, 'fake.pdf.php', 'application/pdf', null, true)]);
        self::assertSame(422, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        self::assertSame(2, $this->em->getRepository(PieceDossier::class)->count([]));
    }

    public function testLearnerCannotRemoveAValidatedPieceThroughTheCollectionForm(): void
    {
        $this->piece->setValide(true); $this->em->flush();
        $client = $this->client($this->learner);
        $url = $this->url('app_stagiaire_dossier_edit', ['id' => $this->inscription->getId()]);
        $crawler = $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $token = $crawler->filter('input[name="dossier_inscription_stagiaire[_token]"]')->attr('value');
        $client->request('POST', $url, ['dossier_inscription_stagiaire' => ['_token' => $token, 'pieces' => []]]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Une pièce validée ne peut pas être modifiée ou supprimée.', $client->getResponse()->getContent());
        self::assertSame(2, $this->em->getRepository(PieceDossier::class)->count([]));
    }

    public function testLearnerSignatureKeepsThePdfAndRecordsItsDigest(): void
    {
        $convention = (new \App\Entity\ConventionContrat())->setEntite($this->entite)->setCreateur($this->admin)
            ->setSession($this->inscription->getSession())->setStagiaire($this->learner)->setNumero('SEC-CONV')
            ->setPdfPath('uploads/pdf/previous-preview.pdf')->addInscription($this->inscription);
        $this->em->persist($convention); $this->em->flush();
        $pdf = $this->createMock(\App\Service\Pdf\PdfManager::class);
        $pdf->method('streamPdfFromHtml')->willReturn(new \Symfony\Component\HttpFoundation\Response('%PDF-1.4 reviewed document'));
        self::getContainer()->set(\App\Service\Pdf\PdfManager::class, $pdf);
        $client = $this->client($this->learner);
        $crawler = $client->request('GET', $this->url('app_stagiaire_dossier_edit', ['id' => $this->inscription->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $token = $crawler->filter('[data-csrf]')->attr('data-csrf');
        self::assertNotSame('', $token);
        $url = $this->url('app_stagiaire_convention_esign', ['id' => $this->inscription->getId(), 'convention' => $convention->getId()]);
        $client->request('POST', $url, ['_token' => $token, 'signatureData' => 'data:image/png;base64,'.base64_encode('not an image')]);
        self::assertSame(400, $client->getResponse()->getStatusCode());
        self::assertNull($this->em->find(\App\Entity\ConventionContrat::class, $convention->getId())->getDateSignatureStagiaire());
        $client->request('GET', $this->url('app_stagiaire_convention_view', ['id' => $this->inscription->getId(), 'convention' => $convention->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->files[] = $client->getResponse()->getFile()->getPathname();
        $signature = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG0sAAAAASUVORK5CYII=';
        $client->request('POST', $url, ['_token' => $token, 'signatureData' => $signature]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $saved = $this->em->find(\App\Entity\ConventionContrat::class, $convention->getId());
        self::assertNotNull($saved->getDateSignatureStagiaire());
        self::assertStringStartsWith('private:', $saved->getPdfPath());
        $path = dirname(__DIR__, 2).'/var/storage/conventions/'.substr($saved->getPdfPath(), 8); $this->files[] = $path;
        self::assertSame('%PDF-1.4 reviewed document', file_get_contents($path));
        $revision = $this->em->getRepository(\App\Entity\ConventionRevision::class)->findOneBy(['convention' => $saved]);
        self::assertSame(hash_file('sha256', $path), $revision->getDonnees()['sourceSha256']);
        $client->request('GET', $this->url('app_stagiaire_convention_view', ['id' => $this->inscription->getId(), 'convention' => $saved->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('%PDF-1.4 reviewed document', $client->getInternalResponse()->getContent());
        $this->files[] = $client->getResponse()->getFile()->getPathname();
        $client->request('POST', $url, ['_token' => $token, 'signatureData' => $signature]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(1, $this->em->getRepository(\App\Entity\ConventionRevision::class)->count([]));
    }

    public function testExistingSensitiveUploadUrlsRequireOwnershipAndNeverExposeOrphans(): void
    {
        $company = (new \App\Entity\Entreprise())->setEntite($this->entite)->setCreateur($this->admin)->setRaisonSociale('Entreprise test');
        $signaturePath = $this->upload('emargements', '.png');
        $signature = (new \App\Entity\Emargement())->setEntite($this->entite)->setCreateur($this->admin)
            ->setSession($this->inscription->getSession())->setUtilisateur($this->learner)
            ->setPeriode(\App\Enum\DemiJournee::AM)->setRole('stagiaire')->setSignaturePath(ltrim($signaturePath, '/'));
        $proofPath = $this->upload('proofs');
        $proof = (new \App\Entity\Paiement())->setEntite($this->entite)->setCreateur($this->admin)->setJustificatif(basename($proofPath));
        $docPath = $this->upload('entreprise/docs');
        $doc = (new \App\Entity\EntrepriseDocument())->setEntite($this->entite)->setEntreprise($company)->setTitre('Document test')
            ->setFilename(basename($docPath))->setOriginalName('document.pdf')->setCreateur($this->admin)->setUploadedBy($this->admin);
        $pdfPath = $this->upload('pdf');
        $attestation = (new \App\Entity\Attestation())->setEntite($this->entite)->setCreateur($this->admin)
            ->setInscription($this->inscription)->setNumero('SEC-ATTEST')->setReussi(false)->setDateDelivrance(new \DateTimeImmutable())->setPdfPath(ltrim($pdfPath, '/'));
        foreach ([$company, $signature, $proof, $doc, $attestation] as $record) $this->em->persist($record);
        $this->em->flush();
        $paths = [$signaturePath, $proofPath, $docPath, $pdfPath];
        $client = self::getContainer()->get('test.client'); $client->disableReboot(); $client->catchExceptions(true);
        foreach ($paths as $path) {
            self::assertFileExists(dirname(__DIR__, 2).'/public'.$path);
            $client->request('GET', $path);
            self::assertSame(404, $client->getResponse()->getStatusCode(), $path);
        }
        $client->loginUser($this->admin);
        foreach ($paths as $path) {
            $client->request('GET', $path);
            self::assertSame(200, $client->getResponse()->getStatusCode(), $path);
            self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
        }
        $client->request('GET', $this->upload('pdf'));
        self::assertSame(404, $client->getResponse()->getStatusCode(), 'An existing orphan archive is not served.');
        $client->loginUser($this->learner);
        foreach ([$signaturePath => 200, $pdfPath => 200, $proofPath => 404, $docPath => 404] as $path => $expected) {
            $client->request('GET', $path);
            self::assertSame($expected, $client->getResponse()->getStatusCode(), $path);
        }
        $signature = $this->em->find(\App\Entity\Emargement::class, $signature->getId());
        $signature->setSession($this->em->find(Session::class, $this->foreign->getSession()->getId())); $this->em->flush();
        $client->request('GET', $signaturePath);
        self::assertSame(404, $client->getResponse()->getStatusCode(), 'A mismatched signature/session cannot be read.');
        $membership = $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur' => $this->learner, 'entite' => $this->entite]);
        $membership->setStatus('suspended'); $this->em->flush();
        $client->request('GET', $pdfPath);
        self::assertSame(404, $client->getResponse()->getStatusCode(), 'Suspended members lose archive access.');
    }

    public function testCompanyTrainerAndCommercialUploadsKeepTheirSpecificScope(): void
    {
        $company = (new \App\Entity\Entreprise())->setEntite($this->entite)->setCreateur($this->admin)->setRaisonSociale('Portfolio test');
        $this->learner->setEntreprise($company);
        $membership = $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur' => $this->learner, 'entite' => $this->entite]);
        $membership->setRoles(['TENANT_ENTREPRISE']);
        $path = $this->upload('entreprise/docs');
        $document = (new \App\Entity\EntrepriseDocument())->setEntite($this->entite)->setEntreprise($company)->setTitre('Document société')
            ->setFilename(basename($path))->setOriginalName('societe.pdf')->setCreateur($this->admin)->setUploadedBy($this->admin);
        $trainer = (new \App\Entity\Formateur())->setEntite($this->entite)->setCreateur($this->admin)->setUtilisateur($this->learner);
        $contractPath = $this->upload('pdf');
        $contract = (new \App\Entity\ContratFormateur())->setEntite($this->entite)->setCreateur($this->admin)
            ->setFormateur($trainer)->setSession($this->inscription->getSession())->setNumero('SEC-TRAINER')->setPdfPath(ltrim($contractPath, '/'));
        foreach ([$company, $document, $trainer, $contract] as $record) $this->em->persist($record);
        $this->em->flush();
        $client = $this->client($this->learner); $client->catchExceptions(true);
        $client->request('GET', $path); self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->request('GET', $contractPath); self::assertSame(404, $client->getResponse()->getStatusCode());
        $membership = $this->em->find(UtilisateurEntite::class, $membership->getId());
        $membership->setRoles(['TENANT_FORMATEUR']); $this->em->flush();
        $client->request('GET', $contractPath); self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->request('GET', $path); self::assertSame(404, $client->getResponse()->getStatusCode());
        $membership = $this->em->find(UtilisateurEntite::class, $membership->getId());
        $company = $this->em->find(\App\Entity\Entreprise::class, $company->getId());
        $membership->setRoles(['TENANT_COMMERCIAL']);
        $this->em->find(Session::class, $this->inscription->getSession()->getId())->setEntrepriseCliente($company); $this->em->flush();
        $client->request('GET', $contractPath); self::assertSame(404, $client->getResponse()->getStatusCode());
        $access = self::getContainer()->get(\App\Service\Delegation\DossierAccess::class);
        $membership = $this->em->find(UtilisateurEntite::class, $membership->getId());
        $access->assign($membership, 'entreprises', $company->getId(), 'edit', $this->em->find(Utilisateur::class, $this->admin->getId())); $this->em->flush();
        $client->request('GET', $contractPath); self::assertSame(200, $client->getResponse()->getStatusCode());
        $contract = $this->em->find(\App\Entity\ContratFormateur::class, $contract->getId());
        $contract->setEntite($this->em->find(Entite::class, $this->foreign->getEntite()->getId()))
            ->setSession($this->em->find(Session::class, $this->foreign->getSession()->getId())); $this->em->flush();
        $client->loginUser($this->admin);
        $client->request('GET', $contractPath); self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testOrganizationMarksAndExpensesRequireAdminAndPdfImagesUseLocalBytes(): void
    {
        $signaturePath = $this->upload('signatures/organisme', '.png');
        $stampPath = $this->upload('tampons/organisme', '.png');
        $prefs = (new \App\Entity\EntitePreferences())->setEntite($this->entite)->setCreateur($this->admin)
            ->setSignatureOrganismePath($signaturePath)->setTamponOrganismePath($stampPath);
        $expensePath = $this->upload('depense');
        $expense = (new \App\Entity\Depense())->setEntite($this->entite)->setCreateur($this->admin)->setLibelle('Justificatif test')->setJustificatifPath(basename($expensePath));
        $this->em->persist($prefs); $this->em->persist($expense); $this->em->flush();
        $client = $this->client($this->learner); $client->catchExceptions(true);
        foreach ([$signaturePath, $stampPath, $expensePath] as $path) {
            $client->request('GET', $path); self::assertSame(404, $client->getResponse()->getStatusCode());
        }
        $client->loginUser($this->admin);
        foreach ([$signaturePath, $stampPath, $expensePath] as $path) {
            $client->request('GET', $path); self::assertSame(200, $client->getResponse()->getStatusCode());
        }
        $images = self::getContainer()->get(\App\Twig\PrivateDocumentImageExtension::class);
        $data = $images->dataUri($signaturePath);
        self::assertStringStartsWith('data:image/png;base64,', $data);
        self::assertSame(file_get_contents(dirname(__DIR__, 2).'/public'.$signaturePath), base64_decode(substr($data, strpos($data, ',') + 1)));
        foreach (['../../.env', 'https://example.test/image.png', '/uploads/pdf/document.pdf', 'uploads/signatures/organisme/../../../.env', $expensePath] as $invalid) self::assertNull($images->dataUri($invalid));
        $link = dirname(__DIR__, 2).'/public/uploads/signatures/organisme/audit-security-'.bin2hex(random_bytes(8)).'.png';
        symlink(dirname(__DIR__, 2).'/public'.$stampPath, $link); $this->files[] = $link;
        self::assertNull($images->dataUri('uploads/signatures/organisme/'.basename($link)), 'No escape from the authorized directory through symlinks.');
    }

    private function upload(string $directory, string $extension = '.pdf'): string
    {
        $relative = '/uploads/'.$directory.'/audit-security-'.bin2hex(random_bytes(12)).$extension;
        $path = dirname(__DIR__, 2).'/public'.$relative;
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0750, true);
        file_put_contents($path, $extension === '.png'
            ? base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG0sAAAAASUVORK5CYII=')
            : '%PDF-1.4 protected fixture');
        $this->files[] = $path;
        return $relative;
    }

    private function inscription(Entite $entite, string $code): Inscription
    {
        $site = (new Site())->setEntite($entite)->setCreateur($this->admin)->setNom($code)->setSlug(strtolower($code));
        $formation = (new Formation())->setEntite($entite)->setCreateur($this->admin)->setTitre($code)->setSlug(strtolower($code));
        $session = (new Session())->setEntite($entite)->setCreateur($this->admin)->setSite($site)->setFormation($formation)->setCode('SEC-'.$code);
        $inscription = (new Inscription())->setSession($session)->setEntite($entite)->setCreateur($this->admin)->setStagiaire($this->learner);
        foreach ([$site, $formation, $session, $inscription] as $entity) $this->em->persist($entity);
        return $inscription;
    }

    private function piece(Inscription $inscription): PieceDossier
    {
        $dossier = (new DossierInscription())->setInscription($inscription)->setEntite($inscription->getEntite())->setCreateur($this->admin);
        $inscription->setDossier($dossier);
        $directory = self::getContainer()->getParameter('piece_dossier_dir');
        if (!is_dir($directory)) mkdir($directory, 0750, true);
        $name = 'audit-test-'.bin2hex(random_bytes(12)).'.pdf';
        file_put_contents($directory.'/'.$name, '%PDF-1.4 fixture'); $this->files[] = $directory.'/'.$name;
        $piece = (new PieceDossier())->setDossier($dossier)->setInscription($inscription)->setEntite($inscription->getEntite())->setCreateur($this->admin)->setType(PieceType::CNI_RECTO)->setFilename($name)->setUploadedAt(new \DateTimeImmutable());
        $dossier->addPiece($piece); $this->em->persist($dossier);
        return $piece;
    }

    private function client(?Utilisateur $user = null): KernelBrowser
    {
        $client = self::getContainer()->get('test.client'); $client->disableReboot(); $client->catchExceptions(false); $client->loginUser($user ?? $this->admin); return $client;
    }

    private function url(string $route, array $params = []): string
    {
        return self::getContainer()->get('router')->generate($route, ['entite' => $this->entite->getId()] + $params);
    }
}
