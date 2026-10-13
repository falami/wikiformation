<?php

declare(strict_types=1);
namespace App\Tests\Integration;

use App\Entity\{Entite, Entreprise, Utilisateur, UtilisateurEntite, Site, Session, SessionJour, Inscription};
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EntreprisePortalContextTest extends WebTestCase
{
    public function testRepresentativeCanSwitchCompaniesWithoutSeeingOtherEmployees(): void
    {
        $database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        try {
            $client = self::createClient(); $client->disableReboot(); $client->catchExceptions(false);
            $em = self::getContainer()->get('doctrine')->getManager();
            (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
            $user = (new Utilisateur())->setEmail('rh@portal.test')->setPassword('unused')->setPrenom('RH')->setNom('Test');
            $tenant = (new Entite())->setNom('Organisme')->setPublic(false)->setCreateur($user);
            $em->persist($user); $em->persist($tenant); $em->flush(); $user->setEntite($tenant);
            $membership = (new UtilisateurEntite())->setUtilisateur($user)->setEntite($tenant)->setCreateur($user)->setRoles(['TENANT_ENTREPRISE'])->setStatus('active');
            $em->persist($membership);
            $em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($tenant)->setStatus('active'));
            $site = (new Site())->setNom('Salle')->setSlug('portal-test')->setEntite($tenant)->setCreateur($user); $em->persist($site);
            $companies = []; $sessions = [];
            $primary = (new Entreprise())->setRaisonSociale('Entreprise principale')->setEntite($tenant)->setCreateur($user);
            $em->persist($primary); $user->setEntreprise($primary);
            foreach (['Alpha', 'Beta', 'Interdite'] as $name) {
                $company = (new Entreprise())->setRaisonSociale($name)->setEntite($tenant)->setCreateur($user);
                if ($name !== 'Interdite') $company->setRepresentant($user);
                else $user->addEntreprisesAssociee($company); // Being a trainee grants no company-wide access.
                $learner = (new Utilisateur())->setEmail(strtolower($name).'@portal.test')->setPassword('unused')->setPrenom($name)->setNom('Salarié');
                $session = (new Session())->setCode('SES-'.$name)->setEntite($tenant)->setCreateur($user)->setSite($site)->setFormationIntituleLibre('Formation '.$name);
                $session->addJour((new SessionJour())->setEntite($tenant)->setCreateur($user)->setDateDebut(new \DateTimeImmutable('2026-10-01 09:00'))->setDateFin(new \DateTimeImmutable('2026-10-01 17:00')));
                $registration = (new Inscription())->setEntite($tenant)->setCreateur($user)->setSession($session)->setStagiaire($learner)->setEntreprise($company);
                foreach ([$company, $learner, $session, $registration] as $record) $em->persist($record);
                $convention = (new \App\Entity\ConventionContrat())->setEntite($tenant)->setCreateur($user)->setSession($session)->setEntreprise($company)->setNumero('CC-'.$name)->setIntituleFormation('Formation '.$name);
                $convention->setPdfPath('fixture-preview.pdf');
                $sessions[] = $session;
                $convention->addInscription($registration); $em->persist($convention);
                if ($name === 'Beta') {
                    $second = (new Utilisateur())->setEmail('second@portal.test')->setPassword('unused')->setPrenom('Second')->setNom('<Salarié>');
                    $secondRegistration = (new Inscription())->setEntite($tenant)->setCreateur($user)->setSession($session)->setStagiaire($second)->setEntreprise($company);
                    $em->persist($second); $em->persist($secondRegistration); $convention->addInscription($secondRegistration);
                    $trainer = (new \App\Entity\Formateur())->setEntite($tenant)->setCreateur($user)->setUtilisateur($user); $em->persist($trainer); $session->setFormateur($trainer);
                    foreach ([2,3] as $day) $session->addJour((new SessionJour())->setEntite($tenant)->setCreateur($user)->setDateDebut(new \DateTimeImmutable('2026-10-0'.$day.' 09:00'))->setDateFin(new \DateTimeImmutable('2026-10-0'.$day.' 17:00')));
                    foreach ([$user,$learner,$second] as $signer) foreach ([1,2,3] as $day) foreach (\App\Enum\DemiJournee::cases() as $period) {
                        $em->persist((new \App\Entity\Emargement())->setEntite($tenant)->setCreateur($user)->setSession($session)->setUtilisateur($signer)->setRole($signer === $user ? 'formateur' : 'stagiaire')->setDateJour(new \DateTimeImmutable('2026-10-0'.$day))->setPeriode($period)->setSignedAt(new \DateTimeImmutable()));
                    }

                }
                $companies[] = $company;
            }
            $em->flush(); $client->loginUser($user);
            $base = '/fr/entreprise/'.$tenant->getId();
            $client->request('POST', $base.'/inscriptions/ajax'); self::assertResponseIsSuccessful();
            $data = json_decode($client->getResponse()->getContent(), true);
            self::assertCount(1, $data['data']); self::assertSame('SES-Alpha', $data['data'][0]['code']);
            $client->request('POST', $base.'/inscriptions/ajax?entreprise='.$companies[1]->getId()); self::assertResponseIsSuccessful();
            $data = json_decode($client->getResponse()->getContent(), true);
            self::assertCount(2, $data['data']); self::assertSame('SES-Beta', $data['data'][0]['code']);
            $client->request('GET', $base.'/calendar/feed?entreprise='.$companies[0]->getId()); self::assertResponseIsSuccessful();
            self::assertStringContainsString('Alpha', $client->getResponse()->getContent());
            self::assertStringNotContainsString('Beta', $client->getResponse()->getContent());
            $client->request('POST', $base.'/documents/emargements/ajax?entreprise='.$companies[1]->getId()); self::assertResponseIsSuccessful();
            self::assertStringContainsString('18/18', json_decode($client->getResponse()->getContent(),true)['data'][0]['signatures']);
            self::assertStringNotContainsString('108/108', $client->getResponse()->getContent());
            $client->request('POST', $base.'/documents/conventions/ajax?entreprise='.$companies[1]->getId()); self::assertResponseIsSuccessful();
            self::assertStringContainsString('CC-Beta', $client->getResponse()->getContent());
            $conventions = json_decode($client->getResponse()->getContent(), true);
            self::assertCount(1, $conventions['data']);
            self::assertStringContainsString('Signer', $conventions['data'][0]['actions']);
            self::assertSame('Beta Salarié<br>Second &lt;Salarié&gt;', $conventions['data'][0]['stagiaire']);
            self::assertStringNotContainsString('CC-Alpha', $client->getResponse()->getContent());
            foreach ([
                ['', 1],
                ['&session='.$sessions[1]->getId(), 1],
                ['&session='.$sessions[0]->getId(), 0],
                ['&session='.$sessions[2]->getId(), 0],
            ] as [$filter, $expectedCount]) {
                $client->request('GET', $base.'/documents/feed?entreprise='.$companies[1]->getId().$filter);
                self::assertResponseIsSuccessful();
                $documents=json_decode($client->getResponse()->getContent(),true)['data'];
                self::assertCount($expectedCount,$documents);
                if ($expectedCount) self::assertSame('CC-Beta',$documents[0]['name']);
            }
            $client->request('GET', $base.'/documents?entreprise='.$companies[1]->getId()); self::assertResponseIsSuccessful();
            self::assertSelectorExists('#portal-company option[selected][value="'.$companies[1]->getId().'"]');
            $client->request('GET', $base.'/dashboard?entreprise='.$companies[0]->getId()); self::assertResponseIsSuccessful();
            self::assertSelectorExists('#portal-company option[selected][value="'.$companies[0]->getId().'"]');
            self::assertSelectorTextContains('#company-pending-sessions', 'SES-Alpha');
            self::assertSelectorTextContains('#company-pending-sessions', 'Convention à signer');
            self::assertSelectorCount(1, '#company-pending-sessions [data-pending-convention]');
            self::assertSelectorTextContains('#company-pending-sessions [data-pending-convention] a', 'Consulter et signer');
            self::assertSelectorTextContains('#company-pending-sessions', '1 appréciation stagiaire manquante');
            self::assertStringNotContainsString('SES-Beta', $client->getCrawler()->filter('#company-pending-sessions')->text());
            $client->request('GET', $base.'/dashboard?entreprise='.$companies[1]->getId()); self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#company-pending-sessions', '2 appréciations stagiaires manquantes');
            self::assertStringNotContainsString('émargement manquant', $client->getCrawler()->filter('#company-pending-sessions')->text());

            $actor = $em->find(Utilisateur::class, $user->getId()); $actor->setEntreprise(null); $em->flush(); $client->loginUser($actor);
            $client->request('POST', $base.'/inscriptions/ajax?entreprise='.$companies[1]->getId()); self::assertResponseIsSuccessful();
            $actor = $em->find(Utilisateur::class, $user->getId());
            $otherTenant = (new Entite())->setNom('Autre organisme')->setPublic(false)->setCreateur($actor); $em->persist($otherTenant);
            $otherCompany = (new Entreprise())->setRaisonSociale('Autre organisme société')->setEntite($otherTenant)->setCreateur($actor)->setRepresentant($actor); $em->persist($otherCompany); $em->flush();
            $client->catchExceptions(true);
            $client->request('POST', $base.'/inscriptions/ajax?entreprise='.$otherCompany->getId()); self::assertResponseStatusCodeSame(403);
            $client->request('POST', $base.'/inscriptions/ajax?entreprise='.$companies[2]->getId()); self::assertResponseStatusCodeSame(403);
            $client->request('GET', $base.'/documents?entreprise='.$companies[2]->getId()); self::assertResponseStatusCodeSame(403);
        } finally {
            self::ensureKernelShutdown();
            foreach (['ENV', 'SERVER'] as $i => $name) {
                if ($database[$i] === null) unset($GLOBALS['_'.$name]['DATABASE_URL']);
                else $GLOBALS['_'.$name]['DATABASE_URL'] = $database[$i];
            }
        }
    }
}
