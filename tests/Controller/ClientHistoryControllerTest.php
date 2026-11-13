<?php
namespace App\Tests\Controller;

use App\Controller\Administrateur\ClientHistoryController;
use App\Entity\{Entite, Session, ConventionContrat, Entreprise, Utilisateur, UtilisateurEntite, Inscription, Facture, Devis};
use Doctrine\ORM\{EntityManagerInterface, EntityRepository};
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class ClientHistoryControllerTest extends TestCase
{
    public static function sources(): array { return [['convention'], ['facture'], ['facture_direct'], ['devis_direct'], ['convention_direct']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('sources')]
    public function testSessionOpensCustomerInteractionTab(string $source): void
    {
        $tenant = new Entite();
        $contact = new Utilisateur();
        $session = new Session();
        foreach ([[$tenant, 1], [$contact, 116], [$session, 57]] as [$entity, $id]) {
            $property = new \ReflectionProperty($entity, 'id');
            $property->setValue($entity, $id);
        }
        $company = (new Entreprise())->setRepresentant($contact);
        (new \ReflectionProperty($company, 'id'))->setValue($company, 20);
        $session->setEntite($tenant);
        if ($source === 'facture') {
            $invoice = (new Facture())->setEntite($tenant)->setDestinataire($contact);
            $registration = (new Inscription())->setEntite($tenant)->setSession($session)->addFacture($invoice);
            $session->addInscription($registration);
        } else {
        $convention = (new ConventionContrat())->setEntite($tenant)->setEntreprise($company)->setSession($session);
        $session->addConventionContrat($convention);
        }
        $type = 'session';
        $document = $session;
        if (str_ends_with($source, '_direct')) {
            $type = substr($source, 0, -7);
            $document = match ($type) {
                'facture' => (new Facture())->setEntite($tenant)->setDestinataire($contact),
                'devis' => (new Devis())->setEntite($tenant)->setDestinataire($contact),
                'convention' => (new ConventionContrat())->setEntite($tenant)->setStagiaire($contact),
            };
        }
        $documents = $this->createMock(EntityRepository::class);
        $documents->method('find')->with(57)->willReturn($document);
        $memberships = $this->createMock(EntityRepository::class);
        $memberships->method('findOneBy')->with(['entite' => $tenant, 'utilisateur' => $contact])->willReturn(new UtilisateurEntite());
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(fn($class) => match ($class) {
            $document::class => $documents,
            UtilisateurEntite::class => $memberships,
            default => throw new \LogicException('Should redirect before loading the fallback history'),
        });
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturn(true);
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::once())->method('generate')->with('app_administrateur_utilisateur_show', ['entite'=>1, 'id'=>116, '_fragment'=>'pane-histo'])->willReturn('/fr/administrateur/1/utilisateur/116#pane-histo');
        $container = new Container();
        $container->set('security.authorization_checker', $auth);
        $container->set('router', $router);
        $controller = new ClientHistoryController();
        $controller->setContainer($container);
        $response = $controller->history($tenant, $type, 57, new Request(), $em);
        self::assertSame('/fr/administrateur/1/utilisateur/116#pane-histo', $response->headers->get('Location'));
    }
}
