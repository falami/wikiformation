<?php
namespace App\Tests\Integration;

use App\Entity\{Entite, Utilisateur, UtilisateurEntite};
use App\Entity\Billing\EntiteSubscription;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SuperConsoleTenantTest extends KernelTestCase
{
    private array $originalDatabase;
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV','SERVER'] as $i=>$name) {
            if ($this->originalDatabase[$i] === null) unset($GLOBALS['_'.$name]['DATABASE_URL']);
            else $GLOBALS['_'.$name]['DATABASE_URL'] = $this->originalDatabase[$i];
        }
    }
    public function testLinksUsersSubscriptionAndTrialTargetSelectedTenant(): void
    {
        $this->originalDatabase = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $em = self::getContainer()->get('doctrine')->getManager();
        $em->getConfiguration()->addCustomStringFunction('JSON_CONTAINS', SuperConsoleJsonContains::class);
        $em->getConnection()->getNativeConnection()->sqliteCreateFunction('JSON_CONTAINS', static fn($json,$value) => (int)in_array(json_decode($value,true),json_decode($json,true),true));
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $admin = (new Utilisateur())->setEmail('super@example.test')->setNom('Admin')->setPrenom('Super')->setPassword('unused')->setRoles(['ROLE_SUPER_ADMIN']);
        $learner = (new Utilisateur())->setEmail('target-learner@example.test')->setPassword('unused')->setPrenom('Target')->setNom('Learner');
        $home = (new Entite())->setNom('Home organisation')->setCreateur($admin)->setPublic(false);
        $target = (new Entite())->setNom('Target organisation')->setCreateur($admin)->setPublic(false);
        foreach ([$admin,$learner,$home,$target] as $entity) $em->persist($entity);
        $em->flush(); $admin->setEntite($home);
        $em->persist((new UtilisateurEntite())->setEntite($home)->setUtilisateur($admin)->setCreateur($admin)->setRoles(['TENANT_ADMIN']));
        $em->persist((new UtilisateurEntite())->setEntite($target)->setUtilisateur($learner)->setCreateur($admin)->setRoles(['TENANT_STAGIAIRE']));
        // Subscription IDs deliberately differ from entity IDs.
        $targetSub = (new EntiteSubscription())->setEntite($target)->setStatus('active');
        $em->persist($targetSub); $em->flush();
        $homeSub = (new EntiteSubscription())->setEntite($home)->setStatus('active');
        $em->persist($homeSub); $em->flush();
        $homeId=$home->getId(); $targetId=$target->getId(); $subId=$targetSub->getId();
        $router=self::getContainer()->get('router');
        $url=fn($name,$args=[])=>$router->generate($name,$args);
        $client=self::getContainer()->get('test.client'); $client->disableReboot(); $client->catchExceptions(false); $client->loginUser($admin);
        $crawler=$client->request('GET',$url('app_super_admin_console',['entite'=>$homeId]));
        self::assertSame(200,$client->getResponse()->getStatusCode());
        $usersUrl=$url('app_super_entite_users_index',['entite'=>$targetId]);
        $billingUrl=$url('app_super_billing_subscription_show',['entite'=>$targetId]);
        self::assertGreaterThan(0,$crawler->filter('a[href="'.$usersUrl.'"]')->count());
        self::assertGreaterThan(0,$crawler->filter('a[href="'.$billingUrl.'"]')->count());
        $client->request('GET',$usersUrl);
        self::assertSame(200,$client->getResponse()->getStatusCode());
        self::assertStringContainsString('target-learner@example.test',$client->getResponse()->getContent());
        $client->request('GET',$billingUrl);
        self::assertSame(200,$client->getResponse()->getStatusCode());
        self::assertStringContainsString('Target organisation',$client->getResponse()->getContent());
        $client->request('GET',$url('app_super_billing_subscription_edit',['entite'=>$targetId,'id'=>$subId]));
        self::assertSame(200,$client->getResponse()->getStatusCode());
        $client->catchExceptions(true);
        $client->request('GET',$url('app_super_billing_subscription_edit',['entite'=>$homeId,'id'=>$subId]));
        self::assertSame(404,$client->getResponse()->getStatusCode());
        $crawler=$client->request('GET',$url('app_super_admin_entite_trial',['entite'=>$homeId,'e'=>$targetId]));
        self::assertSame(200,$client->getResponse()->getStatusCode());
        self::assertStringContainsString('SUPER / Target organisation',$client->getResponse()->getContent());
        $client->submit($crawler->selectButton('Offrir')->form(['grant_trial[days]'=>21]));
        self::assertSame(302,$client->getResponse()->getStatusCode());
        self::assertStringContainsString('/super/'.$homeId.'/console',$client->getResponse()->headers->get('Location'));
        $em->clear();
        self::assertSame('trialing',$em->getRepository(EntiteSubscription::class)->find($subId)->getStatus());
        self::assertSame('active',$em->getRepository(EntiteSubscription::class)->findOneBy(['entite'=>$homeId])->getStatus());
        self::assertSame((new \DateTimeImmutable('+21 days'))->format('Y-m-d'), $em->find(EntiteSubscription::class,$subId)->getTrialEndsAt()->format('Y-m-d'));
        $client->loginUser($em->find(Utilisateur::class,$learner->getId()));
        $client->request('GET',$billingUrl);
        self::assertSame(403,$client->getResponse()->getStatusCode());

    }
}

final class SuperConsoleJsonContains extends \Scienta\DoctrineJsonFunctions\Query\AST\Functions\Mysql\JsonContains
{
    protected function validatePlatform(\Doctrine\ORM\Query\SqlWalker $sqlWalker): void {}
}
