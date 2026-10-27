<?php

namespace App\Tests\Integration;

use App\Entity\{Entite, Formateur, SatisfactionTemplate, Utilisateur, UtilisateurEntite};
use App\Entity\Billing\EntiteSubscription;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};
use Symfony\Component\DomCrawler\Crawler;

/** The shared desktop/mobile navigation must retain tenant permissions and valid controls. */
final class WorkspaceNavigationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Entite $entite;
    private Utilisateur $user;
    private UtilisateurEntite $membership;
    private SatisfactionTemplate $template;
    private array $originalDatabase;

    protected function setUp(): void
    {
        $this->originalDatabase = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        self::assertTrue((bool) ($this->em->getConnection()->getParams()['memory'] ?? false));
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->user = (new Utilisateur())->setEmail('navigation@example.test')->setPassword('unused')->setPrenom('Camille')->setNom('Navigation');
        $this->entite = (new Entite())->setNom('Organisme navigation')->setPublic(false)->setCreateur($this->user)
            ->setLogo('logo-wikiformation.png')->setCouleurPrincipal('#233342')->setCouleurSecondaire('#ffc107')
            ->setCouleurTertiaire('#f0f0f0')->setCouleurQuaternaire('#000000');
        $this->em->persist($this->user);
        $this->em->persist($this->entite);
        $this->em->flush();
        $this->user->setEntite($this->entite);
        $this->membership = (new UtilisateurEntite())->setUtilisateur($this->user)->setEntite($this->entite)->setCreateur($this->user)
            ->setRoles([UtilisateurEntite::TENANT_ADMIN])->setStatus(UtilisateurEntite::STATUS_ACTIVE);
        $this->template = (new SatisfactionTemplate())->setTitre('Navigation')->setEntite($this->entite)->setCreateur($this->user)->setIsActive(true);
        foreach ([$this->membership, $this->template, (new EntiteSubscription())->setEntite($this->entite)->setStatus('active')] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $i => $type) {
            if ($this->originalDatabase[$i] === null) unset($GLOBALS['_'.$type]['DATABASE_URL']);
            else $GLOBALS['_'.$type]['DATABASE_URL'] = $this->originalDatabase[$i];
        }
    }

    public function testMultipleRolesShareOneCompleteAccessibleNavigation(): void
    {
        $this->membership->setRoles([
            UtilisateurEntite::TENANT_ADMIN, UtilisateurEntite::TENANT_FORMATEUR, UtilisateurEntite::TENANT_STAGIAIRE,
            UtilisateurEntite::TENANT_COMMERCIAL, UtilisateurEntite::TENANT_OPCO, UtilisateurEntite::TENANT_OF,
        ]);
        $this->em->flush();
        $client = $this->client();
        $page = $client->request('GET', $this->editorUrl());
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(1, $page->filter('#workspaceSidebar'));
        self::assertCount(1, $page->filter('#workspaceNavTree'));
        self::assertCount(0, $page->filter('#mobileMenu'), 'Mobile must use the same navigation tree.');

        $navigation = $page->filter('#workspaceSidebar');
        $destinations = $navigation->filter('a[href]')->each(static fn(Crawler $link) => $link->attr('href'));
        foreach ([
            'app_administrateur_session_index', 'app_administrateur_workflow_index', 'app_administrateur_depense_index',
            'app_administrateur_satisfaction_template_index', 'app_administrateur_formateur_satisfaction_template_index',
            'app_habilitation_attestations', 'app_charter_mine', 'app_formateur_dashboard', 'app_stagiaire_dashboard',
            'app_commercial_dashboard', 'app_opco_dashboard',
        ] as $route) {
            self::assertContains($this->url($route), $destinations, 'Missing navigation destination: '.$route);
        }
        $ids = $page->filter('[id]')->each(static fn(Crawler $element) => $element->attr('id'));
        self::assertSame($ids, array_values(array_unique($ids)), 'Duplicate IDs break collapse controls.');
        foreach ($page->filter('#workspaceSidebar [aria-controls], [data-navigation-toggle]') as $control) {
            foreach (explode(' ', $control->getAttribute('aria-controls')) as $id) {
                self::assertCount(1, $page->filter('[id="'.$id.'"]'), 'Control must have exactly one target: '.$id);
            }
        }
        self::assertSame('true', $navigation->filter('[aria-controls="submenuSatisfaction"]')->attr('aria-expanded'));
        self::assertStringContainsString('show', $navigation->filter('#submenuSatisfaction')->attr('class'));
        self::assertSame('Logo Organisme navigation', $navigation->filter('.wn-brand-logo img')->attr('alt'));
        self::assertStringEndsWith('/uploads/photos/entite/logo/logo-wikiformation.png', $navigation->filter('.wn-brand-logo img')->attr('src'));
        self::assertCount(1, $page->filter('link[href*="workspace-navigation.css"]'));
        self::assertCount(1, $page->filter('script[src*="workspace-navigation.js"]'));
    }

    public function testTrainerNavigationDoesNotExposeAdministrationOrLearnerSections(): void
    {
        $this->membership->setRoles([UtilisateurEntite::TENANT_FORMATEUR]);
        $trainer = (new Formateur())->setEntite($this->entite)->setCreateur($this->user)->setUtilisateur($this->user);
        $this->em->persist($trainer);
        $this->em->flush();
        $client = $this->client();
        $page = $client->request('GET', $this->url('app_formateur_dashboard'));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertPortalPermissions($page, 'app_formateur_dashboard', 'app_stagiaire_dashboard');
    }

    public function testLearnerNavigationDoesNotExposeAdministrationOrTrainerSections(): void
    {
        $this->membership->setRoles([UtilisateurEntite::TENANT_STAGIAIRE]);
        $this->em->flush();
        $client = $this->client();
        $page = $client->request('GET', $this->url('app_stagiaire_dashboard'));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertPortalPermissions($page, 'app_stagiaire_dashboard', 'app_formateur_dashboard');
    }

    public function testTenantColorsAreEmittedForTheCommonDesktopAndMobileShell(): void
    {
        $this->entite->setCouleurPrincipal('#f6f3ec')->setCouleurSecondaire('#735626')
            ->setCouleurTertiaire('#ffffff')->setCouleurQuaternaire('#213547');
        $this->em->flush();
        $client = $this->client();
        $client->request('GET', $this->editorUrl());
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $html = $client->getResponse()->getContent();
        foreach (['principal' => '#f6f3ec', 'secondaire' => '#735626', 'tertiaire' => '#ffffff', 'quaternaire' => '#213547'] as $name => $color) {
            self::assertStringContainsString('--theme-color-'.$name.': '.$color.';', $html);
        }
    }

    private function assertPortalPermissions(Crawler $page, string $presentRoute, string $absentRoute): void
    {
        $destinations = $page->filter('#workspaceSidebar a[href]')->each(static fn(Crawler $link) => $link->attr('href'));
        self::assertContains($this->url($presentRoute), $destinations);
        foreach ([$absentRoute, 'app_administrateur_session_index', 'app_administrateur_depense_index', 'app_administrateur_billing'] as $route) {
            self::assertNotContains($this->url($route), $destinations);
        }
        self::assertCount(0, $page->filter('.wn-settings'));
        self::assertCount(0, $page->filter('#mobileMenu'));
        self::assertCount(1, $page->filter('#workspaceNavTree'));
    }

    private function client(): KernelBrowser
    {
        $client = new KernelBrowser(self::$kernel);
        $client->disableReboot();
        $client->catchExceptions(false);
        $client->loginUser($this->user);
        return $client;
    }

    private function url(string $route): string
    {
        return self::getContainer()->get('router')->generate($route, ['_locale' => 'fr', 'entite' => $this->entite->getId()]);
    }

    private function editorUrl(): string
    {
        return '/fr/administrateur/'.$this->entite->getId().'/satisfaction/templates/'.$this->template->getId().'/edit';
    }
}
