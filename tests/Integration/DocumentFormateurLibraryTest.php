<?php

namespace App\Tests\Integration;

use App\Entity\{DocumentFormateur, Entite, Formateur, Utilisateur, UtilisateurEntite};
use App\Entity\Billing\{EntiteSubscription, Plan};
use App\Service\Document\DocumentFormateurStorage;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Private documents are tested against an ephemeral database and temporary files only. */
final class DocumentFormateurLibraryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Entite $entite;
    private Entite $otherEntite;
    private Utilisateur $admin;
    private Utilisateur $trainer;
    private DocumentFormateurStorage $storage;
    private string $directory;
    private array $originalDatabase;

    protected function setUp(): void
    {
        $this->originalDatabase = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->directory = sys_get_temp_dir() . '/wikiformation-documents-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->storage = new DocumentFormateurStorage($this->directory . '/private');
        self::getContainer()->set(DocumentFormateurStorage::class, $this->storage);

        $this->admin = (new Utilisateur())->setEmail('documents.admin@example.test')->setPassword('unused')->setPrenom('Admin')->setNom('Documents');
        $this->entite = (new Entite())->setNom('Organisme documents')->setPublic(false)->setCreateur($this->admin);
        $this->otherEntite = (new Entite())->setNom('Autre organisme')->setPublic(false)->setCreateur($this->admin);
        foreach ([$this->admin, $this->entite, $this->otherEntite] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $this->admin->setEntite($this->entite);
        $this->membership($this->admin, UtilisateurEntite::TENANT_ADMIN);
        $this->trainer = (new Utilisateur())->setEmail('documents.trainer@example.test')->setPassword('unused')->setPrenom('Camille')->setNom('Formateur')->setEntite($this->entite)->setCreateur($this->admin);
        $profile = (new Formateur())->setEntite($this->entite)->setCreateur($this->admin)->setUtilisateur($this->trainer)->setAssujettiTva(false);
        $this->trainer->setFormateur($profile);
        $this->membership($this->trainer, UtilisateurEntite::TENANT_FORMATEUR);
        $plan = (new Plan())->setCode('documents-test')->setName('Documents test');
        foreach ([$this->trainer, $profile, $plan] as $entity) $this->em->persist($entity);
        $this->em->persist((new EntiteSubscription())->setEntite($this->entite)->setStatus('active')->setPlan($plan));
        $this->em->flush();
        self::assertFalse($this->admin->isSuperAdmin(), 'Tenant isolation must be exercised without the super-admin bypass.');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (isset($this->directory) && is_dir($this->directory)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            rmdir($this->directory);
        }
        foreach (['ENV', 'SERVER'] as $index => $type) {
            if ($this->originalDatabase[$index] === null) unset($GLOBALS['_' . $type]['DATABASE_URL']);
            else $GLOBALS['_' . $type]['DATABASE_URL'] = $this->originalDatabase[$index];
        }
    }

    public function testUploadAndReplacementKeepOldBytesAndServeTheLatestToTrainers(): void
    {
        $client = $this->client();
        $form = $this->editForm($client, 'new');
        $form['document_formateur[file]']->upload($this->pdf('version-one'));
        $client->submit($form, ['document_formateur[titre]' => 'Feuille collective', 'document_formateur[categorie]' => 'emargement', 'document_formateur[publie]' => '1']);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $document = $this->em->getRepository(DocumentFormateur::class)->findOneBy(['titre' => 'Feuille collective']);
        self::assertNotNull($document);
        self::assertSame($this->entite->getId(), $document->getEntite()->getId());
        self::assertCount(1, $document->getVersions());
        $first = $document->getCurrentVersion();
        self::assertSame(1, $first->getNumero());
        self::assertSame($this->admin->getId(), $first->getUploadedBy()->getId());
        $firstBytes = file_get_contents($this->storage->path($first));
        self::assertStringContainsString('version-one', $firstBytes);

        $form = $this->editForm($client, 'edit', $document->getId());
        $form['document_formateur[file]']->upload($this->pdf('version-two'));
        $client->submit($form, ['document_formateur[noteVersion]' => 'Nouvelle présentation']);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $document = $this->reload($document);
        self::assertCount(2, $document->getVersions());
        self::assertSame(2, $document->getCurrentVersion()->getNumero());
        self::assertSame('Nouvelle présentation', $document->getCurrentVersion()->getNote());
        self::assertSame($firstBytes, file_get_contents($this->storage->path($first)));

        $client->request('GET', $this->url('app_administrateur_documents_formateurs_version', ['id' => $document->getId(), 'version' => 1]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame($firstBytes, $client->getInternalResponse()->getContent());
        $client->loginUser($this->trainer);
        $client->request('GET', $this->url('app_formateur_documents_download', ['id' => $document->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('version-two', $client->getInternalResponse()->getContent());
        self::assertStringContainsString('attachment', $client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('private', $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    public function testTrainersOnlySeePublishedDocumentsAndCannotManageOrReadHistory(): void
    {
        $published = $this->document('Modèle public');
        $hidden = $this->document('Modèle interne', false);
        $client = $this->client($this->trainer);
        $crawler = $client->request('GET', $this->url('app_formateur_documents_index'));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Modèle public', $crawler->text());
        self::assertStringNotContainsString('Modèle interne', $crawler->text());
        $client->catchExceptions(true);
        $client->request('GET', $this->url('app_formateur_documents_download', ['id' => $hidden->getId()]));
        self::assertSame(404, $client->getResponse()->getStatusCode());
        foreach (['index' => [], 'new' => [], 'edit' => ['id' => $published->getId()], 'version' => ['id' => $published->getId(), 'version' => 1]] as $route => $params) {
            $client->request('GET', $this->url('app_administrateur_documents_formateurs_' . $route, $params));
            self::assertSame(403, $client->getResponse()->getStatusCode(), 'Trainer access to ' . $route);
        }
        $client->request('POST', $this->url('app_administrateur_documents_formateurs_edit', ['id' => $published->getId()]), ['document_formateur' => ['titre' => 'Unexpected change']]);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $published = $this->reload($published);
        self::assertSame('Modèle public', $published->getTitre());
    }

    public function testDocumentsAreIsolatedByOrganismForBothAdministratorsAndTrainers(): void
    {
        $other = $this->document('Fichier confidentiel organisme B', true, $this->otherEntite);
        $client = $this->client();
        $crawler = $client->request('GET', $this->url('app_administrateur_documents_formateurs_index'));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString($other->getTitre(), $crawler->text());
        $client->catchExceptions(true);
        foreach (['edit' => ['id' => $other->getId()], 'version' => ['id' => $other->getId(), 'version' => 1]] as $route => $params) {
            $client->request('GET', $this->url('app_administrateur_documents_formateurs_' . $route, $params));
            self::assertSame(404, $client->getResponse()->getStatusCode());
        }
        $client->request('GET', $this->url('app_administrateur_documents_formateurs_index', ['entite' => $this->otherEntite->getId()]));
        self::assertSame(403, $client->getResponse()->getStatusCode());
        foreach ([$this->admin, $this->trainer] as $user) {
            $client->loginUser($user);
            $client->request('GET', $this->url('app_formateur_documents_download', ['id' => $other->getId()]));
            self::assertSame(404, $client->getResponse()->getStatusCode());
            $client->request('GET', $this->url('app_formateur_documents_download', ['entite' => $this->otherEntite->getId(), 'id' => $other->getId()]));
            self::assertSame(403, $client->getResponse()->getStatusCode());
        }
    }

    public function testAdministratorCanUnpublishWithoutUploadingOrDestroyingVersions(): void
    {
        $document = $this->document('Ancien titre');
        $path = $this->storage->path($document->getCurrentVersion());
        $client = $this->client();
        $form = $this->editForm($client, 'edit', $document->getId());
        $form['document_formateur[publie]']->untick();
        $client->submit($form, ['document_formateur[titre]' => 'Nouveau titre']);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $document = $this->reload($document);
        self::assertSame('Nouveau titre', $document->getTitre());
        self::assertFalse($document->isPublie());
        self::assertCount(1, $document->getVersions());
        self::assertFileExists($path);
        $client->request('GET', $this->url('app_formateur_documents_download', ['id' => $document->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode(), 'An administrator can preview their unpublished document.');
        $client->loginUser($this->trainer);
        $client->catchExceptions(true);
        $client->request('GET', $this->url('app_formateur_documents_download', ['id' => $document->getId()]));
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testInvalidCsrfDoesNotSaveAnUploadedFile(): void
    {
        $client = $this->client();
        $form = $this->editForm($client, 'new');
        $form['document_formateur[file]']->upload($this->pdf('invalid-csrf'));
        $client->submit($form, ['document_formateur[titre]' => 'Refusé', 'document_formateur[_token]' => 'invalid']);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(DocumentFormateur::class)->count([]));
        self::assertSame([], glob($this->directory . '/private/*'));
    }

    public function testMissingAndDisguisedFilesAreRejectedWithoutSaving(): void
    {
        $client = $this->client();
        $form = $this->editForm($client, 'new');
        $client->submit($form, ['document_formateur[titre]' => 'Sans fichier']);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Choisissez le document', $client->getResponse()->getContent());

        $fake = $this->directory . '/disguised.pdf';
        file_put_contents($fake, '<?php echo "not a PDF";');
        $form = $this->editForm($client, 'new');
        $form['document_formateur[file]']->upload($fake);
        $client->submit($form, ['document_formateur[titre]' => 'Faux PDF']);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(DocumentFormateur::class)->count([]));
        self::assertSame([], glob($this->directory . '/private/*'));
    }

    public function testStaleEditIsRejectedWithoutOverwritingOrKeepingAnExtraUpload(): void
    {
        $document = $this->document('Titre initial');
        $client = $this->client();
        $staleForm = $this->editForm($client, 'edit', $document->getId());
        $form = $this->editForm($client, 'edit', $document->getId());
        $client->submit($form, ['document_formateur[titre]' => 'Modification récente']);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $staleForm['document_formateur[file]']->upload($this->pdf('stale-upload'));
        $client->submit($staleForm, ['document_formateur[titre]' => 'Ancienne modification']);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('modifié entre-temps', $client->getResponse()->getContent());
        $document = $this->reload($document);
        self::assertSame('Modification récente', $document->getTitre());
        self::assertCount(1, $document->getVersions());
        self::assertCount(1, glob($this->directory . '/private/*'));
    }

    public function testBlankTitleAndVersionNoteWithoutAFileDoNotChangeTheSavedDocument(): void
    {
        $document = $this->document('Intitulé conservé');
        $client = $this->client();
        $form = $this->editForm($client, 'edit', $document->getId());
        $client->submit($form, ['document_formateur[titre]' => '   ']);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $document = $this->reload($document);
        self::assertSame('Intitulé conservé', $document->getTitre());

        $form = $this->editForm($client, 'edit', $document->getId());
        $client->submit($form, ['document_formateur[noteVersion]' => 'Version sans pièce jointe']);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Ajoutez le nouveau fichier', $client->getResponse()->getContent());
        $document = $this->reload($document);
        self::assertCount(1, $document->getVersions());
        self::assertNull($document->getCurrentVersion()->getNote());
        self::assertCount(1, glob($this->directory . '/private/*'));
    }

    public function testBlankPdfModelsAreAvailableOnlyToTheOrganismsAdministratorsAndTrainers(): void
    {
        $trainee = (new Utilisateur())->setEmail('documents.trainee@example.test')->setPassword('unused')->setPrenom('Stagiaire')->setNom('Test')->setEntite($this->entite)->setCreateur($this->admin);
        $this->membership($trainee, UtilisateurEntite::TENANT_STAGIAIRE);
        $this->em->persist($trainee);
        $this->em->flush();

        $client = $this->client();
        $route = 'app_formateur_documents_modele';
        $client->request('GET', $this->url($route, ['type' => 'emargement']));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('application/pdf', $client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF-', $client->getInternalResponse()->getContent());

        $client->loginUser($this->trainer);
        $client->request('GET', $this->url($route, ['type' => 'appreciation-formateur']));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringStartsWith('%PDF-', $client->getInternalResponse()->getContent());

        $client->catchExceptions(true);
        $client->request('GET', $this->url($route, ['entite' => $this->otherEntite->getId(), 'type' => 'emargement']));
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $client->request('GET', str_replace('/emargement', '/inconnu', $this->url($route, ['type' => 'emargement'])));
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $client->loginUser($trainee);
        $client->request('GET', $this->url($route, ['type' => 'appreciation-stagiaire']));
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    private function membership(Utilisateur $user, string $role): void
    {
        $user->addUtilisateurEntite((new UtilisateurEntite())->setUtilisateur($user)->setEntite($this->entite)->setCreateur($this->admin)->setRoles([$role]));
    }

    private function reload(DocumentFormateur $document): DocumentFormateur
    {
        $id = $document->getId();
        $this->em->clear();
        return $this->em->find(DocumentFormateur::class, $id);
    }

    private function document(string $title, bool $published = true, ?Entite $entite = null): DocumentFormateur
    {
        $document = (new DocumentFormateur())->setEntite($entite ?? $this->entite)->setTitre($title)->setPublie($published);
        $document->addVersion($this->storage->store(new UploadedFile($this->pdf('fixture'), 'modele.pdf', 'application/pdf', null, true), $this->admin, 1, null));
        $this->em->persist($document);
        $this->em->flush();
        return $document;
    }

    private function pdf(string $marker): string
    {
        $file = $this->directory . '/' . bin2hex(random_bytes(6)) . '.pdf';
        file_put_contents($file, "%PDF-1.4\n% " . $marker . "\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
        return $file;
    }

    private function client(?Utilisateur $user = null): KernelBrowser
    {
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->catchExceptions(false);
        $client->loginUser($user ?? $this->admin);
        return $client;
    }

    private function editForm(KernelBrowser $client, string $route, ?int $id = null): Form
    {
        $crawler = $client->request('GET', $this->url('app_administrateur_documents_formateurs_' . $route, $id === null ? [] : ['id' => $id]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        return $crawler->filter('form[name="document_formateur"]')->form();
    }

    private function url(string $route, array $params = []): string
    {
        return self::getContainer()->get('router')->generate($route, $params + ['entite' => $this->entite->getId()]);
    }
}
