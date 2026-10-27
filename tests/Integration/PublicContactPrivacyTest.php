<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class PublicContactPrivacyTest extends KernelTestCase
{
    private KernelBrowser $client;
    private array $originalDatabase;
    private array $messages = [];

    protected function setUp(): void
    {
        $this->originalDatabase = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function ($message): void { $this->messages[] = $message; });
        self::getContainer()->set(MailerInterface::class, $mailer);
        self::getContainer()->set('limiter.public_contact', new RateLimiterFactory([
            'id' => 'contact-privacy-test', 'policy' => 'sliding_window', 'limit' => 5, 'interval' => '15 minutes',
        ], new InMemoryStorage()));
        $this->client = new KernelBrowser(self::$kernel);
        $this->client->disableReboot();
        $this->client->catchExceptions(false);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $type) {
            if ($this->originalDatabase[$index] === null) unset($GLOBALS['_' . $type]['DATABASE_URL']);
            else $GLOBALS['_' . $type]['DATABASE_URL'] = $this->originalDatabase[$index];
        }
    }

    private function formData(): array
    {
        $crawler = $this->client->request('GET', '/fr/contact');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        return [
            'nom' => 'Test Contact', 'email' => 'contact-test@example.test',
            'message' => 'Demande de renseignements sur le service.',
            'website' => '', 'startedAt' => (string) (time() - 3),
            '_token' => $crawler->filter('form[name="contact"] input[name="contact[_token]"]')->first()->attr('value'),
        ];
    }

    private function submit(array $data, array $headers = []): void
    {
        $this->client->request('POST', '/fr/contact', ['contact' => $data], [], $headers + [
            'HTTP_ORIGIN' => 'http://localhost', 'REMOTE_ADDR' => '192.0.2.7',
        ]);
    }

    public function testContactWorksWithoutGoogleOrAudienceConsentAndShowsPrivacyNotice(): void
    {
        $data = $this->formData();
        $html = $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('recaptcha', strtolower($html));
        self::assertStringNotContainsString('fonts.googleapis', $html);
        self::assertStringNotContainsString('googletagmanager', $html);
        self::assertStringContainsString('data-privacy-notice="contact"', $html);
        self::assertStringContainsString('/fr/politique-de-confidentialite', $html);
        $this->submit($data, ['HTTP_REFERER' => 'https://external.example.test/']);
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame('/fr/contact', $this->client->getResponse()->headers->get('Location'));
        self::assertCount(1, $this->messages);
        self::assertSame('contact-test@example.test', $this->messages[0]->getReplyTo()[0]->getAddress());
    }

    public function testContactRejectsHoneypotAndInvalidSubmissionTimes(): void
    {
        $data = $this->formData();
        foreach ([['website' => 'spam'], ['startedAt' => '0'], ['startedAt' => (string) time()], ['startedAt' => (string) (time() - 90000)]] as $override) {
            $this->submit($override + $data);
            self::assertSame(302, $this->client->getResponse()->getStatusCode());
        }
        self::assertCount(0, $this->messages);
    }

    public function testContactRejectsCrossOriginCsrfAndLimitsRepeatedSubmissions(): void
    {
        $data = $this->formData();
        $this->submit(['_token' => 'invalid'] + $data, ['HTTP_ORIGIN' => 'https://external.example.test']);
        self::assertCount(0, $this->messages);
        for ($index = 0; $index < 4; ++$index) $this->submit($data);
        self::assertCount(4, $this->messages);
        $this->submit($data);
        self::assertSame(429, $this->client->getResponse()->getStatusCode());
        self::assertNotEmpty($this->client->getResponse()->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertCount(4, $this->messages);
    }
    public function testWhiteLabelVisitorsCanReachPlatformPrivacyInformation(): void
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        $host = (new \App\Entity\PublicHost())->setHost('training.example.test')->setName('Organisme test');
        $em->persist($host);
        $em->flush();
        $crawler = $this->client->request('GET', 'http://training.example.test/fr/catalogue');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertGreaterThan(0, $crawler->filter('a[href="https://wikiformation.fr/fr/politique-de-confidentialite"]')->count());
        self::assertGreaterThan(0, $crawler->filter('a[href="https://wikiformation.fr/fr/cookies"]')->count());
        self::assertSame(0, $crawler->filter('form.footer-form')->count());
        self::assertStringNotContainsString('recaptcha', strtolower($this->client->getResponse()->getContent()));
    }

}
