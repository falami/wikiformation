<?php

declare(strict_types=1);
namespace App\Tests\Integration;

use App\Entity\Utilisateur;
use App\Security\TwoFactor\EmailChallenge;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EmailTwoFactorLoginTest extends KernelTestCase
{
    private KernelBrowser $client;
    private array $database;
    private array $emails = [];
    private string $email;

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
        $em->persist($membership); $em->flush();
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

    private function passwordLogin(string $password = 'Secret-password-47!'): void
    {
        $page = $this->client->request('GET', '/fr/login');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $form = $page->filter('form')->form();
        $form['_username'] = $this->email; $form['_password'] = $password;
        $this->client->submit($form);
    }

    public function testPasswordAloneCannotAccessAccountAndValidEmailCodeCompletesLogin(): void
    {
        $this->passwordLogin();
        self::assertSame('/2fa', $this->client->getResponse()->headers->get('Location'));
        self::assertCount(1, $this->emails);
        self::assertInstanceOf(TemplatedEmail::class, $this->emails[0]);
        self::assertSame($this->email, $this->emails[0]->getTo()[0]->getAddress());
        $code = $this->emails[0]->getContext()['code'];
        self::assertMatchesRegularExpression('/^[0-9]{6}$/', $code);
        $session = $this->client->getSession();
        self::assertStringNotContainsString('"'.$code.'"', json_encode($session->get(EmailChallenge::KEY)));
        foreach (['/fr/workspace', '/fr/administrateur/1/dashboard', '/fr/formateur/1/dashboard', '/fr/login'] as $url) {
            $this->client->request('GET', $url);
            self::assertSame('/2fa', parse_url($this->client->getResponse()->headers->get('Location'), PHP_URL_PATH));
        }
        $page = $this->client->request('GET', '/2fa');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertCount(1, $this->emails); // Reloads do not send additional emails.
        $form = $page->filter('form[action="/2fa_check"]')->form();
        $form['_auth_code'] = $code;
        $this->client->submit($form);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertNotSame('/2fa', $this->client->getResponse()->headers->get('Location'));
        $this->client->request('GET', '/fr/workspace');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->client->getSession()->has(EmailChallenge::KEY));
        self::assertNull($this->client->getCookieJar()->get('REMEMBERME'));
        $this->client->request('GET', '/fr/logout');
        $this->passwordLogin();
        self::assertSame('/2fa', $this->client->getResponse()->headers->get('Location'));
        self::assertCount(2, $this->emails);
    }

    public function testBadPasswordSendsNothing(): void
    {
        $this->passwordLogin('incorrect');
        self::assertCount(0, $this->emails);
        self::assertSame('/fr/login', parse_url($this->client->getResponse()->headers->get('Location'), PHP_URL_PATH));
    }

    public function testPasswordBruteForceIsLimited(): void
    {
        for ($attempt = 0; $attempt < 6; ++$attempt) $this->passwordLogin('incorrect');
        $this->passwordLogin();
        self::assertCount(0, $this->emails);
        self::assertSame('/fr/login', parse_url($this->client->getResponse()->headers->get('Location'), PHP_URL_PATH));
    }

    public function testInvalidCodeAndInvalidCsrfDoNotGrantAccessAndResendIsThrottled(): void
    {
        $this->passwordLogin();
        $page = $this->client->request('GET', '/2fa');
        $form = $page->filter('form[action="/2fa_check"]')->form();
        $form['_auth_code'] = 'not-valid';
        $this->client->submit($form);
        self::assertSame('/2fa', parse_url($this->client->getResponse()->headers->get('Location'), PHP_URL_PATH));
        $this->client->request('POST', '/2fa_check', ['_auth_code' => $this->emails[0]->getContext()['code'], '_csrf_token' => 'forged']);
        self::assertSame('/2fa', parse_url($this->client->getResponse()->headers->get('Location'), PHP_URL_PATH));
        $page = $this->client->request('GET', '/2fa');
        $this->client->submit($page->filter('form[action="/fr/2fa/resend"]')->form());
        self::assertCount(1, $this->emails);
        $page = $this->client->followRedirect();
        self::assertStringContainsString('patienter une minute', $page->text());
        $this->client->request('GET', '/fr/workspace');
        self::assertSame('/2fa', parse_url($this->client->getResponse()->headers->get('Location'), PHP_URL_PATH));
    }
}
