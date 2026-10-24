<?php
namespace App\Tests\Integration;

use App\Entity\{Entite, Utilisateur, Categorie, Formateur};
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class FormationQuickCreateTest extends KernelTestCase
{
    public function testCreationDuplicatesAndCsrf(): void
    {
        $database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        try {
            self::bootKernel();
            $em = self::getContainer()->get('doctrine')->getManager();
            (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
            $admin = (new Utilisateur())->setEmail('admin@test.fr')->setPrenom('Admin')->setNom('Test')->setPassword('unused')->setRoles(['ROLE_SUPER_ADMIN']);
            $entite = (new Entite())->setNom('Test')->setCreateur($admin)->setPublic(false)->setSiret('12345678900012')->setNumeroTva('FR12345678901')->setAdresse('1 rue Test')->setCodePostal('75001')->setVille('Paris')->setPays('France');
            $em->persist($admin); $em->persist($entite);
            $admin->setEntite($entite);
            $em->persist((new \App\Entity\UtilisateurEntite())->setUtilisateur($admin)->setEntite($entite)->setCreateur($admin)->setRoles(['TENANT_ADMIN']));
            $em->flush();
            $client = self::getContainer()->get('test.client');
            $client->disableReboot(); $client->catchExceptions(false); $client->loginUser($admin);
            $base = '/fr/administrateur/'.$entite->getId().'/formation/creation-rapide/';
            $submit = function (string $kind, array $values) use ($client, $base) {
                $client->request('GET', $base.$kind);
                self::assertSame(200, $client->getResponse()->getStatusCode());
                $html = json_decode($client->getResponse()->getContent(), true)['html'];
                $crawler = new Crawler($html, 'http://localhost');
                $form = $crawler->filter('form')->form();
                $params = $form->getPhpValues();
                $name = array_key_first($params);
                $params[$name] = array_replace($params[$name], $values);
                $client->request('POST', $base.$kind, $params);
            };
            $submit('categorie', ['nom'=>'Sécurité']);
            self::assertSame(201, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
            $parent = json_decode($client->getResponse()->getContent(), true)['id'];
            $submit('categorie', ['nom'=>'Incendie', 'parent'=>$parent]);
            self::assertSame(201, $client->getResponse()->getStatusCode());
            $submit('categorie', ['nom'=>' securite ']);
            self::assertSame(422, $client->getResponse()->getStatusCode());
            self::assertSame(2, $em->getRepository(Categorie::class)->count([]));
            $admin = $em->find(Utilisateur::class, $admin->getId());
            $entite = $em->find(Entite::class, $entite->getId());
            $site = (new \App\Entity\Site())->setNom('Site test')->setSlug('site-test')->setEntite($entite)->setCreateur($admin);
            $em->persist($site); $em->flush();
            $submit('engin', ['nom'=>'Nacelle test', 'site'=>$site->getId(), 'type'=>\App\Enum\EnginType::NACELLE->value]);
            self::assertSame(201, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
            $submit('engin', ['nom'=>'NACELLE TEST', 'site'=>$site->getId(), 'type'=>\App\Enum\EnginType::NACELLE->value]);
            self::assertSame(422, $client->getResponse()->getStatusCode());
            $admin = $em->find(Utilisateur::class, $admin->getId());
            $other = (new Entite())->setNom('Other')->setCreateur($admin)->setPublic(false)->setSiret('12345678900013')->setNumeroTva('FR12345678902')->setAdresse('2 rue Test')->setCodePostal('75001')->setVille('Paris')->setPays('France');
            $foreign = (new Categorie())->setNom('Foreign')->setSlug('foreign')->setEntite($other)->setCreateur($admin);
            $em->persist($other); $em->persist($foreign); $em->flush();
            $submit('categorie', ['nom'=>'Forbidden parent', 'parent'=>$foreign->getId()]);
            self::assertSame(422, $client->getResponse()->getStatusCode());
            $site = $em->find(\App\Entity\Site::class, $site->getId());
            $other = $em->find(Entite::class, $other->getId());
            $site->setEntite($other); $em->flush();
            $submit('engin', ['nom'=>'Forbidden site', 'site'=>$site->getId(), 'type'=>\App\Enum\EnginType::NACELLE->value]);
            self::assertSame(422, $client->getResponse()->getStatusCode());
            $submit('formateur', ['prenom'=>'Jean', 'nom'=>'Test', 'email'=>'jean@test.fr']);
            self::assertSame(201, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
            $submit('formateur', ['prenom'=>'Jean', 'nom'=>'Test', 'email'=>'JEAN@test.fr']);
            self::assertSame(422, $client->getResponse()->getStatusCode());
            self::assertSame(1, $em->getRepository(Formateur::class)->count([]));
            $client->request('POST', $base.'categorie', ['categorie'=>['nom'=>'Invalid csrf']]);
            self::assertSame(422, $client->getResponse()->getStatusCode());
            self::assertSame(3, $em->getRepository(Categorie::class)->count([]));
        } finally {
            self::ensureKernelShutdown();
            foreach (['_ENV', '_SERVER'] as $i=>$global) {
                if ($database[$i] === null) unset($GLOBALS[$global]['DATABASE_URL']);
                else $GLOBALS[$global]['DATABASE_URL'] = $database[$i];
            }
        }
    }
}
