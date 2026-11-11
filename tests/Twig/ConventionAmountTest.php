<?php
namespace App\Tests\Twig;

use App\Entity\{ConventionContrat, Devis, Formation, Session};
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ConventionAmountTest extends TestCase
{
    public function testAmountPriorityIncludingExplicitZero(): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2).'/templates'));
        $session = (new Session())->setFormation((new Formation())->setPrixBaseCents(75000))->setMontantCents(10000)->setTauxTva(20);
        $quote = (new Devis())->setMontantHtCents(20000)->setMontantTvaCents(4000)->setMontantTtcCents(24000)->setDevise('EUR');
        $c = (new ConventionContrat())->setSession($session)->setEffectifPrevisionnel(1)->setDevis($quote)->setMontantHtCents(7500)->setTauxTva(20);
        $render = fn() => $twig->render('pdf/_convention_amount.html.twig', ['convention'=>$c, 'devis'=>$c->getDevis(), 'session'=>$session]);
        self::assertStringContainsString('HT : 75,00 EUR', $render());
        $c->setMontantHtCents(0);
        self::assertStringContainsString('HT : 0,00 EUR', $render());
        $c->setMontantHtCents(null);
        self::assertStringContainsString('HT : 200,00 EUR', $render());
        $c->setDevis(null);
        self::assertStringContainsString('HT : 100,00 EUR', $render());
        self::assertStringContainsString('TTC : 120,00 EUR', $render());
        self::assertStringNotContainsString('750,00', $render());
    }
}
