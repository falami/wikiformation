<?php
namespace App\Tests\Twig;

use App\Entity\{ConventionContrat, Entreprise, Inscription, Utilisateur};
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class ConventionListingTest extends KernelTestCase
{
    public function testSignatureStatesAndAttachedParticipantCount(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get(Environment::class);
        $convention = (new ConventionContrat())->setEntreprise(new Entreprise());
        $render = fn() => $twig->render('administrateur/convention/_signature_status.html.twig', ['c'=>$convention]);
        self::assertStringContainsString('Non signée', $render());
        $convention->setDateSignatureOf(new \DateTimeImmutable('2026-10-25 10:00:00'));
        self::assertStringContainsString('Non signée', $render());
        $convention->setDateSignatureEntreprise(new \DateTimeImmutable('2026-10-25 11:00:00'));
        self::assertStringContainsString('>Signée</span>', $render());
        $convention->setDateSignatureOf(null);
        $signed = $render();
        self::assertStringContainsString('>Signée</span>', $signed);
        self::assertStringContainsString('Entreprise : signé', $signed);
        self::assertStringContainsString('25/10/2026', $signed);
        self::assertStringNotContainsString('Partiellement signée', $signed);
        self::assertStringNotContainsString('en attente', $signed);
        self::assertStringNotContainsString('Organisme', $signed);
        $convention->addInscription(new Inscription());
        $convention->addInscription(new Inscription());
        $html = $twig->render('administrateur/convention/_listing_details.html.twig', ['c'=>$convention]);
        self::assertStringContainsString('2 stagiaires rattachés', $html);
    }

    public function testIndividualRecipientAndLegacySignatureImageAlsoCompleteTheStatus(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get(Environment::class);
        $convention = (new ConventionContrat())->setStagiaire(new Utilisateur());
        $render = fn() => $twig->render('administrateur/convention/_signature_status.html.twig', ['c'=>$convention]);
        $convention->setDateSignatureEntreprise(new \DateTimeImmutable());
        self::assertStringContainsString('Non signée', $render());
        $convention->setSignatureDataUrlStagiaire('data:image/png;base64,signature');
        $signed = $render();
        self::assertStringContainsString('>Signée</span>', $signed);
        self::assertStringContainsString('Stagiaire : signé', $signed);
        self::assertStringNotContainsString('en attente', $signed);
        self::assertStringNotContainsString('Organisme', $signed);
    }
}
