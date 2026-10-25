<?php
namespace App\Tests\Twig;

use App\Entity\{ConventionContrat, Entreprise, Inscription};
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
        self::assertStringContainsString('Partiellement signée', $render());
        $convention->setDateSignatureEntreprise(new \DateTimeImmutable('2026-10-25 11:00:00'));
        self::assertStringContainsString('Signée par les deux parties', $render());
        $convention->addInscription(new Inscription());
        $convention->addInscription(new Inscription());
        $html = $twig->render('administrateur/convention/_listing_details.html.twig', ['c'=>$convention]);
        self::assertStringContainsString('2 stagiaires rattachés', $html);
    }
}
