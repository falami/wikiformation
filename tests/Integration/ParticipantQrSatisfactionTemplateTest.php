<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\{Entite, Formation, SatisfactionChapter, SatisfactionQuestion, SatisfactionTemplate, Session, Site, Utilisateur};
use App\Enum\{SatisfactionQuestionType, TypeFinancement};
use App\Service\Session\ParticipantQrSatisfactionTemplate;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ParticipantQrSatisfactionTemplateTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ParticipantQrSatisfactionTemplate $templates;
    private Session $session;
    private Utilisateur $admin;
    private array $originalDatabase;

    protected function setUp(): void
    {
        $this->originalDatabase = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->templates = self::getContainer()->get(ParticipantQrSatisfactionTemplate::class);
        $this->admin = (new Utilisateur())->setEmail('qr-template-admin@example.test')->setPassword('unused')->setPrenom('Alex')->setNom('Admin');
        $entite = (new Entite())->setNom('QR Test')->setCreateur($this->admin)->setPublic(false);
        $site = (new Site())->setEntite($entite)->setCreateur($this->admin)->setNom('Salle QR')->setSlug('salle-qr-template');
        $this->session = (new Session())->setSite($site)->setEntite($entite)->setCreateur($this->admin)->setCode('QR-FREE')->setTypeFinancement(TypeFinancement::OUI)->setFormationIntituleLibre('Formation sans catalogue');
        foreach ([$this->admin, $entite, $site, $this->session] as $entity) $this->em->persist($entity);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $i => $type) {
            if ($this->originalDatabase[$i] === null) unset($GLOBALS['_' . $type]['DATABASE_URL']);
            else $GLOBALS['_' . $type]['DATABASE_URL'] = $this->originalDatabase[$i];
        }
    }

    public function testFreeTitleSessionGetsReusableCompleteQuestionnaire(): void
    {
        $template = $this->templates->forSession($this->session);
        self::assertNotNull($template->getId());
        self::assertSame($this->session->getEntite(), $template->getEntite());
        self::assertSame(ParticipantQrSatisfactionTemplate::SYSTEM_KEY, $template->getSystemKey());
        self::assertSame($template, $this->templates->forSession($this->session));
        self::assertCount(1, $template->getChapters());
        $questions = $template->getChapters()->first()->getQuestions()->toArray();
        self::assertCount(4, $questions);
        foreach (array_slice($questions, 0, 3) as $question) {
            self::assertSame(SatisfactionQuestionType::SCALE, $question->getType());
            self::assertSame(10, $question->getMetricMax());
            self::assertTrue($question->isRequired());
            self::assertSame($this->session->getEntite(), $question->getEntite());
            self::assertSame($this->admin, $question->getCreateur());
        }
        self::assertSame(['overall_rating', 'organism_rating', 'trainer_rating', null], array_map(fn($q) => $q->getMetricKey(), $questions));
        self::assertSame(SatisfactionQuestionType::TEXTAREA, $questions[3]->getType());
        self::assertFalse($questions[3]->isRequired());
        // Renaming a model does not cause a second automatic questionnaire.
        $template->setTitre('Mon appréciation personnalisée');
        $this->em->flush();
        self::assertSame($template, $this->templates->forSession($this->session));
        self::assertSame(1, $this->em->getRepository(SatisfactionTemplate::class)->count([]));
    }

    public function testExplicitCatalogueQuestionnaireHasPriority(): void
    {
        $configured = $this->configuredTemplate($this->session->getEntite());
        self::assertSame($configured, $this->templates->forSession($this->session));
        self::assertSame(0, $this->em->getRepository(SatisfactionTemplate::class)->count(['systemKey' => ParticipantQrSatisfactionTemplate::SYSTEM_KEY]));
        $configured->setIsActive(false);
        $this->em->flush();
        self::assertNotSame($configured, $this->templates->forSession($this->session));
    }

    public function testFallbackIsTenantSpecificAndIgnoresOtherTemplates(): void
    {
        $other = (new Entite())->setNom('Other')->setCreateur($this->admin)->setPublic(false);
        $this->em->persist($other);
        $this->em->flush();
        $foreign = $this->configuredTemplate($other);
        $arbitrary = (new SatisfactionTemplate())->setEntite($this->session->getEntite())->setCreateur($this->admin)->setTitre('Appréciation de la formation — questionnaire standard');
        $this->em->persist($arbitrary);
        $this->em->flush();
        $first = $this->templates->forSession($this->session);
        self::assertNotSame($foreign, $first);
        self::assertNotSame($arbitrary, $first);
        $foreignSession = (new Session())->setEntite($other)->setCreateur($this->admin);
        $second = $this->templates->forSession($foreignSession);
        self::assertNotSame($first->getId(), $second->getId());
        self::assertSame($other, $second->getEntite());
    }

    private function configuredTemplate(Entite $entite): SatisfactionTemplate
    {
        $template = (new SatisfactionTemplate())->setEntite($entite)->setCreateur($this->admin)->setTitre('Catalogue');
        $chapter = (new SatisfactionChapter())->setEntite($entite)->setCreateur($this->admin);
        $template->addChapter($chapter);
        $chapter->addQuestion((new SatisfactionQuestion())->setLibelle('Question configurée'));
        $formation = (new Formation())->setEntite($this->session->getEntite())->setCreateur($this->admin)->setTitre('Formation catalogue')->setSlug('catalogue-qr');
        $formation->setSatisfactionTemplate($template);
        $this->session->setFormation($formation);
        foreach ([$template, $formation] as $entity) $this->em->persist($entity);
        $this->em->flush();
        return $template;
    }
}
