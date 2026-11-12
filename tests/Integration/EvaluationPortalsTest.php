<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Formateur\EvaluationController as TrainerController;
use App\Controller\Stagiaire\EvaluationController as LearnerController;
use App\Entity\{Entite, EvaluationDossier, EvaluationTemplate, Formation, Inscription, Session, Utilisateur};
use App\Service\Email\MailerManager;
use App\Service\Evaluation\{EvaluationWorkflow, EvaluationResultNotifier};
use Doctrine\ORM\{EntityManagerInterface, OptimisticLockException};
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\{Request, RequestStack};
use Symfony\Component\HttpFoundation\Session\Session as HttpSession;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class EvaluationPortalsTest extends KernelTestCase
{
    private function id(object $object, int $id): object
    {
        (new \ReflectionProperty($object, 'id'))->setValue($object, $id);
        return $object;
    }

    private function dossier(): EvaluationDossier
    {
        $tenant = $this->id((new Entite())->setNom('Académie'), 1);
        $learner = $this->id((new Utilisateur())->setPrenom('Camille')->setNom('Test'), 2);
        $trainer = $this->id((new Utilisateur())->setPrenom('Alex')->setNom('Évaluateur'), 3);
        $formation = (new Formation())->setTitre('Formation pratique');
        $session = $this->id((new Session())->setEntite($tenant)->setCode('SES-TEST')->setFormation($formation), 4);
        $inscription = $this->id((new Inscription())->setEntite($tenant)->setSession($session)->setStagiaire($learner), 5);
        $options = [['id' => 'a', 'label' => 'Alpha'], ['id' => 'b', 'label' => 'Bravo']];
        $schema = ['theory' => [
            ['id' => 'q1', 'label' => 'Un choix', 'type' => 'single', 'points' => 2, 'options' => $options, 'correct' => ['a']],
            ['id' => 'q2', 'label' => 'Deux choix', 'type' => 'multiple', 'points' => 2, 'options' => $options, 'correct' => ['a', 'b']],
            ['id' => 'q3', 'label' => 'Remettre dans l’ordre', 'type' => 'order', 'points' => 2, 'options' => $options, 'correct' => ['b', 'a']],
            ['id' => 'q4', 'label' => 'Réponse libre', 'type' => 'text', 'points' => 4],
        ], 'practice' => [
            ['id' => 'p1', 'label' => 'Geste essentiel', 'points' => 4, 'critical' => true, 'allowNa' => false],
            ['id' => 'p2', 'label' => 'Geste facultatif', 'points' => 2, 'critical' => false, 'allowNa' => true],
        ], 'theoryThreshold' => 10, 'practiceThreshold' => 10];
        $template = $this->id((new EvaluationTemplate())->setEntite($tenant)->setTitle('Théorie & pratique')->setSchema($schema), 6);
        return $this->id((new EvaluationDossier())->setEntite($tenant)->setTemplate($template)->setInscription($inscription)->setEvaluator($trainer)->setSnapshot((new EvaluationWorkflow())->snapshotTemplate($template)), 7);
    }

    private function view(EvaluationDossier $dossier): array
    {
        return (new \ReflectionMethod(LearnerController::class, 'learnerView'))->invoke(new LearnerController(), $dossier, true);
    }

    private function submit(EvaluationDossier $dossier): void
    {
        (new EvaluationWorkflow())->submitTheory($dossier, ['q1' => ['a'], 'q2' => ['a', 'b'], 'q3' => ['b', 'a'], 'q4' => '<script>untrusted</script>']);
    }

    private function controllerContainer(Utilisateur $user, array &$context): Container
    {
        $container = new Container();
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        $container->set('security.token_storage', $tokens);
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(true);
        $container->set('security.csrf.token_manager', $csrf);
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(static function(string $template, array $parameters) use (&$context): string { $context = $parameters; return 'rendered'; });
        $container->set('twig', $twig);
        return $container;
    }

    private function notifier(EntityManagerInterface $em): EvaluationResultNotifier
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');
        $twig = $this->createMock(Environment::class);
        $urls = $this->createMock(UrlGeneratorInterface::class);
        return new EvaluationResultNotifier($em, new MailerManager($mailer, $twig, $urls), $twig, $urls, new LockFactory(new InMemoryStore()), new NullLogger());
    }

    public function testPrivateAnswersAndDraftMarksNeverEnterTheLearnerView(): void
    {
        $d = $this->dossier();
        $this->submit($d);
        $workflow = new EvaluationWorkflow();
        $workflow->saveReview($d, ['q1' => ['points' => 0, 'comment' => 'PRIVATE FEEDBACK']], [], 'defavorable', null);
        $view = $this->view($d);
        self::assertArrayNotHasKey('theoryGrading', $view);
        self::assertArrayNotHasKey('practiceGrading', $view);
        self::assertArrayNotHasKey('theoryVerdict', $view);
        self::assertArrayNotHasKey('snapshot', $view);
        foreach ($view['questions'] as $question) self::assertArrayNotHasKey('correct', $question);
        self::assertStringNotContainsString('PRIVATE FEEDBACK', json_encode($view));
    }

    public function testStaleRepeatedAndTamperedLearnerPostsNeverFlush(): void
    {
        foreach (['stale', 'repeated', 'unknown', 'duplicate-order', 'unconfirmed'] as $case) {
            $d = $this->dossier();
            if ($case === 'repeated') $this->submit($d);
            $payload = ['_token' => 'valid', 'version' => $case === 'stale' ? '0' : '1', 'submission_confirmed' => $case === 'unconfirmed' ? '0' : '1', 'answers' => ['q1' => ['a'], 'q2' => ['a'], 'q3' => ['b', 'a'], 'q4' => 'Texte']];
            if ($case === 'unknown') $payload['answers']['q1'] = ['unknown'];
            if ($case === 'duplicate-order') $payload['answers']['q3'] = ['a', 'a'];
            $em = $this->createMock(EntityManagerInterface::class);
            $em->expects(self::never())->method('flush');
            $context = [];
            $controller = new LearnerController();
            $controller->setContainer($this->controllerContainer($d->getStagiaire(), $context));
            $response = $controller->show($d->getEntite(), $d, new Request([], $payload, [], [], [], ['REQUEST_METHOD' => 'POST']), $em, new EvaluationWorkflow());
            self::assertSame($case === 'stale' ? 409 : 422, $response->getStatusCode(), $case);
            self::assertNotEmpty($context['error']);
            self::assertNull($context['summary']);
            self::assertSame($case === 'repeated' ? 'to_review' : 'assigned', $context['dossier']['state']);
        }
    }

    public function testFailedPublicationAndConcurrencyDoNotRenderUncommittedSuccess(): void
    {
        foreach (['unconfirmed', 'incomplete', 'conflict'] as $case) {
            $d = $this->dossier(); $this->submit($d);
            $payload = ['_token' => 'valid', 'version' => '1', 'action' => 'publish', 'validation_confirmed' => $case === 'unconfirmed' ? '0' : '1',
                'theory' => ['q1' => ['points' => '2'], 'q2' => ['points' => '2'], 'q3' => ['points' => '2'], 'q4' => ['points' => '4']],
                'practice' => ['p1' => ['points' => $case === 'incomplete' ? '' : '4'], 'p2' => ['na' => '1']], 'theory_verdict' => 'favorable', 'practice_verdict' => 'favorable'];
            $em = $this->createMock(EntityManagerInterface::class);
            if ($case === 'conflict') $em->expects(self::once())->method('flush')->willThrowException(OptimisticLockException::lockFailed($d));
            else $em->expects(self::never())->method('flush');
            $context = []; $controller = new TrainerController();
            $controller->setContainer($this->controllerContainer($d->getEvaluator(), $context));
            $response = $controller->show($d->getEntite(), $d, new Request([], $payload, [], [], [], ['REQUEST_METHOD' => 'POST']), $em, new EvaluationWorkflow(), $this->notifier($em));
            self::assertSame($case === 'conflict' ? 409 : 422, $response->getStatusCode());
            self::assertNull($context['dossier']->getPublishedAt());
            self::assertFalse($context['dossier']->isSuccessful());
            self::assertNotEmpty($context['error']);
        }
    }

    public function testAutomaticDraftSaveReturnsJsonAndNeverPublishes(): void
    {
        foreach (['partial', 'invalid', 'stale', 'race'] as $case) {
            $d = $this->dossier();
            $payload = ['_token' => 'valid', 'version' => $case === 'stale' ? '0' : '1', 'action' => 'save',
                'practice' => ['p1' => ['points' => $case === 'invalid' ? '99' : '2', 'comment' => 'Observation en cours'], 'p2' => ['na' => '1']]];
            $em = $this->createMock(EntityManagerInterface::class);
            if ($case === 'race') $em->expects(self::once())->method('flush')->willThrowException(OptimisticLockException::lockFailed($d));
            elseif ($case === 'partial') $em->expects(self::once())->method('flush');
            else $em->expects(self::never())->method('flush');
            $context = [];
            $controller = new TrainerController();
            $controller->setContainer($this->controllerContainer($d->getEvaluator(), $context));
            $request = new Request([], $payload, [], [], [], ['REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
            $response = $controller->show($d->getEntite(), $d, $request, $em, new EvaluationWorkflow(), $this->notifier($em));
            self::assertSame(match ($case) { 'partial' => 200, 'invalid' => 422, default => 409 }, $response->getStatusCode());
            $json = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertNull($d->getPublishedAt());
            self::assertSame([], $context);
            if ($case === 'partial') {
                self::assertSame(1, $json['version']);
                self::assertSame(2.0, $d->getPracticeGrading()['p1']['points']);
                self::assertSame('Observation en cours', $d->getPracticeGrading()['p1']['comment']);
                self::assertTrue($d->getPracticeGrading()['p2']['na']);
            } else self::assertNotEmpty($json['error']);
        }
    }

    public function testAllQuestionTypesAndPublishedResultsRenderEscaped(): void
    {
        self::bootKernel();
        $request = Request::create('/fr/stagiaire/1/evaluations/7');
        $request->setSession(new HttpSession(new MockArraySessionStorage()));
        self::getContainer()->get(RequestStack::class)->push($request);
        $twig = self::getContainer()->get('twig');
        $workflow = new EvaluationWorkflow(); $d = $this->dossier();
        $context = ['entite' => $d->getEntite(), 'dossier' => $this->view($d), 'answers' => [], 'summary' => null, 'error' => null, 'conflict' => false];
        $html = $twig->load('evaluation/stagiaire/show.html.twig')->renderBlock('body', $context);
        self::assertStringContainsString('type="radio"', $html);
        self::assertStringContainsString('type="checkbox"', $html);
        self::assertStringContainsString('data-order-question', $html);
        self::assertStringContainsString('name="answers[q4]"', $html);
        self::assertStringNotContainsString('Corrigé', $html);
        $this->submit($d);
        $workflow->saveReview($d, ['q1' => ['points' => 0], 'q2' => ['points' => 2], 'q3' => ['points' => 2], 'q4' => ['points' => 4, 'comment' => '<b>Observation</b>']], ['p1' => ['points' => 4], 'p2' => ['na' => true]], 'favorable', 'favorable');
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($d->getEvaluator(), 'main', ['ROLE_USER']));
        $trainerContext = ['entite' => $d->getEntite(), 'dossier' => $d, 'theory' => $d->getTheoryGrading(), 'practice' => $d->getPracticeGrading(), 'theoryVerdict' => $d->getTheoryVerdict(), 'practiceVerdict' => $d->getPracticeVerdict(), 'suggestions' => $workflow->suggestTheoryGrading($d), 'summary' => $workflow->scoreSummary($d), 'error' => null, 'conflict' => false];
        $html = $twig->load('evaluation/formateur/show.html.twig')->renderBlock('body', $trainerContext);
        $crawler = new \Symfony\Component\DomCrawler\Crawler($html);
        foreach (['theory', 'practice'] as $part) {
            $options = $crawler->filter('#'.$part.'-verdict option');
            self::assertSame(['', 'favorable', 'defavorable'], $options->extract(['value']));
            self::assertSame('Favorable', $crawler->filter('#'.$part.'-verdict option[value=favorable]')->text());
        }
        self::assertCount(6, $crawler->filter('input[type=number][step="0.5"][min="0"][max]'));
        self::assertStringContainsString('value="0"', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>untrusted', $html);
        $workflow->publish($d);
        $html = $twig->load('evaluation/stagiaire/show.html.twig')->renderBlock('body', array_replace($context, ['dossier' => $this->view($d), 'answers' => $d->getTheoryAnswers(), 'summary' => $workflow->scoreSummary($d)]));
        self::assertStringContainsString('0 / 2', $html);
        self::assertStringContainsString('Non applicable', $html);
        self::assertStringContainsString('16,00', $html);
        self::assertStringContainsString('&lt;b&gt;Observation&lt;/b&gt;', $html);
        self::assertStringContainsString('Mon attestation de réussite', $html);
        self::assertStringContainsString('Télécharger mon bilan complet', $html);
        $trainerContext['summary'] = $workflow->scoreSummary($d);
        $html = $twig->load('evaluation/formateur/show.html.twig')->renderBlock('body', $trainerContext);
        self::assertStringContainsString('Réessayer l’envoi des résultats', $html);
        self::assertStringNotContainsString('Valider et publier', $html);
    }
}
