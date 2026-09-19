<?php

namespace App\Tests\Integration;

use App\Entity\{ConventionContrat, Entite, Entreprise, Formation, Inscription, Session, Utilisateur};
use Dompdf\Dompdf;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Rendu du document avec des objets non persistés : aucune donnée métier n’est modifiée. */
final class ConventionFlexiblePdfTest extends KernelTestCase
{
    public function testCustomizedSignedCompanyDocumentIncludesFreeNamesAndUnknownParticipants(): void
    {
        [$convention, $formation] = $this->fixture();
        $convention
            ->setIntituleFormation('H0B0 - indices adaptés au client')
            ->setDureeFormation('7 heures sur 2 demi-journées')
            ->setParticipantsLibres("  Camille Sans Courriel\n\nAlex <Martin>  ")
            ->setEffectifPrevisionnel(5)
            ->setDateSignatureEntreprise(new \DateTimeImmutable('2026-09-19'));
        $registered = (new Utilisateur())->setPrenom('Léa')->setNom('Inscrite')->setEmail('lea@example.test');
        $convention->addInscription((new Inscription())->setStagiaire($registered));

        $html = $this->render($convention);
        self::assertStringContainsString('H0B0 - indices adaptés au client', $html);
        self::assertStringContainsString('7 heures sur 2 demi-journées', $html);
        self::assertStringContainsString('Camille Sans Courriel', $html);
        self::assertStringContainsString('Alex &lt;Martin&gt;', $html);
        self::assertStringContainsString('lea@example.test', $html);
        self::assertStringContainsString('Effectif prévu : 5 stagiaires.', $html);
        self::assertStringContainsString('Liste nominative à compléter : 2 stagiaires à désigner.', $html);
        self::assertStringContainsString('PROGRAMME-CATALOGUE-INCHANGE', $html);
        self::assertStringNotContainsString('H0B0 - 1 jour', $html);
        self::assertSame('H0B0 - 1 jour', $formation->getTitre());
        self::assertSame(1, $formation->getDuree());
        self::assertTrue($convention->isSigned());

        $pdf = new Dompdf(['isRemoteEnabled' => false, 'fontCache' => sys_get_temp_dir()]);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        self::assertStringStartsWith('%PDF-', $pdf->output());
    }

    public function testCompanyDocumentCanContainOnlyAnExpectedHeadcount(): void
    {
        [$convention] = $this->fixture();
        $convention->setEffectifPrevisionnel(8);
        $html = $this->render($convention);

        self::assertStringContainsString('Effectif prévu : 8 stagiaires.', $html);
        self::assertStringContainsString('Liste nominative à compléter : 8 stagiaires à désigner.', $html);
        self::assertCount(0, $convention->getInscriptions());
        self::assertSame([], $convention->getParticipantsLibresListe());
    }

    public function testLegacyIndividualDocumentUsesCatalogueTitleAndDuration(): void
    {
        [$convention] = $this->fixture();
        $stagiaire = (new Utilisateur())->setPrenom('Camille')->setNom('Individuel')->setEmail('individual@example.test');
        $convention->setEntreprise(null)->setStagiaire($stagiaire);
        $convention->addInscription((new Inscription())->setStagiaire($stagiaire));

        $html = $this->render($convention);
        self::assertStringContainsString('H0B0 - 1 jour', $html);
        self::assertStringContainsString(' - 1 jour', $html);
        self::assertStringContainsString('Effectif prévu : 1 stagiaire.', $html);
        self::assertStringContainsString('individual@example.test', $html);
        self::assertStringNotContainsString('Liste nominative à compléter', $html);
    }

    /** @return array{ConventionContrat, Formation} */
    private function fixture(): array
    {
        $entite = (new Entite())->setNom('Organisme de formation test');
        $formation = (new Formation())->setTitre('H0B0 - 1 jour')->setDuree(1)->setObjectifs('PROGRAMME-CATALOGUE-INCHANGE');
        $session = (new Session())->setEntite($entite)->setFormation($formation)->setCode('SES-TEST');
        $convention = (new ConventionContrat())->setEntite($entite)->setSession($session)
            ->setEntreprise((new Entreprise())->setRaisonSociale('Entreprise test'))
            ->setNumero('CONV-TEST');

        return [$convention, $formation];
    }

    private function render(ConventionContrat $convention): string
    {
        self::bootKernel();
        return self::getContainer()->get('twig')->render('pdf/convention_contrat.html.twig', [
            'entite' => $convention->getEntite(), 'convention' => $convention,
        ]);
    }
}
