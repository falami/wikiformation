<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{Entite, Utilisateur, UtilisateurEntite};
use App\Entity\Billing\{EntiteSubscription, Plan};
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};
use Symfony\Component\Mailer\MailerInterface;

/** Account protection is exercised through HTTP using only disposable SQLite data. */
final class TenantDirectorProtectionTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private KernelBrowser $client;
    private array $database;
    private int $entityId;
    private int $directorId;
    private int $adminId;
    private int $learnerId;

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');
        self::getContainer()->set(MailerInterface::class, $mailer);
        $this->em = self::getContainer()->get('doctrine')->getManager();
        $this->em->getConfiguration()->addCustomStringFunction('JSON_CONTAINS', DirectorProtectionJsonContains::class);
        $this->em->getConnection()->getNativeConnection()->sqliteCreateFunction(
            'JSON_CONTAINS',
            static fn ($json, $value) => (int) in_array(json_decode($value, true), json_decode($json, true), true),
        );
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $director = $this->user('director');
        $entity = (new Entite())->setNom('Organisme protégé')->setPublic(false)->setCreateur($director);
        $this->em->persist($entity);
        $this->em->flush();
        $director->setEntite($entity);
        $this->membership($director, $entity, $director, [UtilisateurEntite::TENANT_DIRIGEANT]);
        $admin = $this->user('admin')->setEntite($entity)->setCreateur($director);
        $this->membership($admin, $entity, $director, [UtilisateurEntite::TENANT_ADMIN]);
        $learner = $this->user('learner')->setEntite($entity)->setCreateur($director);
        $this->membership($learner, $entity, $director, [UtilisateurEntite::TENANT_STAGIAIRE]);
        $plan = (new Plan())->setCode('DIRECTOR_PROTECTION')->setName('Protection dirigeant')->setMaxApprenantsAn(100)->setMaxUtilisateurs(20);
        $this->em->persist($plan);
        $this->em->persist((new EntiteSubscription())->setEntite($entity)->setPlan($plan)->setStatus('active'));
        $this->em->flush();
        $this->entityId = $entity->getId();
        $this->directorId = $director->getId();
        $this->adminId = $admin->getId();
        $this->learnerId = $learner->getId();

        $this->client = self::getContainer()->get('test.client');
        $this->client->disableReboot();
        $this->client->catchExceptions(true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $name) {
            if ($this->database[$index] === null) {
                unset($GLOBALS['_'.$name]['DATABASE_URL']);
            } else {
                $GLOBALS['_'.$name]['DATABASE_URL'] = $this->database[$index];
            }
        }
    }

    public static function protectedAccounts(): iterable
    {
        yield 'local director' => ['local_director'];
        yield 'creator without director role' => ['local_creator'];
        yield 'director in another organism' => ['foreign_director'];
        yield 'creator of another organism' => ['foreign_creator'];
    }

    #[DataProvider('protectedAccounts')]
    public function testAdministratorCannotModifyOrRevokeProtectedAccount(string $kind): void
    {
        $targetId = $this->protectedAccount($kind);
        $before = $this->accountSnapshot($targetId);
        $this->login($this->adminId);

        // A valid editable form supplies a valid CSRF token; denial must be authorization.
        $values = $this->editValues($this->learnerId);
        $values['utilisateur']['email'] = 'takeover@example.test';
        $values['utilisateur']['prenom'] = 'Compte détourné';
        $values['utilisateur']['ueRoles'] = [UtilisateurEntite::TENANT_STAGIAIRE];

        $this->client->request('GET', $this->url('modifier/'.$targetId));
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', $this->url('modifier/'.$targetId), $values);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame($before, $this->accountSnapshot($targetId));

        foreach (['reset-password' => 'reset_password_', 'send-activation' => 'send_activation_', 'supprimer' => 'delete_user_'] as $action => $tokenPrefix) {
            $token = $this->csrf($tokenPrefix.$targetId);
            $this->client->request('POST', $this->url($action.'/'.$targetId), ['_token' => $token]);
            self::assertSame(403, $this->client->getResponse()->getStatusCode(), $kind.' / '.$action);
            self::assertSame($before, $this->accountSnapshot($targetId), $action.' must not alter the account or any membership.');
        }
    }

    public function testDirectorCanEditOwnProfileWithoutLosingAdministrativeAccess(): void
    {
        $this->login($this->directorId);
        $values = $this->editValues($this->directorId);
        $values['utilisateur']['prenom'] = 'Prénom corrigé';
        $this->client->request('POST', $this->url('modifier/'.$this->directorId), $values);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->em->clear();
        self::assertSame('Prénom corrigé', $this->em->find(Utilisateur::class, $this->directorId)->getPrenom());
        self::assertSame([UtilisateurEntite::TENANT_DIRIGEANT], $this->localMembership($this->directorId)->getRoles());
        self::assertTrue($this->localMembership($this->directorId)->isActive());
        $this->client->request('GET', $this->url());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public static function nonDirectorRoles(): iterable
    {
        yield 'replace with learner' => [[UtilisateurEntite::TENANT_STAGIAIRE]];
        yield 'keep admin but remove director' => [[UtilisateurEntite::TENANT_ADMIN]];
    }

    #[DataProvider('nonDirectorRoles')]
    public function testDirectorCannotRemoveOwnDirectorRole(array $roles): void
    {
        $before = $this->accountSnapshot($this->directorId);
        $this->login($this->directorId);
        $values = $this->editValues($this->directorId);
        $values['utilisateur']['ueRoles'] = $roles;
        $this->client->request('POST', $this->url('modifier/'.$this->directorId), $values);
        self::assertContains($this->client->getResponse()->getStatusCode(), [200, 403]);
        self::assertSame($before, $this->accountSnapshot($this->directorId));
        if ($this->client->getResponse()->getStatusCode() === 200) {
            self::assertStringContainsString('dirigeant', strtolower($this->client->getCrawler()->text()));
            self::assertGreaterThan(0, $this->client->getCrawler()->filter('.invalid-feedback, .form-error-message, .alert-danger')->count());
        }
        $this->client->request('GET', $this->url());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testDirectorRoleAloneCanGrantAndCreateAdministrators(): void
    {
        // This actor is not the creator: access must come from TENANT_DIRIGEANT alone.
        $entity = $this->em->find(Entite::class, $this->entityId);
        $creator = $this->em->find(Utilisateur::class, $this->directorId);
        $deputy = $this->user('second-director')->setEntite($entity);
        $this->membership($deputy, $entity, $creator, [UtilisateurEntite::TENANT_DIRIGEANT]);
        $this->em->flush();
        $this->login($deputy->getId());
        $values = $this->editValues($this->learnerId);
        $values['utilisateur']['ueRoles'] = [UtilisateurEntite::TENANT_ADMIN];
        $this->client->request('POST', $this->url('modifier/'.$this->learnerId), $values);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->em->clear();
        self::assertSame([UtilisateurEntite::TENANT_ADMIN], $this->localMembership($this->learnerId)->getRoles());

        $page = $this->client->request('GET', $this->url('ajouter'));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $values = $page->filter('form[name="utilisateur"]')->form()->getPhpValues();
        $values['utilisateur']['email'] = 'new-admin@example.test';
        $values['utilisateur']['prenom'] = 'Nouvel';
        $values['utilisateur']['nom'] = 'Administrateur';
        $values['utilisateur']['ueRoles'] = [UtilisateurEntite::TENANT_ADMIN];
        $this->client->request('POST', $this->url('ajouter'), $values);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->em->clear();
        $created = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => 'new-admin@example.test']);
        self::assertNotNull($created);
        self::assertSame([UtilisateurEntite::TENANT_ADMIN], $this->localMembership($created->getId())->getRoles());
    }

    public function testAdministratorCanStillEditOrdinaryUser(): void
    {
        $this->login($this->adminId);
        $values = $this->editValues($this->learnerId);
        $values['utilisateur']['prenom'] = 'Camille';
        $this->client->request('POST', $this->url('modifier/'.$this->learnerId), $values);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        $this->em->clear();
        self::assertSame('Camille', $this->em->find(Utilisateur::class, $this->learnerId)->getPrenom());
        self::assertSame([UtilisateurEntite::TENANT_STAGIAIRE], $this->localMembership($this->learnerId)->getRoles());
    }

    public function testAdministratorCannotPromoteThemselfToDirector(): void
    {
        $before = $this->accountSnapshot($this->adminId);
        $this->login($this->adminId);
        $values = $this->editValues($this->adminId);
        $values['utilisateur']['ueRoles'] = [UtilisateurEntite::TENANT_ADMIN, UtilisateurEntite::TENANT_DIRIGEANT];
        $this->client->request('POST', $this->url('modifier/'.$this->adminId), $values);
        self::assertContains($this->client->getResponse()->getStatusCode(), [200, 403]);
        self::assertSame($before, $this->accountSnapshot($this->adminId));
    }

    public function testDirectoryShowsProtectedAccountWithoutManagementActions(): void
    {
        $this->login($this->adminId);
        $this->client->request('POST', $this->url('ajax'), [
            'draw' => 1,
            'search' => ['value' => 'director@example.test'],
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $result = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $result['recordsFiltered']);
        self::assertCount(1, $result['data']);
        self::assertSame($this->directorId, $result['data'][0]['id']);
        $actions = $result['data'][0]['actions'];
        self::assertStringContainsString('Compte dirigeant protégé', $actions);
        self::assertStringContainsString($this->url((string) $this->directorId), $actions);
        foreach (['modifier', 'reset-password', 'send-activation', 'supprimer'] as $action) {
            self::assertStringNotContainsString($this->url($action.'/'.$this->directorId), $actions);
        }
    }

    public function testAttachingInactiveProtectedAccountCannotOverwriteItsRoles(): void
    {
        $this->localMembership($this->directorId)->setStatus(UtilisateurEntite::STATUS_SUSPENDED);
        $this->em->flush();
        $before = $this->accountSnapshot($this->directorId);
        $this->login($this->adminId);
        $page = $this->client->request('GET', $this->url('ajouter'));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $values = $page->filter('form[name="utilisateur"]')->form()->getPhpValues();
        $values['utilisateur']['email'] = 'director@example.test';
        $values['utilisateur']['prenom'] = 'Compte remplacé';
        $values['utilisateur']['nom'] = 'Autre nom';
        $values['utilisateur']['ueRoles'] = [UtilisateurEntite::TENANT_STAGIAIRE];
        $this->client->request('POST', $this->url('ajouter'), $values);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame($before, $this->accountSnapshot($this->directorId));
        self::assertSame(3, $this->em->getRepository(Utilisateur::class)->count([]));
        self::assertSame(3, $this->em->getRepository(UtilisateurEntite::class)->count([]));
    }

    public function testSecondDirectorCannotRemoveFirstDirectorsAccess(): void
    {
        $entity = $this->em->find(Entite::class, $this->entityId);
        $creator = $this->em->find(Utilisateur::class, $this->directorId);
        $deputy = $this->user('other-director')->setEntite($entity);
        $this->membership($deputy, $entity, $creator, [UtilisateurEntite::TENANT_DIRIGEANT]);
        $this->em->flush();
        $deputyId = $deputy->getId();
        $before = $this->accountSnapshot($this->directorId);
        $this->login($deputyId);
        $values = $this->editValues($this->learnerId);
        $values['utilisateur']['ueRoles'] = [UtilisateurEntite::TENANT_STAGIAIRE];
        $this->client->request('POST', $this->url('modifier/'.$this->directorId), $values);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame($before, $this->accountSnapshot($this->directorId));
        self::assertTrue($this->localMembership($deputyId)->isActive());
    }

    public function testSuspendedDirectorCannotUseAdministrativeRoutes(): void
    {
        $entity = $this->em->find(Entite::class, $this->entityId);
        $creator = $this->em->find(Utilisateur::class, $this->directorId);
        $director = $this->user('suspended-director')->setEntite($entity);
        $this->membership($director, $entity, $creator, [UtilisateurEntite::TENANT_DIRIGEANT])->setStatus(UtilisateurEntite::STATUS_SUSPENDED);
        $this->em->flush();
        $this->client->loginUser($director);
        $this->client->request('GET', $this->url());
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    private function protectedAccount(string $kind): int
    {
        if ($kind === 'local_director') {
            return $this->directorId;
        }
        if ($kind === 'local_creator') {
            $this->localMembership($this->directorId)->setRoles([UtilisateurEntite::TENANT_ADMIN]);
            $this->em->flush();
            return $this->directorId;
        }
        $target = $this->em->find(Utilisateur::class, $this->learnerId);
        $creator = $kind === 'foreign_creator' ? $target : $this->em->find(Utilisateur::class, $this->directorId);
        $other = (new Entite())->setNom('Autre organisme')->setPublic(false)->setCreateur($creator);
        $this->em->persist($other);
        $this->membership($target, $other, $creator, [$kind === 'foreign_director' ? UtilisateurEntite::TENANT_DIRIGEANT : UtilisateurEntite::TENANT_STAGIAIRE]);
        $this->em->flush();
        // Keep a distinct unprotected account from which a valid edit form can be obtained.
        $ordinary = $this->user('ordinary')->setEntite($this->em->find(Entite::class, $this->entityId));
        $this->membership($ordinary, $ordinary->getEntite(), $creator, [UtilisateurEntite::TENANT_STAGIAIRE]);
        $this->em->flush();
        $this->learnerId = $ordinary->getId();
        return $target->getId();
    }

    private function user(string $name): Utilisateur
    {
        $user = (new Utilisateur())->setEmail($name.'@example.test')->setPassword('original-password')->setNom('Test')->setPrenom($name)->setIsVerified(false);
        $this->em->persist($user);
        return $user;
    }

    private function membership(Utilisateur $user, Entite $entity, Utilisateur $creator, array $roles): UtilisateurEntite
    {
        $membership = (new UtilisateurEntite())->setUtilisateur($user)->setEntite($entity)->setCreateur($creator)->setRoles($roles);
        $this->em->persist($membership);
        return $membership;
    }

    private function localMembership(int $userId): UtilisateurEntite
    {
        return $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur' => $userId, 'entite' => $this->entityId]);
    }

    private function login(int $userId): void
    {
        $this->client->loginUser($this->em->find(Utilisateur::class, $userId));
        $this->client->request('GET', $this->url());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    private function editValues(int $userId): array
    {
        $page = $this->client->request('GET', $this->url('modifier/'.$userId));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        return $page->filter('form[name="utilisateur"]')->form()->getPhpValues();
    }

    private function csrf(string $tokenId): string
    {
        $stack = self::getContainer()->get('request_stack');
        $stack->push($this->client->getRequest());
        try {
            return self::getContainer()->get('security.csrf.token_manager')->getToken($tokenId)->getValue();
        } finally {
            $this->client->getRequest()->getSession()->save();
            $stack->pop();
        }
    }

    private function accountSnapshot(int $userId): array
    {
        $this->em->clear();
        $user = $this->em->find(Utilisateur::class, $userId);
        self::assertNotNull($user);
        $memberships = $this->em->getRepository(UtilisateurEntite::class)->findBy(['utilisateur' => $userId], ['id' => 'ASC']);
        return [
            'email' => $user->getEmail(),
            'prenom' => $user->getPrenom(),
            'nom' => $user->getNom(),
            'password' => $user->getPassword(),
            'verified' => $user->isVerified(),
            'resetToken' => $user->getResetToken(),
            'resetExpires' => $user->getResetTokenExpiresAt()?->format(DATE_ATOM),
            'globalRoles' => $user->getRoles(),
            'memberships' => array_map(static fn (UtilisateurEntite $membership) => [
                'entite' => $membership->getEntite()->getId(),
                'roles' => $membership->getRoles(),
                'status' => $membership->getStatus(),
            ], $memberships),
        ];
    }

    private function url(string $action = ''): string
    {
        return '/fr/administrateur/'.$this->entityId.'/utilisateur'.($action === '' ? '' : '/'.$action);
    }
}

final class DirectorProtectionJsonContains extends \Scienta\DoctrineJsonFunctions\Query\AST\Functions\Mysql\JsonContains
{
    protected function validatePlatform(\Doctrine\ORM\Query\SqlWalker $sqlWalker): void {}
}
