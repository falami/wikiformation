<?php

namespace App\Tests\Integration;

use App\Entity\{ContratFormateur, Entite, Formateur, Session, SessionJour, Site, Utilisateur, UtilisateurEntite};
use App\Enum\ContratFormateurStatus;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};

final class TrainerContractSignatureTest extends KernelTestCase
{
    private array $originalDatabase;
    private EntityManagerInterface $em;
    private Entite $entite;
    private Utilisateur $user;
    private ContratFormateur $contract;

    protected function setUp(): void
    {
        $this->originalDatabase = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->user = (new Utilisateur())->setEmail('signature@example.test')->setPassword('unused')->setPrenom('Test')->setNom('Signature')->setRoles(['ROLE_SUPER_ADMIN']);
        $this->entite = (new Entite())->setNom('Organisme signature')->setPublic(false)->setCreateur($this->user);
        $this->em->persist($this->user); $this->em->persist($this->entite); $this->em->flush();
        $this->user->setEntite($this->entite);
        $this->user->addUtilisateurEntite((new UtilisateurEntite())->setUtilisateur($this->user)->setEntite($this->entite)->setCreateur($this->user)->setRoles(['TENANT_FORMATEUR', 'TENANT_ADMIN']));
        $this->em->persist((new \App\Entity\Billing\EntiteSubscription())->setEntite($this->entite)->setStatus('active'));
        $trainer = (new Formateur())->setUtilisateur($this->user)->setEntite($this->entite)->setCreateur($this->user);
        $this->user->setFormateur($trainer);
        $site = (new Site())->setNom('Salle test')->setSlug('signature-test')->setEntite($this->entite)->setCreateur($this->user);
        $session = (new Session())->setEntite($this->entite)->setCreateur($this->user)->setCode('SIGN-TEST')->setSite($site)->setTypeFinancement(\App\Enum\TypeFinancement::OUI)->setFormationIntituleLibre('Mission sur mesure');
        foreach ([['09:00', '12:30', null], ['13:30', '17:00', $trainer]] as [$start, $end, $assigned]) {
            $session->addJour((new SessionJour())->setEntite($this->entite)->setCreateur($this->user)->setDateDebut(new \DateTimeImmutable('2026-10-05 ' . $start))->setDateFin(new \DateTimeImmutable('2026-10-05 ' . $end))->setFormateur($assigned));
        }
        $this->contract = (new ContratFormateur())->setEntite($this->entite)->setCreateur($this->user)->setSession($session)->setFormateur($trainer)->setNumero('CF-SIGN-TEST');
        foreach ([$trainer, $site, $session, $this->contract] as $entity) $this->em->persist($entity);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $type) {
            if ($this->originalDatabase[$index] === null) unset($GLOBALS['_' . $type]['DATABASE_URL']);
            else $GLOBALS['_' . $type]['DATABASE_URL'] = $this->originalDatabase[$index];
        }
    }

    public function testSignatureRequiresValidCsrfAndDisplaysOnlyAssignedSlots(): void
    {
        $client = $this->client();
        $url = $this->url('app_formateur_contrat_sign');
        $crawler = $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Mission sur mesure', $crawler->text());
        self::assertStringContainsString('13h30', $crawler->text());
        self::assertStringNotContainsString('09h00', $crawler->text());
        $token = $crawler->filter('form#form-signature input[name="_token"]')->attr('value');
        self::assertNotEmpty($token);
        foreach ([[], ['_token' => 'invalid']] as $parameters) {
            $client->request('POST', $url, $parameters + ['use_saved_signature' => '1']);
            self::assertSame(403, $client->getResponse()->getStatusCode());
            self::assertNull($this->contract->getSignatureAt());
        }
        // Un jeton valide atteint la validation de signature, sans générer de PDF ni signer.
        $client->request('POST', $url, ['_token' => $token, 'signature_data' => '']);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame(ContratFormateurStatus::BROUILLON, $this->contract->getStatus());
        self::assertNull($this->contract->getSignatureAt());
        self::assertFalse($this->contract->getFormateur()->isAssujettiTva());
    }

    public function testSignatureCannotUseAnotherOrganismsRouteAndFreeTitleAdminPageWorks(): void
    {
        $other = (new Entite())->setNom('Autre organisme')->setPublic(false)->setCreateur($this->user);
        $this->em->persist($other); $this->em->flush();
        $client = $this->client();
        foreach (['GET', 'POST'] as $method) {
            $client->request($method, $this->url('app_formateur_contrat_sign', $other));
            self::assertSame(404, $client->getResponse()->getStatusCode());
        }
        $client->request('GET', self::getContainer()->get('router')->generate('app_administrateur_formateurs_contrats_show', ['entite' => $this->entite->getId(), 'id' => $this->contract->getId()]));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Mission sur mesure', $client->getResponse()->getContent());
        $document = self::getContainer()->get(\App\Service\Pdf\ContratFormateurDocument::class);
        $html = self::getContainer()->get('twig')->render('pdf/contrat_formateur.html.twig', $document->templateData($this->contract));
        self::assertStringContainsString('Mission sur mesure', $html);
        self::assertStringContainsString('13h30', $html);
        self::assertStringNotContainsString('09h00', $html);
    }

    public function testMissionPdfUsesTheDeclaredConventionCountWithoutStudentAccounts(): void
    {
        $this->addConventionWithExpectedCount(12);
        $this->em->refresh($this->contract->getSession());
        $document = self::getContainer()->get(\App\Service\Pdf\ContratFormateurDocument::class);
        $data = $document->templateData($this->contract);
        self::assertSame(12, $data['effectifStage']);
        self::assertCount(0, $data['stagiaires']);
        $html = self::getContainer()->get('twig')->render('pdf/contrat_formateur.html.twig', $data);
        self::assertStringContainsString('12 stagiaire(s)', $html);
        self::assertStringContainsString('Liste nominative à communiquer.', $html);
    }

    public function testSigningUsesTheSameExpectedCountAndDoesNotRegenerateASignedDocument(): void
    {
        $this->addConventionWithExpectedCount(8);
        $pdf = $this->createMock(\App\Service\Pdf\PdfManager::class);
        $pdf->expects(self::once())->method('contratFormateur')->with(
            self::callback(static fn (array $data): bool => $data['effectifStage'] === 8 && $data['contrat']->getStatus() === ContratFormateurStatus::SIGNE),
            self::callback(static fn (string $name): bool => preg_match('/^contrat_formateur_CF-SIGN-TEST_v1_[a-f0-9]{16}\.pdf$/', $name) === 1),
        )->willReturn('/tmp/unused-mocked-contract.pdf');
        self::getContainer()->set(\App\Service\Pdf\PdfManager::class, $pdf);
        $client = $this->client();
        $url = $this->url('app_formateur_contrat_sign');
        $crawler = $client->request('GET', $url);
        $token = $crawler->filter('form#form-signature input[name="_token"]')->attr('value');
        $client->request('POST', $url, ['_token' => $token, 'signature_data' => 'data:image/png;base64,test-signature']);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame(ContratFormateurStatus::SIGNE, $this->em->find(ContratFormateur::class, $this->contract->getId())->getStatus(), (string) $client->getResponse()->headers->get('Location'));
        // Le PDF mocké n'existe pas : le chemin protégé ne doit pas en recréer un après signature.
        $client->request('GET', $this->url('app_formateur_contrat_regen_pdf'));
        self::assertSame(409, $client->getResponse()->getStatusCode());
    }

    private function addConventionWithExpectedCount(int $count): void
    {
        $company = (new \App\Entity\Entreprise())->setEntite($this->entite)->setCreateur($this->user)->setRaisonSociale('Entreprise effectif');
        $convention = (new \App\Entity\ConventionContrat())->setEntite($this->entite)->setCreateur($this->user)
            ->setSession($this->contract->getSession())->setEntreprise($company)->setNumero('CONV-EFFECTIF')->setEffectifPrevisionnel($count);
        $this->em->persist($company); $this->em->persist($convention); $this->em->flush();
    }

    private function client(): KernelBrowser
    {
        $client = new KernelBrowser(self::$kernel); $client->disableReboot(); $client->loginUser($this->user); return $client;
    }

    private function url(string $route, ?Entite $entite = null): string
    {
        return self::getContainer()->get('router')->generate($route, ['entite' => ($entite ?? $this->entite)->getId(), 'contrat' => $this->contract->getId()]);
    }
}
