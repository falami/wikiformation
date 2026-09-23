<?php

namespace App\Tests\Integration;

use App\Entity\{Attestation, ContratFormateur, ConventionContrat, Entite, Entreprise, Formateur, Formation, Inscription, Session, SessionJour, Site, Utilisateur};
use App\Service\Bpf\BpfCalculator;
use App\Service\Pdf\ContratFormateurDocument;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PedagogicalDurationDocumentsTest extends KernelTestCase
{
    private array $originalDatabase;

    protected function setUp(): void
    {
        $this->originalDatabase = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        self::getContainer()->set(\Symfony\Component\Mailer\MailerInterface::class, $this->createMock(\Symfony\Component\Mailer\MailerInterface::class));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $type) {
            if ($this->originalDatabase[$index] === null) unset($GLOBALS['_' . $type]['DATABASE_URL']);
            else $GLOBALS['_' . $type]['DATABASE_URL'] = $this->originalDatabase[$index];
        }
    }

    public function testThreeFullDaysUseTwentyOneHoursAcrossDocumentsAndPreserveManualDuration(): void
    {
        [$session, $user, $entite] = $this->fixture();
        $twig = self::getContainer()->get('twig');
        $convention = (new ConventionContrat())->setSession($session)->setEntite($entite)->setNumero('CONV-HOURS')
            ->setEntreprise((new Entreprise())->setRaisonSociale('Entreprise test'))->setEffectifPrevisionnel(5);
        $html = $twig->render('pdf/convention_contrat.html.twig', ['convention' => $convention, 'entite' => $entite]);
        self::assertStringContainsString('21 heures', $html);
        self::assertSame(3, substr_count($html, 'Pause : 90 min'));
        self::assertStringNotContainsString('25,50', $html);
        $html = $twig->render('pdf/convocation.html.twig', ['session' => $session, 'entite' => $entite, 'stagiaire' => $user]);
        self::assertStringContainsString('21,00 heures de formation, hors pauses', $html);
        $inscription = (new Inscription())->setSession($session)->setStagiaire($user);
        $data = ['inscription' => $inscription, 'session' => $session, 'entite' => $entite, 'stagiaire' => $user];
        $html = $twig->render('pdf/attestation.html.twig', $data);
        self::assertStringContainsString('21,00 h', $html);
        $attestation = (new Attestation())->setNumero('ATT-HOURS')->setDureeHeures(3.5)->setDateDelivrance(new \DateTimeImmutable())->setReussi(true);
        $html = $twig->render('pdf/attestation.html.twig', $data + ['attestation' => $attestation]);
        self::assertStringContainsString('3,50 h', $html);
        self::assertStringNotContainsString('21,00 h', $html);
        $convention->setDureeFormation('Durée adaptée : 18 heures');
        self::assertSame('Durée adaptée : 18 heures', $convention->getDureeFormationEffective());
    }

    public function testBpfUsesNetHoursAndKeepsOnlyTheReportingYear(): void
    {
        [$session, $user, $entite, $formation, $site] = $this->fixture();
        $em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        foreach ([$user, $entite, $formation, $site, $session] as $entity) $em->persist($entity);
        $inscription = (new Inscription())->setSession($session)->setStagiaire($user)->setCreateur($user)->setEntite($entite);
        $em->persist($inscription);
        // Un même créneau historique peut franchir le changement d’année.
        $session->addJour($this->slot('2025-12-31 08:30', '2026-01-01 17:00'));
        foreach ($session->getJours() as $jour) $jour->setCreateur($user)->setEntite($entite);
        $em->flush();
        $bpf = self::getContainer()->get(BpfCalculator::class)->computeForYear($entite, 2026);
        self::assertSame(1, $bpf['nbSessions']);
        self::assertSame(28.0, $bpf['heuresFormation']);
        self::assertSame(28.0, $bpf['heuresStagiaires']);
        self::assertSame(28.0, $bpf['formations'][0]['heures']);
        self::assertSame(1, $bpf['formations'][0]['sessions']);
    }

    public function testDeliberatelyLongerDaysKeepNinetyMinuteLunchAcrossDocuments(): void
    {
        [$session, $user, $entite] = $this->fixture();
        foreach ($session->getJours() as $day) {
            $day->setDateDebut($day->getDateDebut()->setTime(8, 0));
            $day->setDateFin($day->getDateFin()->setTime(17, 30));
        }
        $twig = self::getContainer()->get('twig');
        $convention = (new ConventionContrat())->setSession($session)->setEntite($entite)->setNumero('CONV-LONG')
            ->setEntreprise((new Entreprise())->setRaisonSociale('Entreprise test'))->setEffectifPrevisionnel(5);
        $html = $twig->render('pdf/convention_contrat.html.twig', ['convention' => $convention, 'entite' => $entite]);
        self::assertStringContainsString('24 heures', $html);
        self::assertSame(3, substr_count($html, 'Pause : 90 min'));
        self::assertStringNotContainsString('Pause : 150 min', $html);
        $html = $twig->render('pdf/convocation.html.twig', ['session' => $session, 'entite' => $entite, 'stagiaire' => $user]);
        self::assertStringContainsString('24,00 heures de formation, hors pauses', $html);
        $inscription = (new Inscription())->setSession($session)->setStagiaire($user);
        $html = $twig->render('pdf/attestation.html.twig', ['inscription' => $inscription, 'session' => $session, 'entite' => $entite, 'stagiaire' => $user]);
        self::assertStringContainsString('24,00 h', $html);
        $trainer = (new Formateur())->setUtilisateur($user)->setEntite($entite);
        $session->setFormateur($trainer);
        $contract = (new ContratFormateur())->setNumero('CF-LONG')->setSession($session)->setFormateur($trainer)->setEntite($entite);
        $data = self::getContainer()->get(ContratFormateurDocument::class)->templateData($contract);
        $html = $twig->render('pdf/contrat_formateur.html.twig', $data);
        self::assertStringContainsString('Durée de l’intervention hors pauses : 24,00 h', $html);
    }

    /** @return array{Session, Utilisateur, Entite, Formation, Site} */
    private function fixture(): array
    {
        $user = (new Utilisateur())->setEmail('duration@example.test')->setPassword('unused')->setPrenom('Camille')->setNom('Test');
        $entite = (new Entite())->setNom('Organisme durée')->setPublic(false)->setCreateur($user);
        $formation = (new Formation())->setEntite($entite)->setCreateur($user)->setTitre('Formation test')->setDuree(99)->setSlug('formation-duree');
        $site = (new Site())->setEntite($entite)->setCreateur($user)->setNom('Salle durée')->setSlug('salle-duree');
        $session = (new Session())->setEntite($entite)->setCreateur($user)->setFormation($formation)->setSite($site)->setCode('SES-HOURS');
        foreach (['07', '08', '09'] as $day) $session->addJour($this->slot("2026-10-$day 08:30", "2026-10-$day 17:00"));
        return [$session, $user, $entite, $formation, $site];
    }

    private function slot(string $start, string $end): SessionJour
    {
        return (new SessionJour())->setDateDebut(new \DateTimeImmutable($start))->setDateFin(new \DateTimeImmutable($end));
    }
}
