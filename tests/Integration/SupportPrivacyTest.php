<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{Entite, Formateur, Inscription, Session, Site, SupportAsset, SupportAssignSession, SupportAssignUser, SupportDocument, Utilisateur, UtilisateurEntite};
use App\Entity\Billing\{EntiteSubscription, Plan};
use App\Enum\StatusInscription;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class SupportPrivacyTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private KernelBrowser $client;
    private array $database;
    private array $objects = [];
    private array $files = [];

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        foreach (['admin', 'trainer', 'learner', 'other'] as $name) {
            $this->objects[$name] = (new Utilisateur())->setEmail($name.'@support-privacy.test')->setPassword('unused')->setPrenom('Test')->setNom($name);
            $this->em->persist($this->objects[$name]);
        }
        foreach (['tenant', 'foreignTenant'] as $name) {
            $this->objects[$name] = (new Entite())->setNom($name)->setCreateur($this->objects['admin'])->setPublic(false);
            $this->em->persist($this->objects[$name]);
        }
        $this->em->flush();
        foreach (['admin' => 'TENANT_ADMIN', 'trainer' => 'TENANT_FORMATEUR', 'learner' => 'TENANT_STAGIAIRE', 'other' => 'TENANT_FORMATEUR'] as $name => $role) {
            $this->objects[$name]->setEntite($this->objects['tenant']);
            $membership = (new UtilisateurEntite())->setEntite($this->objects['tenant'])->setUtilisateur($this->objects[$name])->setCreateur($this->objects['admin'])->setRoles([$role]);
            $this->objects[$name.'Membership'] = $membership;
            $this->em->persist($membership);
        }
        $profile = (new Formateur())->setEntite($this->objects['tenant'])->setCreateur($this->objects['admin'])->setUtilisateur($this->objects['trainer'])->setAssujettiTva(false);
        $this->objects['trainer']->setFormateur($profile);
        $this->em->persist($profile);
        $plan = (new Plan())->setCode('SUPPORT_PRIVACY')->setName('Supports fixture');
        $this->em->persist($plan);
        $this->em->persist((new EntiteSubscription())->setEntite($this->objects['tenant'])->setStatus('active')->setPlan($plan));
        foreach (['session' => 'tenant', 'foreignSession' => 'foreignTenant'] as $name => $tenant) {
            $site = (new Site())->setEntite($this->objects[$tenant])->setCreateur($this->objects['admin'])->setNom($name)->setSlug($name);
            $session = (new Session())->setEntite($this->objects[$tenant])->setCreateur($this->objects['admin'])->setSite($site)->setCode($name);
            if ($tenant === 'tenant') $session->setFormateur($profile);
            $this->objects[$name] = $session;
            $this->em->persist($site); $this->em->persist($session);
        }
        $this->objects['enrolment'] = (new Inscription())->setEntite($this->objects['tenant'])->setCreateur($this->objects['admin'])->setSession($this->objects['session'])->setStagiaire($this->objects['learner'])->setStatus(StatusInscription::CONFIRME);
        $this->em->persist($this->objects['enrolment']); $this->em->flush();
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) if (is_file($file)) unlink($file);
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $scope) {
            if ($this->database[$index] === null) unset($GLOBALS['_'.$scope]['DATABASE_URL']);
            else $GLOBALS['_'.$scope]['DATABASE_URL'] = $this->database[$index];
        }
    }

    private function file(string $directory = 'supports/library'): string
    {
        $name = 'privacy-fixture-'.bin2hex(random_bytes(10)).'.txt';
        $path = self::getContainer()->getParameter('kernel.project_dir').'/public/uploads/'.$directory.'/'.$name;
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
        file_put_contents($path, 'Synthetic confidential support');
        $this->files[] = $path;
        return $name;
    }

    private function asset(string $tenant = 'tenant'): SupportAsset
    {
        $asset = (new SupportAsset())->setEntite($this->objects[$tenant])->setUploadedBy($this->objects['trainer'])->setCreateur($this->objects['trainer'])
            ->setTitre('Support confidentiel')->setOriginalName('document.txt')->setFilename($this->file())->setMimeType('text/plain');
        $this->em->persist($asset); $this->em->flush();
        return $asset;
    }

    private function assign(SupportAsset $asset, string $kind): SupportAssignSession|SupportAssignUser
    {
        $asset = $this->em->find(SupportAsset::class, $asset->getId());
        $link = $kind === 'session' ? (new SupportAssignSession())->setSession($this->objects['session']) : (new SupportAssignUser())->setUser($this->objects['learner']);
        $link->setAsset($asset)->setEntite($this->objects['tenant'])->setCreateur($this->objects['trainer']);
        $this->em->persist($link); $this->em->flush();
        return $link;
    }

    private function get(string $url, ?string $user = null): int
    {
        if ($user) $this->client->loginUser($this->objects[$user]);
        $this->client->request('GET', $url);
        foreach ($this->objects as $key => $object) $this->objects[$key] = $this->em->find($object::class, $object->getId());
        return $this->client->getResponse()->getStatusCode();
    }

    public static function visibilityCases(): iterable
    {
        foreach (['user', 'session'] as $kind) foreach (['visible', 'hidden', 'inactive', 'future', 'expired', 'foreign-link'] as $state) yield $kind.' '.$state => [$kind, $state];
    }

    #[DataProvider('visibilityCases')]
    public function testFileAndAllLearnerFeedsRespectLiveVisibility(string $kind, string $state): void
    {
        $asset = $this->asset(); $link = $this->assign($asset, $kind);
        match ($state) {
            'hidden' => $link->setIsVisibleToTrainee(false),
            'inactive' => $link->setIsActive(false),
            'future' => $link->setVisibleFrom(new \DateTimeImmutable('+1 day')),
            'expired' => $link->setVisibleUntil(new \DateTimeImmutable('-1 day')),
            'foreign-link' => $link->setEntite($this->objects['foreignTenant']),
            default => null,
        };
        $this->em->flush();
        $expected = $state === 'visible';
        self::assertSame($expected ? 200 : 404, $this->get('/uploads/supports/library/'.$asset->getFilename(), 'learner'));
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        foreach (['/supports/assets', '/session/'.$this->objects['session']->getId().'/supports/feed'] as $suffix) {
            self::assertSame(200, $this->get('/fr/stagiaire/'.$this->objects['tenant']->getId().$suffix));
            $rows = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
            self::assertCount($expected ? 1 : 0, $rows);
        }
    }

    public function testOriginalUrlRequiresAuthenticationAssignmentAndActiveMembership(): void
    {
        $asset = $this->asset(); $url = '/uploads/supports/library/'.$asset->getFilename();
        self::assertSame(404, $this->get($url));
        self::assertSame(404, $this->get($url, 'other'));
        self::assertSame(200, $this->get($url, 'trainer'));
        self::assertSame(404, $this->get($url, 'learner'));
        $link = $this->assign($asset, 'user');
        self::assertSame(200, $this->get($url));
        $this->objects['learnerMembership']->setStatus('suspended'); $this->em->flush();
        self::assertSame(404, $this->get($url));
        self::assertSame(200, $this->get($url, 'admin'));
    }

    public function testSessionRevocationAndInconsistentTenantDoNotExposeFilesOrMetadata(): void
    {
        $asset = $this->asset(); $link = $this->assign($asset, 'session');
        $url = '/uploads/supports/library/'.$asset->getFilename();
        $this->objects['enrolment']->setStatus(StatusInscription::ANNULE); $this->em->flush();
        self::assertSame(404, $this->get($url, 'learner'));
        $this->objects['enrolment']->setStatus(StatusInscription::CONFIRME); $this->em->find(SupportAssignSession::class, $link->getId())->setSession($this->objects['foreignSession']); $this->em->flush();
        self::assertSame(404, $this->get($url));
        $foreign = $this->asset('foreignTenant'); $this->assign($foreign, 'user');
        self::assertSame(404, $this->get('/uploads/supports/library/'.$foreign->getFilename()));
        self::assertSame(200, $this->get('/fr/stagiaire/'.$this->objects['tenant']->getId().'/supports/assets'));
        self::assertSame([], json_decode($this->client->getResponse()->getContent(), true)['data']);
        foreach (['/supports', '/supports/feed'] as $suffix) self::assertSame(404, $this->get('/fr/stagiaire/'.$this->objects['tenant']->getId().'/session/'.$this->objects['foreignSession']->getId().$suffix));
    }

    public function testLegacySupportIsPrivateAndSessionSpecific(): void
    {
        $document = (new SupportDocument())->setEntite($this->objects['tenant'])->setSession($this->objects['session'])->setCreateur($this->objects['trainer'])
            ->setUploadedBy($this->objects['trainer'])->setTitre('Ancien support')->setFilename($this->file('supports'));
        $this->em->persist($document); $this->em->flush();
        $url = '/uploads/supports/'.$document->getFilename();
        self::assertSame(404, $this->get($url));
        self::assertSame(404, $this->get($url, 'other'));
        self::assertSame(200, $this->get($url, 'trainer'));
        self::assertSame(200, $this->get($url, 'learner'));
        $this->objects['enrolment']->setStatus(StatusInscription::ANNULE); $this->em->flush();
        self::assertSame(404, $this->get($url));
        $this->em->find(SupportDocument::class, $document->getId())->setSession($this->objects['foreignSession']); $this->em->flush();
        self::assertSame(404, $this->get($url, 'admin'));
    }

    public function testUnregisteredAndAmbiguousFilesRemainUnavailableEvenToTenantAdmin(): void
    {
        self::assertSame(404, $this->get('/uploads/supports/library/'.$this->file(), 'admin'));
        $asset = $this->asset();
        $duplicate = (new SupportAsset())->setEntite($this->objects['foreignTenant'])->setCreateur($this->objects['admin'])->setUploadedBy($this->objects['admin'])
            ->setTitre('Archive ambiguë')->setFilename($asset->getFilename());
        $this->em->persist($duplicate); $this->em->flush();
        self::assertSame(404, $this->get('/uploads/supports/library/'.$asset->getFilename()));
        $this->client->request('HEAD', '/uploads/supports/library/'.$asset->getFilename());
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testLegacyUploadRequiresCsrfAndSessionOwnershipAndValidFile(): void
    {
        $base = '/fr/formateur/'.$this->objects['tenant']->getId().'/session/';
        $url = $base.$this->objects['session']->getId().'/supports/upload';
        $this->client->loginUser($this->objects['trainer']);
        $this->client->request('POST', $url);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $page = $this->client->request('GET', $base.$this->objects['session']->getId());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $token = $page->filter('#upload-form input[name="_token"]')->attr('value');
        $this->client->request('POST', $base.$this->objects['foreignSession']->getId().'/supports/upload', ['_token' => $token]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->client->loginUser($this->objects['other']);
        $this->client->request('POST', $url, ['_token' => $token]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->loginUser($this->objects['trainer']);
        $page = $this->client->request('GET', $base.$this->objects['session']->getId());
        $token = $page->filter('#upload-form input[name="_token"]')->attr('value');
        $temporary = tempnam(sys_get_temp_dir(), 'support-upload-'); $this->files[] = $temporary;
        file_put_contents($temporary, '<?php echo "executable";');
        $this->client->request('POST', $url, ['_token' => $token], ['file' => new UploadedFile($temporary, 'file.php', 'application/pdf', null, true)]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(SupportDocument::class)->count([]));
        file_put_contents($temporary, 'A synthetic training handout.');
        $this->client->request('POST', $url, ['_token' => $token], ['file' => new UploadedFile($temporary, 'participant-private-name.txt', 'application/pdf', null, true)]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $document = $this->em->getRepository(SupportDocument::class)->findOneBy([]);
        $this->files[] = self::getContainer()->getParameter('kernel.project_dir').'/public/uploads/supports/'.$document->getFilename();
        self::assertSame('text/plain', $document->getMimeType());
        self::assertStringNotContainsString('private-name', $document->getFilename());
    }
}
