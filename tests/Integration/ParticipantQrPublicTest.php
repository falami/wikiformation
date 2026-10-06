<?php

declare(strict_types=1);
namespace App\Tests\Integration;

use App\Entity\{ConventionContrat, Emargement, Entite, Formation, Inscription, SatisfactionAssignment, SatisfactionAttempt, SatisfactionChapter, SatisfactionQuestion, SatisfactionTemplate, Session, SessionJour, SessionParticipantAccess, Site, Utilisateur};
use App\Enum\{DemiJournee, SatisfactionQuestionType, StatusSession, TypeFinancement};
use App\Service\Session\ParticipantQrAccess;
use Doctrine\ORM\{EntityManagerInterface, Tools\SchemaTool};
use Symfony\Bundle\FrameworkBundle\{KernelBrowser, Test\KernelTestCase};

final class ParticipantQrPublicTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ParticipantQrAccess $qr;
    private Utilisateur $admin;
    private Utilisateur $learner;
    private Entite $entite;
    private Session $session;
    private Inscription $inscription;
    private ConventionContrat $convention;
    private SessionParticipantAccess $guest;
    private SessionParticipantAccess $member;
    private SatisfactionTemplate $template;
    private SatisfactionQuestion $question;
    private array $database;

    protected function setUp(): void
    {
        $this->database = [$_ENV['DATABASE_URL'] ?? null, $_SERVER['DATABASE_URL'] ?? null];
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine')->getManager();
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->admin = (new Utilisateur())->setEmail('qr-owner@example.test')->setPassword('unused')->setPrenom('Alice')->setNom('Formateur');
        $this->learner = (new Utilisateur())->setEmail('qr-member@example.test')->setPassword('unused')->setPrenom('Camille')->setNom('Compte');
        $this->entite = (new Entite())->setNom('Centre de formation')->setPublic(false)->setCreateur($this->admin);
        foreach ([$this->admin, $this->learner, $this->entite] as $object) $this->em->persist($object);
        $this->em->flush();
        $site = (new Site())->setEntite($this->entite)->setCreateur($this->admin)->setNom('Site de formation')->setSlug('qr-site');
        $formation = (new Formation())->setEntite($this->entite)->setCreateur($this->admin)->setTitre('Gestes et prévention')->setSlug('qr-formation');
        $this->session = (new Session())->setEntite($this->entite)->setCreateur($this->admin)->setSite($site)->setFormation($formation)->setCode('QR-SESSION');
        $this->session->addJour((new SessionJour())->setEntite($this->entite)->setCreateur($this->admin)->setDateDebut(new \DateTimeImmutable('today 08:30'))->setDateFin(new \DateTimeImmutable('today 17:00')));
        $this->inscription = (new Inscription())->setSession($this->session)->setEntite($this->entite)->setCreateur($this->admin)->setStagiaire($this->learner);
        $this->convention = (new ConventionContrat())->setEntite($this->entite)->setCreateur($this->admin)->setSession($this->session)->setParticipantsLibres("Alex Martin\nSam Durand");
        $this->template = (new SatisfactionTemplate())->setEntite($this->entite)->setCreateur($this->admin)->setTitre('Appréciation de la formation');
        $chapter = (new SatisfactionChapter())->setEntite($this->entite)->setCreateur($this->admin)->setTitre('Votre expérience');
        $this->question = (new SatisfactionQuestion())->setEntite($this->entite)->setCreateur($this->admin)->setLibelle('Comment évaluez-vous la formation ?')->setType(SatisfactionQuestionType::SCALE)->setRequired(true)->setMetricKey('overall_rating');
        $chapter->addQuestion($this->question);
        $this->template->addChapter($chapter);
        $formation->setSatisfactionTemplate($this->template);
        foreach ([$site, $formation, $this->session, $this->inscription, $this->convention, $this->template] as $object) $this->em->persist($object);
        $this->em->flush();
        $this->qr = self::getContainer()->get(ParticipantQrAccess::class);
        $roster = $this->qr->roster($this->session);
        $this->member = $roster[0];
        $this->guest = $roster[1];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (['ENV', 'SERVER'] as $i => $type) {
            if ($this->database[$i] === null) unset($GLOBALS['_' . $type]['DATABASE_URL']);
            else $GLOBALS['_' . $type]['DATABASE_URL'] = $this->database[$i];
        }
    }

    public function testRenderedParisDateCanBeSubmittedWhenTwigUsesUtc(): void
    {
        self::getContainer()->get('twig')->getExtension(\Twig\Extension\CoreExtension::class)->setTimezone('UTC');
        $day = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris'));
        foreach ($this->session->getJours() as $jour) {
            $jour->setDateDebut($day->setTime(8, 30))->setDateFin($day->setTime(17, 0));
        }
        $this->em->flush();
        $client = $this->client();
        $url = $this->url($this->guest, 'attendance');
        $crawler = $client->request('GET', $url);
        $renderedDate = $crawler->filter('input[name="date"]')->attr('value');
        self::assertSame($day->format('Y-m-d'), $renderedDate);
        self::assertStringContainsString('Émargement du '.$day->format('d/m/Y'), $client->getResponse()->getContent());
        $data = $this->attendanceData($crawler->filter('[name="_token"]')->attr('value'));
        $data['date'] = $renderedDate;
        $client->request('POST', $url, $data);
        self::assertSame(303, $client->getResponse()->getStatusCode());
        $record = $this->em->getRepository(Emargement::class)->findOneBy(['participantAccess' => $this->guest]);
        self::assertNotNull($record);
        self::assertSame($day->format('Y-m-d'), $record->getDateJour()->format('Y-m-d'));
    }

    public function testPastPlannedDayCanBeSignedWithActualSigningTime(): void
    {
        $day = new \DateTimeImmutable('-20 days', new \DateTimeZone('Europe/Paris'));
        foreach ($this->session->getJours() as $jour) $jour->setDateDebut($day->setTime(8, 30))->setDateFin($day->setTime(17, 0));
        $this->em->flush();
        $client = $this->client();
        $url = $this->url($this->guest, 'attendance');
        $crawler = $client->request('GET', $url);
        self::assertSame($day->format('Y-m-d'), $crawler->filter('input[name="date"]')->attr('value'));
        self::assertStringContainsString('Régularisation de présence', $client->getResponse()->getContent());
        $data = $this->attendanceData($crawler->filter('[name="_token"]')->attr('value'));
        $data['date'] = $day->format('Y-m-d');
        $client->request('POST', $url, $data);
        self::assertSame(303, $client->getResponse()->getStatusCode());
        $record = $this->em->getRepository(Emargement::class)->findOneBy(['participantAccess' => $this->guest]);
        self::assertSame($day->format('Y-m-d'), $record->getDateJour()->format('Y-m-d'));
        self::assertEqualsWithDelta(time(), $record->getSignedAt()->getTimestamp(), 5);
        $signedAt = $record->getSignedAt();
        $client->request('POST', $url, $data);
        self::assertSame(1, $this->em->getRepository(Emargement::class)->count([]));
        self::assertEquals($signedAt, $record->getSignedAt());
        $client->followRedirect();
        self::assertStringContainsString('Présence enregistrée', $client->getResponse()->getContent());
        self::assertStringContainsString($day->format('d/m/Y'), $client->getResponse()->getContent());
    }

    public function testFuturePlannedDayCannotBeSigned(): void
    {
        $client = $this->client();
        $url = $this->url($this->guest, 'attendance');
        $crawler = $client->request('GET', $url);
        $data = $this->attendanceData($crawler->filter('[name="_token"]')->attr('value'));
        $day = new \DateTimeImmutable('tomorrow', new \DateTimeZone('Europe/Paris'));
        foreach ($this->session->getJours() as $jour) $jour->setDateDebut($day->setTime(8, 30))->setDateFin($day->setTime(17, 0));
        $this->em->flush();
        $data['date'] = $day->format('Y-m-d');
        $client->request('POST', $url, $data);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(Emargement::class)->count([]));
    }

    public function testGuestCanSignWithoutAccountAndGetNeverSigns(): void
    {
        $client = $this->client();
        $url = $this->url($this->guest, 'attendance');
        $crawler = $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Alex Martin', $client->getResponse()->getContent());
        self::assertSame(0, $this->em->getRepository(Emargement::class)->count([]));
        self::assertSame(2, $this->em->getRepository(Utilisateur::class)->count([]));
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $client->getResponse()->headers->get('Referrer-Policy'));
        $data = $this->attendanceData($crawler->filter('[name="_token"]')->attr('value'));
        $client->request('POST', $url, $data);
        self::assertSame(303, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $record = $this->em->getRepository(Emargement::class)->findOneBy(['participantAccess' => $this->guest]);
        self::assertNotNull($record);
        self::assertNull($record->getUtilisateur());
        self::assertSame($this->admin->getId(), $record->getCreateur()->getId());
        self::assertSame($data['signature'], $record->getSignatureDataUrl());
        $signature = $record->getSignatureDataUrl();
        $client->request('POST', $url, $data + ['ignored' => 'replay']);
        self::assertSame(1, $this->em->getRepository(Emargement::class)->count([]));
        self::assertSame($signature, $record->getSignatureDataUrl());
        $client->followRedirect();
        self::assertStringContainsString('Présence enregistrée', $client->getResponse()->getContent());
    }

    public function testMemberReusesExistingAttendanceRow(): void
    {
        $record = (new Emargement())->setSession($this->session)->setEntite($this->entite)->setCreateur($this->admin)->setUtilisateur($this->learner)->setRole('stagiaire')->setPeriode(DemiJournee::AM)->setDateJour(new \DateTimeImmutable('today'));
        $this->em->persist($record); $this->em->flush();
        $client = $this->client();
        $url = $this->url($this->member, 'attendance');
        $crawler = $client->request('GET', $url);
        $client->request('POST', $url, $this->attendanceData($crawler->filter('[name="_token"]')->attr('value')));
        self::assertSame(303, $client->getResponse()->getStatusCode());
        self::assertSame(1, $this->em->getRepository(Emargement::class)->count([]));
        $record = $this->em->find(Emargement::class, $record->getId());
        self::assertNotNull($record->getSignedAt());
        self::assertSame($this->learner->getId(), $record->getUtilisateur()->getId());
    }

    public function testAttendanceRejectsForgedDatesCsrfAndImages(): void
    {
        $client = $this->client();
        $url = $this->url($this->guest, 'attendance');
        $crawler = $client->request('GET', $url);
        $valid = $this->attendanceData($crawler->filter('[name="_token"]')->attr('value'));
        foreach ([['_token' => 'invalid'], ['date' => (new \DateTimeImmutable('yesterday'))->format('Y-m-d')], ['periode' => 'EVENING'], ['signature' => 'data:image/png;base64,' . base64_encode('not a PNG')], ['signature' => str_repeat('a', 350001)], ['confirmation' => '0']] as $override) {
            $client->request('POST', $url, array_replace($valid, $override));
            self::assertSame(422, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
            self::assertSame(0, $this->em->getRepository(Emargement::class)->count([]));
        }
        $this->session = $this->em->find(Session::class, $this->session->getId());
        foreach ($this->session->getJours() as $jour) $jour->setDateDebut(new \DateTimeImmutable('today 14:00'))->setDateFin(new \DateTimeImmutable('today 17:00'));
        $this->em->flush();
        $client->request('POST', $url, $valid);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(Emargement::class)->count([]));

        // Database clock times remain local even if the PHP default timezone differs from Paris.
        $this->session = $this->em->find(Session::class, $this->session->getId());
        foreach ($this->session->getJours() as $jour) $jour
            ->setDateDebut(new \DateTimeImmutable('today 12:30', new \DateTimeZone('UTC')))
            ->setDateFin(new \DateTimeImmutable('today 13:00', new \DateTimeZone('UTC')));
        $this->em->flush();
        $client->request('POST', $url, $valid);
        self::assertSame(303, $client->getResponse()->getStatusCode());
        self::assertSame(1, $this->em->getRepository(Emargement::class)->count([]));
    }

    public function testQrSignsMorningAndAfternoonSeparately(): void
    {
        $client = $this->client();
        $url = $this->url($this->member, 'attendance');
        $crawler = $client->request('GET', $url);
        $data = $this->attendanceData($crawler->filter('[name="_token"]')->attr('value'));
        foreach (['AM', 'PM'] as $period) {
            $client->request('POST', $url, array_replace($data, ['periode' => $period]));
            self::assertSame(303, $client->getResponse()->getStatusCode());
        }
        $records = $this->em->getRepository(Emargement::class)->findBy(['utilisateur' => $this->learner]);
        self::assertCount(2, $records);
        self::assertEqualsCanonicalizing([DemiJournee::AM, DemiJournee::PM], array_map(fn ($record) => $record->getPeriode(), $records));
        foreach ($records as $record) self::assertNotNull($record->getSignedAt());
    }

    public function testWrongPurposeExpiredRemovedAndCancelledLinksAreDenied(): void
    {
        $client = $this->client();
        $attendance = $this->qr->token($this->guest, 'attendance');
        $client->request('GET', '/fr/appreciation-participant/' . $attendance);
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $client->request('GET', '/fr/presence-participant/' . substr($attendance, 0, -1) . (str_ends_with($attendance, '0') ? '1' : '0'));
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $this->guest = $this->em->find(SessionParticipantAccess::class, $this->guest->getId());
        $this->guest->setExpiresAt(new \DateTimeImmutable('yesterday'));
        $this->em->flush();
        $client->request('GET', $this->url($this->guest, 'attendance'));
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $this->guest = $this->em->find(SessionParticipantAccess::class, $this->guest->getId());
        $this->convention = $this->em->find(ConventionContrat::class, $this->convention->getId());
        $this->guest->setExpiresAt(new \DateTimeImmutable('+1 month'));
        $this->convention->setParticipantsLibres('Sam Durand');
        $this->em->flush();
        $client->request('GET', $this->url($this->guest, 'attendance'));
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $this->session = $this->em->find(Session::class, $this->session->getId());
        $this->session->setStatus(StatusSession::CANCELED);
        $this->em->flush();
        $client->request('GET', $this->url($this->member, 'attendance'));
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testSubcontractedAttendanceIsUnavailable(): void
    {
        $this->session->setTypeFinancement(TypeFinancement::OUI);
        $this->em->flush();
        $client = $this->client();
        $client->request('GET', $this->url($this->member, 'attendance'));
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(Emargement::class)->count([]));
    }

    public function testGuestSatisfactionWritesExistingQuestionnaireAndLocksResponse(): void
    {
        $client = $this->client();
        $url = $this->url($this->guest, 'satisfaction');
        $crawler = $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(SatisfactionAttempt::class)->count([]));
        $token = $crawler->filter('[name="satisfaction_fill[_token]"]')->attr('value');
        $client->request('POST', $url, ['satisfaction_fill' => ['_token' => $token, 'q_' . $this->question->getId() => '8']]);
        self::assertSame(303, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $assignment = $this->em->getRepository(SatisfactionAssignment::class)->findOneBy(['participantAccess' => $this->guest]);
        self::assertNotNull($assignment);
        self::assertNull($assignment->getStagiaire());
        self::assertSame(8, $assignment->getAttempt()->getNoteGlobale());
        self::assertTrue($assignment->getAttempt()->isSubmitted());
        self::assertSame($this->entite->getId(), $assignment->getAttempt()->getEntite()->getId());
        $client->request('POST', $url, ['satisfaction_fill' => ['_token' => $token, 'q_' . $this->question->getId() => '1']]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(8, $assignment->getAttempt()->getNoteGlobale());
        self::assertSame(1, $this->em->getRepository(SatisfactionAttempt::class)->count([]));
        self::assertSame(2, $this->em->getRepository(Utilisateur::class)->count([]));
    }

    public function testSatisfactionRequiresAnswerAndValidCsrf(): void
    {
        $client = $this->client();
        $url = $this->url($this->member, 'satisfaction');
        $crawler = $client->request('GET', $url);
        $token = $crawler->filter('[name="satisfaction_fill[_token]"]')->attr('value');
        foreach ([['_token' => $token], ['_token' => 'invalid', 'q_' . $this->question->getId() => '8']] as $body) {
            $client->request('POST', $url, ['satisfaction_fill' => $body]);
            self::assertSame(422, $client->getResponse()->getStatusCode());
            self::assertSame(0, $this->em->getRepository(SatisfactionAttempt::class)->count([]));
        }
    }

    public function testMemberReusesAssignedQuestionnaireAndCreatesNoGuestAssignment(): void
    {
        $assignment = (new SatisfactionAssignment())->setSession($this->session)->setEntite($this->entite)->setCreateur($this->admin)->setStagiaire($this->learner)->setInscription($this->inscription)->setTemplate($this->template);
        $attempt = (new SatisfactionAttempt())->setEntite($this->entite)->setCreateur($this->admin)->setAssignment($assignment);
        $this->em->persist($assignment); $this->em->persist($attempt); $this->em->flush();
        $client = $this->client();
        $url = $this->url($this->member, 'satisfaction');
        $crawler = $client->request('GET', $url);
        $token = $crawler->filter('[name="satisfaction_fill[_token]"]')->attr('value');
        $client->request('POST', $url, ['satisfaction_fill' => ['_token' => $token, 'q_' . $this->question->getId() => '10']]);
        self::assertSame(303, $client->getResponse()->getStatusCode());
        self::assertSame(1, $this->em->getRepository(SatisfactionAssignment::class)->count([]));
        self::assertSame(1, $this->em->getRepository(SatisfactionAttempt::class)->count([]));
        $saved = $this->em->find(SatisfactionAssignment::class, $assignment->getId());
        self::assertSame($this->learner->getId(), $saved->getStagiaire()->getId());
        self::assertSame($this->template->getId(), $saved->getTemplate()->getId());
        self::assertSame(10, $saved->getAttempt()->getNoteGlobale());
    }

    public function testFormationQuestionnaireOverridesAnOlderPendingAssignment(): void
    {
        $old = (new SatisfactionTemplate())->setEntite($this->entite)->setCreateur($this->admin)->setTitre('Ancien questionnaire');
        $this->em->persist($old);
        foreach ([$this->member, $this->guest] as $access) {
            $assignment = (new SatisfactionAssignment())->setSession($this->session)->setEntite($this->entite)
                ->setCreateur($this->admin)->setTemplate($old)->setParticipantAccess($access)
                ->setStagiaire($access->getInscription()?->getStagiaire())->setInscription($access->getInscription());
            $this->em->persist($assignment);
        }
        $this->em->flush();
        $client = $this->client();
        foreach ([$this->member, $this->guest] as $access) {
            $url = $this->url($access, 'satisfaction');
            $crawler = $client->request('GET', $url);
            self::assertStringContainsString($this->question->getLibelle(), $client->getResponse()->getContent());
            $token = $crawler->filter('[name="satisfaction_fill[_token]"]')->attr('value');
            $client->request('POST', $url, ['satisfaction_fill' => ['_token' => $token, 'q_' . $this->question->getId() => '9']]);
            self::assertSame(303, $client->getResponse()->getStatusCode());
            $saved = $this->em->getRepository(SatisfactionAssignment::class)->findOneBy(['participantAccess' => $access, 'template' => $this->template]);
            self::assertNotNull($saved);
            self::assertSame(9, $saved->getAttempt()->getNoteGlobale());
            $previous = $this->em->getRepository(SatisfactionAssignment::class)->findOneBy(['participantAccess' => $access, 'template' => $old]);
            self::assertNull($previous->getAttempt());
        }
    }

    public function testGuestFallbackQuestionnaireWorksForFreeTextFormation(): void
    {
        $this->session->setFormation(null)->setFormationIntituleLibre('Formation sur mesure');
        $this->em->flush();
        $client = $this->client();
        $url = $this->url($this->guest, 'satisfaction');
        $crawler = $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $fields = $crawler->filter('input[type="radio"]');
        self::assertGreaterThanOrEqual(15, $fields->count());
        $values = ['_token' => $crawler->filter('[name="satisfaction_fill[_token]"]')->attr('value')];
        foreach ($fields as $field) {
            if (preg_match('/\[q_(\d+)\]/', $field->getAttribute('name'), $match)) $values['q_' . $match[1]] = '4';
        }
        $client->request('POST', $url, ['satisfaction_fill' => $values]);
        self::assertSame(303, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $saved = $this->em->getRepository(SatisfactionAssignment::class)->findOneBy(['participantAccess' => $this->guest]);
        self::assertTrue($saved->getAttempt()->isSubmitted());
        self::assertSame(4, $saved->getAttempt()->getNoteGlobale());
    }

    public function testForeignTenantSourceAndBlankSignatureAreDenied(): void
    {
        $client = $this->client();
        $url = $this->url($this->guest, 'attendance');
        $crawler = $client->request('GET', $url);
        $data = $this->attendanceData($crawler->filter('[name="_token"]')->attr('value'));
        $image = imagecreatetruecolor(100, 40);
        imagefilledrectangle($image, 0, 0, 99, 39, imagecolorallocate($image, 255, 255, 255));
        ob_start(); imagepng($image); $data['signature'] = 'data:image/png;base64,' . base64_encode(ob_get_clean()); imagedestroy($image);
        $client->request('POST', $url, $data);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame(0, $this->em->getRepository(Emargement::class)->count([]));
        $owner = $this->em->find(Utilisateur::class, $this->admin->getId());
        $foreign = (new Entite())->setNom('Autre organisme')->setCreateur($owner)->setPublic(false);
        $this->em->persist($foreign);
        $convention = $this->em->find(ConventionContrat::class, $this->convention->getId());
        $convention->setEntite($foreign);
        $this->em->flush();
        $client->request('GET', $url);
        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Alex Martin', $client->getResponse()->getContent());
    }

    private function attendanceData(string $token): array
    {
        $image = imagecreatetruecolor(100, 40);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, 99, 39, $white);
        imageline($image, 10, 15, 80, 30, imagecolorallocate($image, 0, 0, 0));
        ob_start(); imagepng($image); $png = ob_get_clean(); imagedestroy($image);
        return ['_token' => $token, 'date' => (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris')))->format('Y-m-d'), 'periode' => 'AM', 'signature' => 'data:image/png;base64,' . base64_encode($png), 'confirmation' => '1'];
    }

    private function client(): KernelBrowser
    {
        $client = self::getContainer()->get('test.client');
        $client->disableReboot();
        $client->catchExceptions(false);
        return $client;
    }

    private function url(SessionParticipantAccess $access, string $purpose): string
    {
        return self::getContainer()->get('router')->generate('app_participant_qr_' . $purpose, ['token' => $this->qr->token($access, $purpose)]);
    }
}
