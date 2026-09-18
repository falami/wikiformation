<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\{ConventionContrat, Devis, Entite, Entreprise, Formation, Inscription, Session, Utilisateur, UtilisateurEntite};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class ConventionContratTest extends TestCase
{
    public function testSeveralConventionsCanCoverDifferentTraineesForOneCompanyAndSession(): void
    {
        $entite = new Entite();
        $formation = new Formation();
        $session = (new Session())->setEntite($entite)->setFormation($formation);
        $entreprise = (new Entreprise())->setEntite($entite);
        $devis = (new Devis())->setEntite($entite)->setFormation($formation)->setEntrepriseDestinataire($entreprise);
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        foreach ([1, 2] as $number) {
            $convention = (new ConventionContrat())->setNumero('CONV-' . $number)
                ->setEntite($entite)->setSession($session)->setEntreprise($entreprise)->setDevis($devis);
            $convention->addInscription((new Inscription())->setEntite($entite)->setSession($session)
                ->setEntreprise($entreprise)->setStagiaire(new Utilisateur()));

            self::assertCount(0, $validator->validate($convention));
        }

        self::assertCount(2, $session->getConventionContrats());
        self::assertCount(2, $devis->getConventions());
    }

    public function testMovingConventionBetweenDevisUpdatesBothSides(): void
    {
        $original = new Devis();
        $destination = new Devis();
        $convention = (new ConventionContrat())->setDevis($original);

        $destination->addConvention($convention);
        self::assertCount(0, $original->getConventions());
        self::assertSame($destination, $convention->getDevis());
        self::assertTrue($destination->getConventions()->contains($convention));

        $destination->removeConvention($convention);
        self::assertNull($convention->getDevis());
        self::assertCount(0, $destination->getConventions());
    }

    public function testMovingConventionBetweenSessionsUpdatesBothSides(): void
    {
        $original = new Session();
        $destination = new Session();
        $convention = (new ConventionContrat())->setSession($original)->setSession($destination);

        self::assertCount(0, $original->getConventionContrats());
        self::assertTrue($destination->getConventionContrats()->contains($convention));
        $destination->removeConventionContrat($convention);
        self::assertNull($convention->getSession());
    }

    public function testInscriptionLinksStayConsistentFromEitherSide(): void
    {
        $inscription = new Inscription();
        $convention = new ConventionContrat();
        $convention->addInscription($inscription)->addInscription($inscription);
        self::assertCount(1, $inscription->getConventionContrats());
        self::assertCount(1, $convention->getInscriptions());

        $inscription->removeConventionContrat($convention);
        self::assertCount(0, $convention->getInscriptions());
        self::assertCount(0, $inscription->getConventionContrats());

        $inscription->addConventionContrat($convention);
        $convention->removeInscription($inscription);
        self::assertCount(0, $inscription->getConventionContrats());
    }

    public function testDevisInscriptionLinksStayConsistentFromEitherSide(): void
    {
        $inscription = new Inscription();
        $devis = new Devis();
        $devis->addInscription($inscription);
        self::assertTrue($inscription->getDevis()->contains($devis));

        $inscription->removeDevi($devis);
        self::assertCount(0, $devis->getInscriptions());
        $inscription->addDevi($devis);
        $devis->removeInscription($inscription);
        self::assertCount(0, $inscription->getDevis());
    }

    public function testForeignSessionInscriptionIsRejected(): void
    {
        $entite = new Entite();
        $stagiaire = new Utilisateur();
        $session = (new Session())->setEntite($entite);
        $convention = (new ConventionContrat())->setEntite($entite)->setSession($session)->setStagiaire($stagiaire);
        $convention->addInscription((new Inscription())->setEntite($entite)->setSession(new Session())->setStagiaire($stagiaire));

        $violations = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()->validate($convention);
        self::assertCount(1, $violations);
        self::assertSame('inscriptions', $violations[0]->getPropertyPath());
    }

    public function testIndividualConventionRejectsAnotherTrainee(): void
    {
        $entite = new Entite();
        $session = (new Session())->setEntite($entite);
        $convention = (new ConventionContrat())->setEntite($entite)->setSession($session)->setStagiaire(new Utilisateur());
        $convention->addInscription((new Inscription())->setEntite($entite)->setSession($session)->setStagiaire(new Utilisateur()));

        $violations = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()->validate($convention);
        self::assertCount(1, $violations);
        self::assertSame('inscriptions', $violations[0]->getPropertyPath());
    }

    public function testDevisCannotBeIssuedToBothPersonAndCompany(): void
    {
        $entite = new Entite();
        $person = new Utilisateur();
        $person->addUtilisateurEntite((new UtilisateurEntite())->setEntite($entite));
        $devis = (new Devis())->setEntite($entite)->setDestinataire($person)
            ->setEntrepriseDestinataire((new Entreprise())->setEntite($entite));

        $violations = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()->validate($devis);
        self::assertCount(1, $violations);
        self::assertSame('entrepriseDestinataire', $violations[0]->getPropertyPath());
    }

    public function testLegacySignatureImageAlsoLocksConvention(): void
    {
        $convention = new ConventionContrat();
        self::assertFalse($convention->isSigned());
        $convention->setSignatureDataUrlEntreprise('data:image/png;base64,signature');
        self::assertTrue($convention->isSigned());
    }
}
