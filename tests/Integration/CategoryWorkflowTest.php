<?php

namespace App\Tests\Integration;

use App\Entity\{Categorie, Entite, Utilisateur, UtilisateurEntite};
use App\Service\Photo\{ImageUploadException, PhotoManager};
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};

final class CategoryWorkflowTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Entite $entite;
    private Entite $other;
    private Utilisateur $admin;
    private array $originalDatabase;

    protected function setUp(): void
    {
        $this->originalDatabase = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        self::getContainer()->set(\Symfony\Component\Mailer\MailerInterface::class, $this->createMock(\Symfony\Component\Mailer\MailerInterface::class));
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = (new Utilisateur())->setEmail('category@example.test')->setPassword('unused')->setPrenom('Category')->setNom('Test')->setRoles(['ROLE_SUPER_ADMIN']);
        $this->entite = (new Entite())->setNom('Organisme A')->setPublic(false)->setCreateur($this->admin);
        $this->other = (new Entite())->setNom('Organisme B')->setPublic(false)->setCreateur($this->admin);
        foreach ([$this->admin, $this->entite, $this->other] as $entity) $this->em->persist($entity);
        $this->em->flush();
        $this->admin->setEntite($this->entite);
        $this->em->persist((new UtilisateurEntite())->setCreateur($this->admin)->setUtilisateur($this->admin)->setEntite($this->entite)->setRoles(['TENANT_ADMIN'])->setStatus('active'));
        $plan = (new \App\Entity\Billing\Plan())->setCode('test')->setName('Test');
        $this->em->persist($plan);
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->entite)->setStatus('active')->setPlan($plan));
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $i => $type) {
            if ($this->originalDatabase[$i] === null) unset($GLOBALS['_' . $type]['DATABASE_URL']);
            else $GLOBALS['_' . $type]['DATABASE_URL'] = $this->originalDatabase[$i];
        }
    }

    public function testCreateAndDeleteReturnToTheCategoryLibrary(): void
    {
        $client = $this->client();
        $form = $client->request('GET', $this->url('categorie_ajouter'))->filter('form[name="categorie"]')->form();
        $client->submit($form, ['categorie[nom]' => 'Bureautique', 'categorie[slug]' => 'bureautique']);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame($this->url('categorie'), $client->getResponse()->headers->get('Location'));
        $category = $this->em->getRepository(Categorie::class)->findOneBy(['slug' => 'bureautique']);
        self::assertNotNull($category);
        $crawler = $client->followRedirect();
        $form = $crawler->filter('form[action="' . $this->url('categorie_supprimer', ['id' => $category->getId()]) . '"]')->form();
        $client->submit($form);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame($this->url('categorie'), $client->getResponse()->headers->get('Location'));
        self::assertSame(0, $this->em->getRepository(Categorie::class)->count([]));
    }

    public function testImageProcessingFailureIsShownOnTheFormWithoutSaving(): void
    {
        $manager = $this->createMock(PhotoManager::class);
        $manager->expects(self::once())->method('handleImageUpload')->willThrowException(new ImageUploadException('Image trop grande pour la mémoire disponible.'));
        self::getContainer()->set(PhotoManager::class, $manager);
        $client = $this->client();
        $form = $client->request('GET', $this->url('categorie_ajouter'))->filter('form[name="categorie"]')->form();
        $client->submit($form, ['categorie[nom]' => 'Sécurité', 'categorie[slug]' => 'securite']);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Image trop grande pour la mémoire disponible.', $client->getResponse()->getContent());
        self::assertSame(0, $this->em->getRepository(Categorie::class)->count([]));
    }

    public function testDuplicateSlugAndParentCycleAreValidationErrors(): void
    {
        $parent = $this->category('parent');
        $child = $this->category('child')->setParent($parent);
        $this->em->flush();
        $client = $this->client();
        $form = $client->request('GET', $this->url('categorie_ajouter'))->filter('form[name="categorie"]')->form();
        $client->submit($form, ['categorie[nom]' => 'Doublon', 'categorie[slug]' => 'parent']);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Ce slug est déjà utilisé', $client->getResponse()->getContent());
        $form = $client->request('GET', $this->url('categorie_modifier', ['id' => $parent->getId()]))->filter('form[name="categorie"]')->form();
        $client->submit($form, ['categorie[parent]' => $child->getId()]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('ni une de ses sous-catégories', $client->getResponse()->getContent());
        $this->em->clear();
        self::assertNull($this->em->find(Categorie::class, $parent->getId())->getParent());
    }

    public function testCategoryEditAndDeleteCannotTargetAnotherOrganism(): void
    {
        $category = $this->category('foreign', $this->other);
        $client = $this->client();
        $client->catchExceptions(true);
        $client->request('GET', $this->url('categorie_modifier', ['id' => $category->getId()]));
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $client->request('POST', $this->url('categorie_supprimer', ['id' => $category->getId()]));
        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame(1, $this->em->getRepository(Categorie::class)->count([]));
    }

    private function category(string $name, ?Entite $entite = null): Categorie
    {
        $category = (new Categorie())->setNom($name)->setSlug($name)->setEntite($entite ?? $this->entite)->setCreateur($this->admin);
        $this->em->persist($category); $this->em->flush();
        return $category;
    }

    private function client(): KernelBrowser
    {
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot(); $client->catchExceptions(false); $client->loginUser($this->admin);
        return $client;
    }

    private function url(string $route, array $parameters = []): string
    {
        return self::getContainer()->get('router')->generate('app_administrateur_formation_' . $route, ['entite' => $this->entite->getId()] + $parameters);
    }
}
