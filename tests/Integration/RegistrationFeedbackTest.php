<?php

namespace App\Tests\Integration;

use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RegistrationFeedbackTest extends KernelTestCase
{
    public function testRejectedSubmissionDisplaysErrorsInsteadOfASuccessResponse(): void
    {
        $database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        try {
            self::bootKernel();
            $em = self::getContainer()->get('doctrine')->getManager();
            (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
            $client = self::getContainer()->get('test.client');
            $client->disableReboot();
            $client->catchExceptions(false);
            $crawler = $client->request('GET', '/fr/register');
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertSame('false', $crawler->filter('form')->attr('data-turbo'));
            $client->request('POST', '/fr/register', ['registration_form' => [
                'prenom' => '', 'nom' => '', 'email' => 'invalid',
                'password' => ['first' => 'short', 'second' => 'different'],
            ]]);
            self::assertSame(422, $client->getResponse()->getStatusCode());
            self::assertStringContainsString('registration-errors', $client->getResponse()->getContent());
            self::assertStringContainsString('invalid-feedback', $client->getResponse()->getContent());
        } finally {
            self::ensureKernelShutdown();
            foreach (['_ENV', '_SERVER'] as $i => $global) {
                if ($database[$i] === null) { unset($GLOBALS[$global]['DATABASE_URL']); }
                else { $GLOBALS[$global]['DATABASE_URL'] = $database[$i]; }
            }
        }
    }
}
