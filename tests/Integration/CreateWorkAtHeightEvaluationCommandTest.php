<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\CreateWorkAtHeightEvaluationCommand;
use App\Entity\{Entite, EvaluationTemplate, Utilisateur, UtilisateurEntite};
use App\Service\Evaluation\{EvaluationSchema, WorkAtHeightPreset};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateWorkAtHeightEvaluationCommandTest extends KernelTestCase
{
    private array $database;
    private EntityManagerInterface $em;
    private Entite $tenant;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $author = (new Utilisateur())->setEmail('admin@example.test')->setPassword('unused')->setPrenom('Admin')->setNom('Test')->setRoles(['ROLE_USER']);
        $this->tenant = (new Entite())->setNom('Test')->setPublic(false)->setCreateur($author);
        $this->em->persist($author);
        $this->em->persist($this->tenant);
        $this->em->persist((new UtilisateurEntite())->setUtilisateur($author)->setEntite($this->tenant)->setCreateur($author)->setRoles(['TENANT_ADMIN'])->setStatus('active'));
        $this->em->flush();
        $this->tester = new CommandTester(new CreateWorkAtHeightEvaluationCommand($this->em, new WorkAtHeightPreset(), new EvaluationSchema()));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $i => $name) {
            if ($this->database[$i] === null) unset($GLOBALS['_'.$name]['DATABASE_URL']);
            else $GLOBALS['_'.$name]['DATABASE_URL'] = $this->database[$i];
        }
    }

    public function testSimulationCreationAndRepeatedExecution(): void
    {
        self::assertSame(0, $this->runImport());
        self::assertSame(0, $this->em->getRepository(EvaluationTemplate::class)->count([]));
        self::assertSame(0, $this->runImport(['--execute' => true]));
        self::assertSame(0, $this->runImport(['--execute' => true]));
        self::assertSame(1, $this->em->getRepository(EvaluationTemplate::class)->count([]));
        $template = $this->em->getRepository(EvaluationTemplate::class)->findOneBy([]);
        self::assertTrue($template->isActive());
        self::assertSame(1, $template->getVersion());
        self::assertCount(15, $template->getSchema()['theory']);
        self::assertCount(8, $template->getSchema()['practice']);
        self::assertEquals(20, array_sum(array_column($template->getSchema()['theory'], 'points')));
        self::assertEquals(20, array_sum(array_column($template->getSchema()['practice'], 'points')));
        self::assertNull($template->getSchema()['theoryThreshold']);
        self::assertSame(0, $this->em->getRepository(\App\Entity\QcmAssignment::class)->count([]));
        self::assertSame(0, $this->em->getRepository(\App\Entity\EvaluationDossier::class)->count([]));
    }

    public function testExistingDraftIsActivatedButCustomModelIsPreserved(): void
    {
        $this->runImport(['--execute' => true]);
        $template = $this->em->getRepository(EvaluationTemplate::class)->findOneBy([]);
        $template->setActive(false);
        $this->em->flush();
        self::assertSame(0, $this->runImport(['--execute' => true]));
        self::assertTrue($template->isActive());
        self::assertSame(2, $template->getVersion());
        $schema = $template->getSchema();
        $schema['theoryThreshold'] = 12;
        $template->setSchema($schema)->setActive(false);
        $this->em->flush();
        self::assertSame(1, $this->runImport(['--execute' => true]));
        $this->em->refresh($template);
        self::assertSame(12, $template->getSchema()['theoryThreshold']);
        self::assertFalse($template->isActive());
        self::assertSame(1, $this->em->getRepository(EvaluationTemplate::class)->count([]));
    }

    public function testInvalidTenantOrForeignAuthorCannotCreateModel(): void
    {
        self::assertSame(2, $this->tester->execute(['entite' => 'abc', '--execute' => true]));
        self::assertSame(2, $this->tester->execute(['entite' => '99999', '--execute' => true]));
        $outsider = (new Utilisateur())->setEmail('outsider@example.test')->setPassword('unused')->setPrenom('Other')->setNom('Test')->setRoles(['ROLE_USER']);
        $this->em->persist($outsider); $this->em->flush();
        self::assertSame(2, $this->runImport(['--auteur' => (string) $outsider->getId(), '--execute' => true]));
        self::assertSame(0, $this->em->getRepository(EvaluationTemplate::class)->count([]));
    }

    private function runImport(array $options = []): int
    {
        return $this->tester->execute(['entite' => (string) $this->tenant->getId()] + $options);
    }
}
