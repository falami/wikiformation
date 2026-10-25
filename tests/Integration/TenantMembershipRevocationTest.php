<?php
declare(strict_types=1);
namespace App\Tests\Integration;

use App\Entity\{Entite, Utilisateur, UtilisateurEntite};
use App\Entity\Elearning\ElearningCourse;
use App\Entity\Marketplace\{Access, Purchase, Seller};
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};

final class TenantMembershipRevocationTest extends KernelTestCase
{
    private array $database;
    private EntityManagerInterface $em;
    private KernelBrowser $client;
    private int $adminId;
    private int $entityId;
    private int $otherId;
    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $admin = $this->user('admin');
        $entity = (new Entite())->setNom('Organisme A')->setCreateur($admin)->setPublic(false);
        $other = (new Entite())->setNom('Organisme B')->setCreateur($admin)->setPublic(false);
        foreach ([$entity, $other] as $record) $this->em->persist($record);
        $this->em->flush();$admin->setEntite($entity);
        $this->membership($admin, $entity, $admin, [UtilisateurEntite::TENANT_ADMIN]);
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($entity)->setStatus('active'));
        $this->em->flush();
        $this->adminId = $admin->getId();$this->entityId = $entity->getId();$this->otherId = $other->getId();
        $this->client = self::getContainer()->get('test.client');$this->client->disableReboot();$this->client->catchExceptions(false);
        $this->client->loginUser($admin);
        $this->client->request('GET', '/fr/administrateur/'.$this->entityId.'/utilisateur');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $name) {
            if ($this->database[$index] === null) unset($GLOBALS['_'.$name]['DATABASE_URL']);
            else $GLOBALS['_'.$name]['DATABASE_URL'] = $this->database[$index];
        }
    }
    private function user(string $name): Utilisateur
    {
        $user = (new Utilisateur())->setEmail($name.'@example.test')->setPassword('unused')->setPrenom($name)->setNom('Test')->setRoles(['ROLE_USER'])->setIsVerified(false);
        $this->em->persist($user);return $user;
    }
    private function membership(Utilisateur $user, Entite $entity, Utilisateur $creator, array $roles = [UtilisateurEntite::TENANT_STAGIAIRE]): UtilisateurEntite
    {
        $membership = (new UtilisateurEntite())->setUtilisateur($user)->setEntite($entity)->setCreateur($creator)->setRoles($roles);
        $this->em->persist($membership);return $membership;
    }
    private function revoke(int $id, ?string $token = null): void
    {
        if ($token === null) {
            $stack = self::getContainer()->get('request_stack');$stack->push($this->client->getRequest());
            try { $token = self::getContainer()->get('security.csrf.token_manager')->getToken('delete_user_'.$id)->getValue(); }
            finally { $this->client->getRequest()->getSession()->save();$stack->pop(); }
        }
        $this->client->request('POST', '/fr/administrateur/'.$this->entityId.'/utilisateur/supprimer/'.$id, ['_token' => $token]);
    }
    public function testTenantRevocationPreservesSharedAccountAndPaidCourse(): void
    {
        $admin = $this->em->find(Utilisateur::class, $this->adminId);$entity = $this->em->find(Entite::class, $this->entityId);$other = $this->em->find(Entite::class, $this->otherId);
        $buyer = $this->user('buyer');$buyer->setEntite($entity);
        $local = $this->membership($buyer, $entity, $admin);$remote = $this->membership($buyer, $other, $admin);
        $course = (new ElearningCourse())->setEntite($other)->setCreateur($admin)->setTitre('Cours acquis')->setSlug('paid');
        $seller = new Seller();$seller->user = $admin;
        $purchase = new Purchase();$purchase->id = bin2hex(random_bytes(16));$purchase->buyer = $buyer;$purchase->seller = $seller;$purchase->status = 'paid';$purchase->paidAt = new \DateTimeImmutable();$purchase->createdAt = new \DateTimeImmutable();
        $access = new Access();$access->buyer = $buyer;$access->course = $course;$access->entite = $other;$access->purchase = $purchase;$access->snapshot = ['title' => 'Cours acquis'];$access->createdAt = new \DateTimeImmutable();
        foreach ([$course, $seller, $purchase, $access] as $record) $this->em->persist($record);$this->em->flush();
        $ids = [$buyer->getId(), $local->getId(), $remote->getId(), $purchase->id, $access->id];
        $this->revoke($ids[0], 'bad-token');self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->em->find(UtilisateurEntite::class, $ids[1])->isActive());
        $this->revoke($ids[0]);self::assertSame(302, $this->client->getResponse()->getStatusCode());$this->em->clear();
        self::assertNotNull($this->em->find(Utilisateur::class, $ids[0]));
        self::assertSame(UtilisateurEntite::STATUS_SUSPENDED, $this->em->find(UtilisateurEntite::class, $ids[1])->getStatus());
        self::assertTrue($this->em->find(UtilisateurEntite::class, $ids[2])->isActive());
        self::assertNotNull($this->em->find(Purchase::class, $ids[3]));self::assertFalse($this->em->find(Access::class, $ids[4])->suspended);
        $repo = $this->em->getRepository(UtilisateurEntite::class);$buyer = $this->em->find(Utilisateur::class, $ids[0]);
        self::assertFalse($repo->userHasEntite($buyer, $this->em->find(Entite::class, $this->entityId)));
        self::assertFalse($repo->hasRoleInEntite($buyer, $this->em->find(Entite::class, $this->entityId), UtilisateurEntite::TENANT_STAGIAIRE));
        self::assertSame($this->otherId, $repo->findFirstEntiteForUser($buyer)->getId());
    }
    public function testLastMembershipIsRevokedWithoutDeletingLoginOrAdmin(): void
    {
        $admin = $this->em->find(Utilisateur::class, $this->adminId);$entity = $this->em->find(Entite::class, $this->entityId);
        $buyer = $this->user('single');$this->membership($buyer, $entity, $admin);$this->em->flush();$id = $buyer->getId();
        $this->revoke($id);self::assertSame(302, $this->client->getResponse()->getStatusCode());$this->em->clear();
        $buyer = $this->em->find(Utilisateur::class, $id);self::assertNotNull($buyer);
        self::assertNull($this->em->getRepository(UtilisateurEntite::class)->findFirstEntiteForUser($buyer));
        $this->revoke($this->adminId);$this->em->clear();
        self::assertTrue($this->em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur' => $this->adminId, 'entite' => $this->entityId])->isActive());
    }
    public function testForeignTenantAccountCannotBeRevoked(): void
    {
        $admin = $this->em->find(Utilisateur::class, $this->adminId);
        $other = $this->em->find(Entite::class, $this->otherId);
        $buyer = $this->user('foreign');$link = $this->membership($buyer, $other, $admin);$this->em->flush();
        $id = $buyer->getId();$linkId = $link->getId();
        $this->client->catchExceptions(true);$this->revoke($id);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());$this->em->clear();
        self::assertTrue($this->em->find(UtilisateurEntite::class, $linkId)->isActive());
    }
    public function testStudentCannotRevokeAnotherMembership(): void
    {
        $admin = $this->em->find(Utilisateur::class, $this->adminId);$entity = $this->em->find(Entite::class, $this->entityId);
        $actor = $this->user('student');$target = $this->user('target');
        $this->membership($actor, $entity, $admin);$link = $this->membership($target, $entity, $admin);$this->em->flush();
        $targetId = $target->getId();$linkId = $link->getId();
        $this->client->loginUser($actor);$this->client->catchExceptions(true);
        $this->revoke($targetId, 'irrelevant');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());$this->em->clear();
        self::assertTrue($this->em->find(UtilisateurEntite::class, $linkId)->isActive());
    }
}
