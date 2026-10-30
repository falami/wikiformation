<?php
namespace App\Tests\Integration;

use App\Controller\Administrateur\QuestionnaireResponsesController;
use App\Entity\{Entite, Utilisateur, FormateurSatisfactionTemplate, PositioningQuestionnaire, Qcm};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

final class QuestionnaireResponsesTest extends KernelTestCase
{
    public function testQueriesAndTenantIsolationForAllQuestionnaireTypes(): void
    {
        $previous = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        try {
            self::bootKernel();
            $em = self::getContainer()->get(EntityManagerInterface::class);
            (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
            $user = (new Utilisateur())->setEmail('responses@test.invalid')->setPassword('unused')->setNom('Test')->setPrenom('Camille');
            $em->persist($user);
            $tenant = (new Entite())->setPublic(false)->setNom('Test')->setCreateur($user);
            $other = (new Entite())->setPublic(false)->setNom('Autre')->setCreateur($user);
            $em->persist($tenant); $em->persist($other); $em->flush();
            $models = ['formateur' => new FormateurSatisfactionTemplate(), 'positionnement' => (new PositioningQuestionnaire())->setTitle('Positionnement'), 'niveau' => (new Qcm())->setTitre('Niveau')];
            $authorization = $this->createMock(AuthorizationCheckerInterface::class);
            $authorization->expects(self::exactly(6))->method('isGranted')->willReturn(true);
            $twig = $this->createMock(Environment::class);
            $twig->expects(self::exactly(3))->method('render')->willReturnCallback(static function ($template, $context): string {
                self::assertSame(0, $context['total']);
                self::assertSame([], $context['rows']);
                self::assertSame(1, $context['page']);
                return 'ok';
            });
            $container = new Container();
            $container->set('security.authorization_checker', $authorization);
            $container->set('twig', $twig);
            $controller = new QuestionnaireResponsesController(); $controller->setContainer($container);
            foreach ($models as $kind => $model) {
                $model->setEntite($tenant)->setCreateur($user); $em->persist($model); $em->flush();
                $response = $controller->index($tenant, $kind, $model->getId(), new Request(['q' => 'Camille', 'page' => 999]), $em);
                self::assertSame(200, $response->getStatusCode());
                try {
                    $controller->index($other, $kind, $model->getId(), new Request(), $em);
                    self::fail('Cross-tenant questionnaire must be rejected.');
                } catch (NotFoundHttpException) { self::assertTrue(true); }
            }
        } finally {
            self::ensureKernelShutdown();
            foreach (['_ENV', '_SERVER'] as $i => $global) {
                if ($previous[$i] === null) unset($GLOBALS[$global]['DATABASE_URL']);
                else $GLOBALS[$global]['DATABASE_URL'] = $previous[$i];
            }
        }
    }
}
