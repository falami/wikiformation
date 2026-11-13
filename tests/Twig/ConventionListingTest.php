<?php
namespace App\Tests\Twig;

use App\Entity\{ConventionContrat, Entite, Entreprise, Inscription, Utilisateur};
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class ConventionListingTest extends KernelTestCase
{
    public function testSignatureStatesAndAttachedParticipantCount(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get(Environment::class);
        $convention = $this->convention()->setEntreprise(new Entreprise());
        $render = fn() => $twig->render('administrateur/convention/_signature_status.html.twig', ['c'=>$convention]);
        self::assertStringContainsString('Non signée', $render());
        self::assertStringContainsString('/conventions/42/pdf', $render());
        self::assertStringContainsString('Voir le document', $render());
        $convention->setDateSignatureOf(new \DateTimeImmutable('2026-10-25 10:00:00'));
        self::assertStringContainsString('Non signée', $render());
        $convention->setDateSignatureEntreprise(new \DateTimeImmutable('2026-10-25 11:00:00'));
        self::assertStringContainsString('>Signée</span>', $render());
        $convention->setDateSignatureOf(null);
        $signed = $render();
        self::assertStringContainsString('>Signée</span>', $signed);
        self::assertStringContainsString('/conventions/42/pdf', $signed);
        self::assertStringContainsString('Voir le document', $signed);
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
        $convention = $this->convention()->setStagiaire(new Utilisateur());
        $render = fn() => $twig->render('administrateur/convention/_signature_status.html.twig', ['c'=>$convention]);
        $convention->setDateSignatureEntreprise(new \DateTimeImmutable());
        self::assertStringContainsString('Non signée', $render());
        $convention->setSignatureDataUrlStagiaire('data:image/png;base64,signature');
        $signed = $render();
        self::assertStringContainsString('>Signée</span>', $signed);
        self::assertStringContainsString('/conventions/42/pdf', $signed);
        self::assertStringContainsString('Voir le document', $signed);
        self::assertStringContainsString('Stagiaire : signé', $signed);
        self::assertStringNotContainsString('en attente', $signed);
        self::assertStringNotContainsString('Organisme', $signed);
    }
    private function convention(): ConventionContrat
    {
        $entite = new Entite();
        (new \ReflectionProperty(Entite::class, 'id'))->setValue($entite, 1);
        $convention = (new ConventionContrat())->setEntite($entite);
        (new \ReflectionProperty(ConventionContrat::class, 'id'))->setValue($convention, 42);

        return $convention;
    }
}
