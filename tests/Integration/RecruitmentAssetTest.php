<?php

declare(strict_types=1);
namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RecruitmentAssetTest extends WebTestCase
{
    private array $env;
    protected function setUp(): void
    {
        $this->env = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
    }
    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['_ENV', '_SERVER'] as $i => $key) {
            if ($this->env[$i] === null) unset($GLOBALS[$key]['DATABASE_URL']);
            else $GLOBALS[$key]['DATABASE_URL'] = $this->env[$i];
        }
    }
    public function testPublicAssetsAndConditionalCache(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = self::getContainer()->get('doctrine')->getManager();
        (new \Doctrine\ORM\Tools\SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        foreach (['recruitment.css', 'tom-select.css', 'leaflet.css', 'recruitment.js', 'recruitment-form.js', 'tom-select.min.js', 'leaflet.js'] as $file) {
            $client->request('GET', '/fr/recrutement-assets/'.$file);
            self::assertResponseIsSuccessful();
            self::assertStringStartsWith(str_ends_with($file, '.css') ? 'text/css' : 'application/javascript', $client->getResponse()->headers->get('Content-Type'));
            $etag = $client->getResponse()->headers->get('ETag');
            self::assertNotEmpty($etag);
            $client->request('GET', '/fr/recrutement-assets/'.$file, [], [], ['HTTP_IF_NONE_MATCH' => $etag]);
            self::assertResponseStatusCodeSame(304);
        }
        $client->request('GET', '/fr/recrutement-assets/subjects.json');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/fr/recrutement-assets/.env');
        self::assertResponseStatusCodeSame(404);
    }
}
