<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{Entite, Utilisateur, UtilisateurEntite};
use App\Entity\Billing\Plan;
use App\Service\Delegation\CommercialRules;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;

/** Disposable database only; no real account changes or email delivery. */
final class ProtectedAccountAlternatePathsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private array $database;

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');
        self::getContainer()->set(MailerInterface::class, $mailer);
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['_ENV', '_SERVER'] as $i => $global) {
            if ($this->database[$i] === null) unset($GLOBALS[$global]['DATABASE_URL']);
            else $GLOBALS[$global]['DATABASE_URL'] = $this->database[$i];
        }
    }

    private function user(string $name): Utilisateur
    {
        $user = (new Utilisateur())->setEmail($name.'@protected.example.test')
            ->setPrenom('Original')->setNom($name)->setPassword('original-password-hash')->setIsVerified(false);
        $this->em->persist($user);
        return $user;
    }

    public function testPublicRegistrationCannotReplaceUnverifiedProtectedAccounts(): void
    {
        $creator = $this->user('creator');
        $dirigeant = $this->user('dirigeant');
        $super = $this->user('super')->setRoles(['ROLE_SUPER_ADMIN']);
        $entity = (new Entite())->setNom('Organisme protégé')->setCreateur($creator)->setPublic(false);
        $this->em->persist($entity);
        $this->em->persist((new UtilisateurEntite())->setEntite($entity)->setUtilisateur($dirigeant)
            ->setCreateur($creator)->setRoles([UtilisateurEntite::TENANT_DIRIGEANT]));
        $this->em->flush();
        $client = self::getContainer()->get('test.client');
        $client->disableReboot();
        $client->catchExceptions(false);

        foreach ([$creator, $dirigeant, $super] as $target) {
            $page = $client->request('GET', '/fr/register');
            $client->submit($page->filter('form[name="registration_form"]')->form([
                'registration_form[prenom]' => 'Replacement',
                'registration_form[nom]' => 'Replacement',
                'registration_form[email]' => $target->getEmail(),
                'registration_form[password][first]' => 'Replacement123!',
                'registration_form[password][second]' => 'Replacement123!',
                'registration_form[consentementRgpd]' => '1',
            ]));
            // Duplicate validation or the protected-account recovery redirect may reject it.
            self::assertContains($client->getResponse()->getStatusCode(), [422, 303]);
            $saved = $this->em->find(Utilisateur::class, $target->getId());
            self::assertSame('original-password-hash', $saved->getPassword());
            self::assertSame('Original', $saved->getPrenom());
            self::assertFalse($saved->isVerified());
        }
    }

    public function testCommercialEditingProtectsLegacyCreatorWithOnlyLearnerRole(): void
    {
        $creator = $this->user('creator');
        $learner = $this->user('learner');
        $entity = (new Entite())->setNom('Organisme historique')->setCreateur($creator)->setPublic(false);
        $this->em->persist($entity);
        $memberships = [];
        foreach ([$creator, $learner] as $target) {
            $memberships[] = $membership = (new UtilisateurEntite())->setEntite($entity)->setUtilisateur($target)
                ->setCreateur($creator)->setRoles([UtilisateurEntite::TENANT_STAGIAIRE]);
            $this->em->persist($membership);
        }
        $this->em->flush();
        $rules = self::getContainer()->get(CommercialRules::class);
        self::assertNotNull($rules->editBlock($memberships[0]));
        self::assertNull($rules->editBlock($memberships[1]));
    }

    public function testOnboardingGrantsCreatorDirigeantRights(): void
    {
        $creator = $this->user('new-creator');
        $this->em->persist((new Plan())->setCode('SOLO')->setName('Solo')->setPriceMonthlyCents(2900));
        $this->em->flush();
        $client = self::getContainer()->get('test.client');
        $client->disableReboot();
        $client->catchExceptions(false);
        $client->loginUser($creator);
        $page = $client->request('GET', '/fr/onboarding');
        $client->submit($page->filter('#onboardingForm')->form([
            'entite_onboarding[planCode]' => 'SOLO',
            'entite_onboarding[nom]' => 'Nouvel organisme protégé',
            'entite_onboarding[email]' => 'new-organism@example.test',
            'entite_onboarding[telephone]' => '+33612345678',
            'entite_onboarding[ville]' => 'Nîmes',
        ]));
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $membership = $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur' => $creator]);
        self::assertNotNull($membership);
        self::assertTrue($membership->isActive());
        self::assertTrue($membership->hasRole(UtilisateurEntite::TENANT_DIRIGEANT));
        self::assertTrue($membership->isTenantAdmin());
    }
}
