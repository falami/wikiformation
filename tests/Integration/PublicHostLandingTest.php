<?php
namespace App\Tests\Integration;

use App\Entity\PublicHost;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PublicHostLandingTest extends KernelTestCase
{
    public function testExternalHomeDoesNotRedirectThePublicHost(): void
    {
        $original = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        try {
            self::bootKernel();
            $em = self::getContainer()->get('doctrine')->getManager();
            (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
            $host = (new PublicHost())->setHost('formations.itforyou.fr')->setName('I.T. For You')->setHomeUrl('https://itforyou.fr');
            $em->persist($host); $em->flush();
            $id = $host->getId();
            $client = self::getContainer()->get('test.client'); $client->disableReboot(); $client->catchExceptions(true);
            $client->request('GET', 'https://formations.itforyou.fr/fr/');
            self::assertSame(302, $client->getResponse()->getStatusCode());
            self::assertSame('/fr/formation', $client->getResponse()->headers->get('Location'));
            $host = $em->find(PublicHost::class, $id); $host->setCalendarEnabled(false); $em->flush();
            $client->request('GET', 'https://formations.itforyou.fr/fr/');
            self::assertSame(302, $client->getResponse()->getStatusCode());
            self::assertSame('/fr/catalogue', $client->getResponse()->headers->get('Location'));
            $host = $em->find(PublicHost::class, $id); $host->setCatalogueEnabled(false); $em->flush();
            $client->request('GET', 'https://formations.itforyou.fr/fr/');
            self::assertSame(404, $client->getResponse()->getStatusCode());
            self::assertNull($client->getResponse()->headers->get('Location'));
        } finally {
            self::ensureKernelShutdown();
            foreach (['ENV','SERVER'] as $i=>$name) {
                if ($original[$i] === null) unset($GLOBALS['_'.$name]['DATABASE_URL']);
                else $GLOBALS['_'.$name]['DATABASE_URL'] = $original[$i];
            }
        }
    }
}
