<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{Attestation, ConventionContrat, Entite, Entreprise, Formation, Inscription, Session, SessionJour, Utilisateur};
use App\Enum\StatusInscription;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RegulatoryDocumentsTest extends KernelTestCase
{
    private array $originalDatabase;

    protected function setUp(): void
    {
        $this->originalDatabase = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $index => $type) {
            if ($this->originalDatabase[$index] === null) unset($GLOBALS['_' . $type]['DATABASE_URL']);
            else $GLOBALS['_' . $type]['DATABASE_URL'] = $this->originalDatabase[$index];
        }
    }

    public function testCertificateUsesProofAndPeriodAndDoesNotClaimAQualification(): void
    {
        self::bootKernel();
        [$entite, $user, $session] = $this->fixture();
        $i = (new Inscription())->setEntite($entite)->setStagiaire($user)->setSession($session)->setStatus(StatusInscription::TERMINE)->setTauxAssiduite(50)->setReussi(true);
        $i->enregistrerPresenceManuelle('2025-01-03:AM', 'paper', 'Feuille test', $user);
        $i->enregistrerPresenceManuelle('2025-01-03:PM', 'absent', '', $user);
        $a = (new Attestation())->setNumero('ATT-TEST')->setDateDelivrance(new \DateTimeImmutable('2025-01-04'))->setDureeHeures(7)->setReussi(true);
        $data = ['entite' => $entite, 'inscription' => $i, 'stagiaire' => $user, 'attestation' => $a];
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('pdf/attestation.html.twig', $data);
        self::assertStringContainsString('3,00 h', $html);
        self::assertStringContainsString('03/01/2025', $html);
        self::assertStringContainsString('Participation justifiée', $html);
        self::assertStringNotContainsString('7,00 h', $html);
        self::assertStringNotContainsString('valable sans signature', $html);
        self::assertStringNotContainsString('Document officiel', $html);
        self::assertStringNotContainsString('>Validée<', $html);
        $this->preview('certificat', $html);
        $i->setStatus(StatusInscription::CONFIRME);
        $html = $twig->render('pdf/attestation.html.twig', $data);
        self::assertStringContainsString('Projet non utilisable comme justificatif', $html);
        self::assertStringNotContainsString('atteste que', $html);
    }

    public function testIndividualContractHasProtectiveClausesAndRegisteredActivityMention(): void
    {
        self::bootKernel();
        [$entite, $user, $session] = $this->fixture();
        $convention = (new ConventionContrat())->setEntite($entite)->setSession($session)->setNumero('CONV-TEST')->setStagiaire($user)->setEffectifPrevisionnel(1);
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('pdf/convention_contrat.html.twig', ['entite' => $entite, 'convention' => $convention]);
        foreach (['dix jours', '30 %', 'force majeure', 'quatorze jours', 'préfet de région de Occitanie', 'Prérequis', 'Conditions financières à compléter', 'proportion de la durée dispensée à distance', 'Modèle de formulaire de rétractation', 'la signature du présent contrat'] as $expected) self::assertStringContainsString(mb_strtolower($expected), mb_strtolower($html));
        $this->preview('contrat-individuel', $html);
        $session->setTauxDistanciel(0);
        $convention->setEntreprise((new Entreprise())->setRaisonSociale('Client société'));
        $html = $twig->render('pdf/convention_contrat.html.twig', ['entite' => $entite, 'convention' => $convention]);
        self::assertStringNotContainsString('Le bénéficiaire dispose de dix jours', $html);
        self::assertStringNotContainsString('Modèle de formulaire de rétractation', $html);
        self::assertStringContainsString('0,00 % de la durée totale des enseignements', $html);
        self::assertStringNotContainsString('proportion de la durée dispensée à distance', $html);
    }

    public function testDistanceTeachingPercentageKeepsUnknownAndValidatesBounds(): void
    {
        self::bootKernel();
        $session = new Session();
        $validator = self::getContainer()->get('validator');
        self::assertNull($session->getTauxDistanciel());
        foreach ([null, 0.0, 50.25, 100.0] as $value) {
            $session->setTauxDistanciel($value);
            self::assertCount(0, $validator->validateProperty($session, 'tauxDistanciel'));
        }
        foreach ([-0.01, 100.01] as $value) {
            $session->setTauxDistanciel($value);
            self::assertCount(1, $validator->validateProperty($session, 'tauxDistanciel'));
        }
    }

    public function testCertificateFormCannotChooseAnotherOrganismInscription(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine')->getManager();
        (new \Doctrine\ORM\Tools\SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        [$entite, $user, $session] = $this->fixture();
        $entite->setCreateur($user);
        $user->setPassword('unused');
        $site = (new \App\Entity\Site())->setEntite($entite)->setCreateur($user)->setNom('Salle test')->setSlug('salle-test');
        $session->setCreateur($user)->setSite($site); $session->getFormation()->setCreateur($user)->setSlug('formation-test');
        foreach ($session->getJours() as $day) $day->setCreateur($user)->setEntite($entite);
        $i = (new Inscription())->setEntite($entite)->setStagiaire($user)->setSession($session)->setCreateur($user);
        $other = (new Entite())->setNom('Autre organisme')->setCreateur($user)->setPublic(false);
        $otherUser = (new Utilisateur())->setEmail('other@example.test')->setPassword('unused')->setNom('Autre')->setPrenom('Client');
        $foreign = (new Inscription())->setEntite($other)->setStagiaire($otherUser)->setSession($session)->setCreateur($user);
        foreach ([$user, $otherUser, $entite, $other, $site, $session->getFormation(), $session, $i, $foreign] as $entity) $em->persist($entity);
        $em->flush();
        $form = self::getContainer()->get('form.factory')->create(\App\Form\Administrateur\AttestationType::class, (new Attestation())->setDateDelivrance(new \DateTimeImmutable())->setReussi(false), ['entite' => $entite]);
        $choices = array_values($form->get('inscription')->createView()->vars['choices']);
        self::assertCount(1, $choices);
        self::assertSame($i->getId(), $choices[0]->data->getId());
        self::assertTrue($form->get('dureeHeures')->isDisabled());
        $form->get('inscription')->submit((string) $foreign->getId());
        self::assertFalse($form->get('inscription')->isSynchronized());
        self::assertNull($form->get('inscription')->getData());
    }

    private function fixture(): array
    {
        $user = (new Utilisateur())->setPrenom('Camille')->setNom('Test')->setEmail('camille@example.test');
        $entite = (new Entite())->setNom('Organisme de démonstration')->setPublic(false)->setNumeroDeclarant('76300000030')->setRegion('Occitanie')->setVille('Alès')->setAdresse('1 rue de Démonstration')->setCodePostal('30100')->setSiret('00000000000000');
        $formation = (new Formation())->setEntite($entite)->setTitre('Utiliser un tableur')->setObjectifs('Structurer et analyser des tableaux.')->setConditionPrealable('Utiliser un ordinateur.')->setPedagogie('Exercices guidés sur poste individuel.')->setModalitesEvaluation('Mise en situation individuelle.');
        $session = (new Session())->setEntite($entite)->setFormation($formation)->setCode('SESSION-TEST');
        foreach ([['09:00', '12:00'], ['14:00', '18:00']] as [$start, $end]) $session->addJour((new SessionJour())->setDateDebut(new \DateTimeImmutable('2025-01-03 '.$start))->setDateFin(new \DateTimeImmutable('2025-01-03 '.$end))->setPauseMinutes(0));
        return [$entite, $user, $session];
    }
    private function preview(string $name, string $html): void
    {
        if (!$dir = getenv('DOCUMENT_REVIEW_DIR')) return;
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        file_put_contents($dir.'/'.$name.'.pdf', self::getContainer()->get(\App\Service\Pdf\PdfManager::class)->createPortraitBytes($html));
    }
}
