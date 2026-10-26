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
final class SupportLibraryTest extends KernelTestCase
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

    private function membership(Utilisateur $user, string $role): void
    {
        $user->addUtilisateurEntite((new UtilisateurEntite())->setUtilisateur($user)->setEntite($this->entite)->setCreateur($this->admin)->setRoles([$role]));
    }
    private function client(): KernelBrowser
    {
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->loginUser($this->trainer);
        return $client;
    }
    private function url(string $action): string
    {
        return self::getContainer()->get('router')->generate('app_formateur_supports_'.$action, ['entite' => $this->entite->getId()]);
    }
    private function asset(?Entite $entite = null, ?Utilisateur $owner = null): \App\Entity\SupportAsset
    {
        $asset = (new \App\Entity\SupportAsset())->setEntite($this->em->find(Entite::class, ($entite ?? $this->entite)->getId()))->setUploadedBy($this->em->find(Utilisateur::class, ($owner ?? $this->trainer)->getId()))
            ->setCreateur($this->em->find(Utilisateur::class, $this->admin->getId()))->setTitre('Support test')->setFilename('fixture.pdf')->setUploadedAt(new \DateTimeImmutable());
        $this->em->persist($asset); $this->em->flush(); return $asset;
    }
    private function token(KernelBrowser $client): string
    {
        $client->request('GET', $this->url('library'));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        preg_match("/X-CSRF-Token': '([^']+)'/", $client->getResponse()->getContent(), $matches);
        self::assertNotEmpty($matches[1] ?? null);
        return $matches[1];
    }
    public function testFeedContainsIdAndOnlyCurrentTenantAndOwner(): void
    {
        $mine = $this->asset(); $this->asset($this->otherEntite); $this->asset(null, $this->admin);
        $client = $this->client();
        $client->request('GET', $this->url('assets_feed'));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertCount(1, $data); self::assertSame($mine->getId(), $data[0]['id']);
        self::assertNotEmpty($data[0]['uploadedAtSort']);
    }
    public function testAssignmentReadRejectsOtherTenantAndOwner(): void
    {
        $client = $this->client();
        foreach ([$this->asset($this->otherEntite), $this->asset(null, $this->admin)] as $asset) {
            $client->request('GET', $this->url('assignments_feed'), ['assetId' => $asset->getId()]);
            self::assertSame(403, $client->getResponse()->getStatusCode());
        }
        $asset = $this->asset();
        $client->request('GET', $this->url('assignments_feed'), ['assetId' => $asset->getId()]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }
    public function testEveryMutationRequiresCsrf(): void
    {
        $client = $this->client();
        foreach (['library_upload','assign_sessions','assign_users','unassign_session','unassign_user'] as $route) {
            $client->request('POST', $this->url($route));
            self::assertSame(403, $client->getResponse()->getStatusCode(), $route);
        }
    }
    public function testValidTokenStillRejectsForeignAssetAndMissingSession(): void
    {
        $client = $this->client(); $token = $this->token($client);
        $foreign = $this->asset($this->otherEntite);
        $client->request('POST', $this->url('assign_sessions'), ['assetId' => $foreign->getId(), 'sessionIds' => [1]], [], ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $mine = $this->asset();
        $client->request('POST', $this->url('assign_users'), ['assetId' => $mine->getId(), 'userIds' => [$this->admin->getId()]], [], ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(\App\Entity\SupportAssignUser::class)->count([]));
    }
    public function testUploadRejectsExecutableBeforeWriting(): void
    {
        $client = $this->client(); $token = $this->token($client);
        $path = $this->directory.'/test.php'; file_put_contents($path, '<?php echo 1;');
        $file = new UploadedFile($path, 'unsafe.php', 'application/pdf', null, true);
        $client->request('POST', $this->url('library_upload'), [], ['files' => [$file]], ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(\App\Entity\SupportAsset::class)->count([]));
    }
    public function testSessionAssignmentDeduplicatesAndCanBeRemoved(): void
    {
        $site = (new \App\Entity\Site())->setEntite($this->entite)->setCreateur($this->admin)->setNom('Test')->setSlug('supports-test');
        $session = (new \App\Entity\Session())->setEntite($this->entite)->setCreateur($this->admin)->setSite($site)->setCode('SUPPORT-TEST')->setFormateur($this->trainer->getFormateur($this->entite));
        $this->em->persist($site); $this->em->persist($session); $this->em->flush();
        $asset = $this->asset();
        $client = $this->client(); $token = $this->token($client);
        $headers = ['HTTP_X_CSRF_TOKEN' => $token];
        for ($i = 0; $i < 2; ++$i) {
            $client->request('POST', $this->url('assign_sessions'), ['assetId' => $asset->getId(), 'sessionIds' => [$session->getId(), $session->getId()], 'visible' => '0'], [], $headers);
            self::assertSame(200, $client->getResponse()->getStatusCode());
        }
        $links = $this->em->getRepository(\App\Entity\SupportAssignSession::class)->findAll();
        self::assertCount(1, $links);
        self::assertFalse($links[0]->isVisibleToTrainee());
        $client->request('GET', $this->url('assignments_feed'), ['assetId' => $asset->getId()]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(1, json_decode($client->getResponse()->getContent(), true)['sessions']);
        $client->request('POST', $this->url('unassign_session'), ['id' => $links[0]->getId()], [], $headers);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(\App\Entity\SupportAssignSession::class)->count([]));
    }
    public function testUserAssignmentRequiresEnrollmentAndDeduplicates(): void
    {
        $site = (new \App\Entity\Site())->setEntite($this->entite)->setCreateur($this->admin)->setNom('Test')->setSlug('supports-users');
        $session = (new \App\Entity\Session())->setEntite($this->entite)->setCreateur($this->admin)->setSite($site)->setCode('SUPPORT-USERS')->setFormateur($this->trainer->getFormateur($this->entite));
        $enrollment = (new \App\Entity\Inscription())->setSession($session)->setStagiaire($this->admin)->setEntite($this->entite)->setCreateur($this->trainer);
        foreach ([$site, $session, $enrollment] as $row) $this->em->persist($row);
        $this->em->flush();
        $asset = $this->asset(); $client = $this->client(); $token = $this->token($client);
        $headers = ['HTTP_X_CSRF_TOKEN' => $token];
        $params = ['assetId' => $asset->getId(), 'sessionId' => $session->getId()];
        $client->request('POST', $this->url('assign_users'), $params + ['userIds' => [$this->trainer->getId()]], [], $headers);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        for ($i = 0; $i < 2; ++$i) {
            $client->request('POST', $this->url('assign_users'), $params + ['userIds' => [$this->admin->getId(), $this->admin->getId()]], [], $headers);
            self::assertSame(200, $client->getResponse()->getStatusCode());
        }
        $links = $this->em->getRepository(\App\Entity\SupportAssignUser::class)->findAll();
        self::assertCount(1, $links);
        $client->request('POST', $this->url('unassign_user'), ['id' => $links[0]->getId()], [], $headers);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(\App\Entity\SupportAssignUser::class)->count([]));
    }
}
