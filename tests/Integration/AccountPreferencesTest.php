<?php
namespace App\Tests\Integration;
use App\Entity\{Utilisateur, PersonalSignature};
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
final class AccountPreferencesTest extends KernelTestCase {
private KernelBrowser $client;
private array $database;
private array $emails = [];
private string $email;
private Utilisateur $user;
    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        self::getContainer()->get('cache.rate_limiter')->clear();
        self::getContainer()->get('twig')->addGlobal('entite', null); // Kernel is reused across anonymous and authenticated requests.
        $this->email = 'twofactor-'.bin2hex(random_bytes(5)).'@example.test';
        $user = (new Utilisateur())->setEmail($this->email)->setPrenom('Test')->setNom('Connexion');
        $user->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'Secret-password-47!'));
        $em->persist($user); $em->flush();
        $entity = (new \App\Entity\Entite())->setNom('Test 2FA')->setCreateur($user)->setPublic(false);
        $em->persist($entity); $em->flush(); $user->setEntite($entity);
        $membership = (new \App\Entity\UtilisateurEntite())->setUtilisateur($user)->setEntite($entity)->setCreateur($user)->setRoles(['TENANT_STAGIAIRE']);
        $em->persist($membership); $em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($entity)->setStatus('active')); $em->flush();
        $this->user = $user;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function ($email): void { $this->emails[] = $email; });
        self::getContainer()->set(MailerInterface::class, $mailer);
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot(); $this->client->catchExceptions(true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $scope) {
            if ($this->database[$index] === null) unset($GLOBALS['_'.$scope]['DATABASE_URL']);
            else $GLOBALS['_'.$scope]['DATABASE_URL'] = $this->database[$index];
        }
    }


public function testPersonalSectionsAndProfile(): void {
 $this->client->loginUser($this->user);
 foreach (['profil','securite','signature'] as $section) {
  $page=$this->client->request('GET','/fr/compte/preferences/'.$section);
  self::assertSame(200,$this->client->getResponse()->getStatusCode(), substr($this->client->getResponse()->getContent(),0,500));
  self::assertStringNotContainsString('Contrats et organisme',$page->filter('.pref-nav')->text());
 }
 $page=$this->client->request('GET','/fr/compte/preferences/profil');
 $this->client->submit($page->filter('form[name="profile"]')->form(['profile[prenom]'=>'Alice','profile[nom]'=>'Durand','profile[telephone]'=>'0601020304']));
 self::assertSame(302,$this->client->getResponse()->getStatusCode());
 self::assertSame('Alice',self::getContainer()->get('doctrine')->getRepository(Utilisateur::class)->find($this->user->getId())->getPrenom());
}
public function testPasswordRequiresCurrentPasswordThenLogsOut(): void {
 $this->client->loginUser($this->user);
 $page=$this->client->request('GET','/fr/compte/preferences/securite');
 $values=['password[current]'=>'incorrect','password[new][first]'=>'New-password-12345!','password[new][second]'=>'New-password-12345!'];
 $this->client->submit($page->filter('form[name="password"]')->form($values));
 self::assertSame(200,$this->client->getResponse()->getStatusCode());
 self::assertStringContainsString('actuel est incorrect',$this->client->getResponse()->getContent());
 $page=$this->client->request('GET','/fr/compte/preferences/securite'); $values['password[current]']='Secret-password-47!';
 $this->client->submit($page->filter('form[name="password"]')->form($values));
 self::assertSame('/fr/login',$this->client->getResponse()->headers->get('Location'));
 self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid(self::getContainer()->get('doctrine')->getRepository(Utilisateur::class)->find($this->user->getId()),'New-password-12345!'));
 $this->client->request('GET','/fr/compte/preferences'); self::assertSame(302,$this->client->getResponse()->getStatusCode());
}
public function testSignatureValidationOwnershipAndDeletion(): void {
 $this->client->loginUser($this->user);
 $page=$this->client->request('GET','/fr/compte/preferences/signature');
 $token=$page->filter('#personal-signature-form input[name="_token"]')->attr('value');
 $this->client->request('POST','/fr/compte/preferences/signature',['_token'=>'bad','image'=>'bad']);
 self::assertSame(403,$this->client->getResponse()->getStatusCode());
 $this->client->request('POST','/fr/compte/preferences/signature',['_token'=>$token,'image'=>'data:image/png;base64,'.base64_encode('invalid')]);
 self::assertSame(200,$this->client->getResponse()->getStatusCode());
 $image=imagecreatetruecolor(200,100); ob_start();imagepng($image);$data='data:image/png;base64,'.base64_encode(ob_get_clean());imagedestroy($image);
 $this->client->request('POST','/fr/compte/preferences/signature',['_token'=>$token,'image'=>$data]);
 self::assertSame(302,$this->client->getResponse()->getStatusCode());
 $em=self::getContainer()->get('doctrine')->getManager();
 self::assertCount(1,$em->getRepository(PersonalSignature::class)->findBy(['owner'=>$this->user]));
 $this->user=$em->find(Utilisateur::class,$this->user->getId());
 $other=(new Utilisateur())->setEmail('other@example.test')->setPrenom('Other')->setNom('Client')->setPassword('hash')->setEntite($this->user->getEntite()); $em->persist($other);
 $membership=(new \App\Entity\UtilisateurEntite())->setUtilisateur($other)->setEntite($this->user->getEntite())->setCreateur($this->user)->setRoles(['TENANT_STAGIAIRE']);$em->persist($membership);$em->flush();
 $this->client->loginUser($other);$page=$this->client->request('GET','/fr/compte/preferences/signature');self::assertStringNotContainsString('Votre signature enregistrée',$page->html());
 $this->client->loginUser($this->user);$page=$this->client->request('GET','/fr/compte/preferences/signature');self::assertStringContainsString('Votre signature enregistrée',$page->html());
 $this->client->submit($page->selectButton('Supprimer ma signature')->form());
 self::assertCount(0,$em->getRepository(PersonalSignature::class)->findAll());
}
public function testOrganizationPreferencesRemainRestrictedAndRenderForAdmin(): void {
 $this->client->loginUser($this->user);
 $this->client->request('GET','/fr/administrateur/1/preferences/formateurs/contrat');
 self::assertSame(403,$this->client->getResponse()->getStatusCode());
 $em=self::getContainer()->get('doctrine')->getManager();
 $user=$em->find(Utilisateur::class,$this->user->getId());
 $membership=$em->getRepository(\App\Entity\UtilisateurEntite::class)->findOneBy(['utilisateur'=>$user]);
 $membership->setRoles(['TENANT_ADMIN']);$em->flush();$this->client->loginUser($user);
 $page=$this->client->request('GET','/fr/administrateur/1/preferences/formateurs/contrat');
 self::assertSame(200,$this->client->getResponse()->getStatusCode(),(string)$this->client->getResponse()->headers->get('Location'));
 self::assertCount(11,$page->filter('details'));
 $this->client->request('POST','/fr/administrateur/1/preferences/formateurs/contrat/signature',['dataUrl'=>'invalid']);
 self::assertSame(403,$this->client->getResponse()->getStatusCode());
}
}
