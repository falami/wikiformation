<?php
namespace App\Tests\Unit;
use App\Entity\{Session, SessionJour, Formateur, Utilisateur};
use App\Service\FormateurSatisfaction\FormateurSatisfactionAssigner;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class FormateurSatisfactionReplacementTest extends TestCase
{
    public function testFullyReplacedPrincipalDoesNotReceiveNewAssignment(): void
    {
        [$session, $principal, $replacement] = $this->fixture();
        $session->addJour((new SessionJour())->setFormateur($replacement));
        self::assertSame([$replacement->getUtilisateur()], $this->resolve($session));
    }
    public function testPartialReplacementKeepsBothEffectiveTrainersWithoutDuplicates(): void
    {
        [$session, $principal, $replacement] = $this->fixture();
        $session->addJour(new SessionJour());
        $session->addJour((new SessionJour())->setFormateur($replacement));
        $session->addJour((new SessionJour())->setFormateur($replacement));
        self::assertSame([$principal->getUtilisateur(), $replacement->getUtilisateur()], $this->resolve($session));
    }
    public function testSessionWithoutSlotsKeepsPrincipal(): void
    {
        [$session, $principal] = $this->fixture();
        self::assertSame([$principal->getUtilisateur()], $this->resolve($session));
    }
    private function fixture(): array
    {
        $users = [new Utilisateur(), new Utilisateur()];
        foreach ($users as $i => $u) (new \ReflectionProperty(Utilisateur::class, 'id'))->setValue($u, $i + 1);
        $principal = (new Formateur())->setUtilisateur($users[0]);
        $replacement = (new Formateur())->setUtilisateur($users[1]);
        return [(new Session())->setFormateur($principal), $principal, $replacement];
    }
    private function resolve(Session $session): array
    {
        $service = new FormateurSatisfactionAssigner($this->createMock(EntityManagerInterface::class));
        return (new \ReflectionMethod($service, 'resolveFormateurs'))->invoke($service, $session);
    }
}
