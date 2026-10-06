<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\{Emargement, SatisfactionAssignment, SatisfactionAttempt, Session, SessionParticipantAccess};
use App\Enum\DemiJournee;
use App\Form\Satisfaction\SatisfactionFillType;
use App\Service\Session\{ParticipantQrAccess, ParticipantQrSatisfactionTemplate};
use App\Service\Satisfaction\SatisfactionKpiExtractor;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

/** A QR grants access to one participant, one session and one purpose only. */
final class ParticipantQrPublicController extends AbstractController
{
    #[Route('/presence-participant/{token}', name: 'app_participant_qr_attendance', methods: ['GET', 'POST'])]
    public function attendance(string $token, Request $request, ParticipantQrAccess $qr, EntityManagerInterface $em): Response
    {
        $access = $qr->resolve($token, 'attendance');
        if (!$access) return $this->unavailable();
        $session = $access->getSession();
        if (!$session->isEmargementRequis()) {
            return $this->unavailable('Les émargements de cette session sont gérés par votre organisme de formation.', $access, 403);
        }

        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris'));
        $days = [];
        foreach ($session->getJours() as $jour) {
            $key = $jour->getDateDebut()?->format('Y-m-d');
            if (!$key || $key > $today->format('Y-m-d')) continue;
            $day = new \DateTimeImmutable($key, new \DateTimeZone('Europe/Paris'));
            if ($this->periods($session, $day)) $days[$key] = $day;
        }
        ksort($days);
        $defaultDate = isset($days[$today->format('Y-m-d')]) ? $today->format('Y-m-d') : array_key_first($days);
        $requestedDate = $request->isMethod('POST') ? $request->request->get('date') : $request->query->get('date', $defaultDate);
        $selectedDay = is_string($requestedDate) ? ($days[$requestedDate] ?? null) : null;
        $periods = $selectedDay ? $this->periods($session, $selectedDay) : [];
        $error = null;
        if ($request->isMethod('POST')) {
            $period = (string) $request->request->get('periode', '');
            $signature = (string) $request->request->get('signature', '');
            if (!$this->isCsrfTokenValid('participant_attendance_' . $access->getId(), $request->request->get('_token'))) {
                $error = 'Votre page a expiré. Actualisez-la, puis signez à nouveau.';
            } elseif (!$selectedDay || !isset($periods[$period])) {
                $error = 'Choisissez une demi-journée prévue dans cette session, passée ou aujourd’hui.';
            } elseif ($request->request->get('confirmation') !== '1') {
                $error = 'Confirmez votre présence avant de valider.';
            } elseif (!$this->validSignature($signature)) {
                $error = 'Dessinez votre signature dans le cadre avant de valider.';
            } else {
                $em->beginTransaction();
                try {
                    // Serialize submissions for this participant; an existing signature is immutable here.
                    $em->lock($access, LockMode::PESSIMISTIC_WRITE);
                    $record = $this->attendanceRecord($em, $access, $selectedDay, $period);
                    if ($record) $em->refresh($record);
                    if (!$record || !$this->isSigned($record)) {
                        $record ??= (new Emargement())->setSession($session)->setEntite($access->getEntite())
                            ->setCreateur($session->getCreateur())->setUtilisateur($access->getInscription()?->getStagiaire())
                            ->setParticipantAccess($access)->setRole('stagiaire')->setDateJour($selectedDay)->setPeriode(DemiJournee::from($period));
                        $record->setSignatureDataUrl($signature)->setSignedAt(new \DateTimeImmutable())
                            ->setIp($request->getClientIp())->setUserAgent(mb_substr((string) $request->headers->get('User-Agent'), 0, 255))
                            ->setUpdatedAt(new \DateTimeImmutable());
                        $em->persist($record);
                        $em->flush();
                    }
                    $em->commit();
                } catch (UniqueConstraintViolationException) {
                    $em->rollback();
                    return $this->unavailable('Une réponse vient d’être enregistrée. Actualisez la page pour consulter son état.', $access, 409);
                } catch (\Throwable $exception) {
                    $em->rollback();
                    throw $exception;
                }
                return $this->privateResponse($this->redirectToRoute('app_participant_qr_attendance', ['token' => $token, 'date' => $selectedDay->format('Y-m-d')], 303));
            }
        }
        // One query for this participant's calendar, including signatures made through other interfaces.
        $criteria = ['session' => $session, 'entite' => $access->getEntite(), 'role' => 'stagiaire'];
        if ($user = $access->getInscription()?->getStagiaire()) $criteria['utilisateur'] = $user;
        else $criteria['participantAccess'] = $access;
        $signedDays = [];
        foreach ($em->getRepository(Emargement::class)->findBy($criteria) as $record) {
            if ($this->isSigned($record)) $signedDays[$record->getDateJour()->format('Y-m-d')][$record->getPeriode()->value] = true;
        }
        $calendarStates = [];
        $completeDays = 0;
        foreach ($days as $key => $day) {
            $expected = $this->periods($session, $day);
            $count = count(array_intersect_key($signedDays[$key] ?? [], $expected));
            $complete = $count === count($expected);
            $calendarStates[$key] = ['complete' => $complete, 'signed' => $count, 'expected' => count($expected)];
            if ($complete) ++$completeDays;
        }
        $signed = [];
        foreach (array_keys($periods) as $period) {
            $record = $this->attendanceRecord($em, $access, $selectedDay, $period);
            if ($record && $this->isSigned($record)) $signed[$period] = $record->getSignedAt();
        }
        return $this->privateResponse($this->render('participant_qr/public_attendance.html.twig', [
            'access' => $access, 'session' => $session, 'entite' => $access->getEntite(),
            'calendarStates' => $calendarStates, 'completeDays' => $completeDays,
            'today' => $today, 'selectedDay' => $selectedDay, 'days' => $days, 'periods' => $periods, 'signed' => $signed, 'error' => $error,
        ], new Response(status: $error ? 422 : 200)));
    }

    #[Route('/appreciation-participant/{token}', name: 'app_participant_qr_satisfaction', methods: ['GET', 'POST'])]
    public function satisfaction(string $token, Request $request, ParticipantQrAccess $qr, EntityManagerInterface $em, SatisfactionKpiExtractor $kpis, ParticipantQrSatisfactionTemplate $templates): Response
    {
        $access = $qr->resolve($token, 'satisfaction');
        if (!$access) return $this->unavailable();
        $session = $access->getSession();
        $assignments = $this->assignments($em, $access);
        foreach ($assignments as $assignment) {
            if ($assignment->getAttempt()?->isSubmitted()) return $this->thankYou($access);
        }
        // The catalogue selection is authoritative, even if an older model was assigned.
        // Keep previous attempts on their original model instead of reinterpreting their answers.
        $template = $templates->forSession($session);
        $assignment = null;
        foreach ($assignments as $candidate) {
            if ($candidate->getTemplate()?->getId() === $template->getId()) {
                $assignment = $candidate;
                break;
            }
        }
        if (!$template || $template->getEntite()?->getId() !== $access->getEntite()?->getId()) {
            return $this->unavailable('Le questionnaire n’est pas encore disponible. Votre formateur pourra vous prévenir dès qu’il sera prêt.', $access, 200);
        }
        $form = $this->createForm(SatisfactionFillType::class, null, [
            'template' => $template, 'entite_id' => $access->getEntite()->getId(), 'only_public' => true,
            'csrf_token_id' => 'participant_satisfaction_' . $access->getId(),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            // HTML required alone is insufficient on an anonymous endpoint.
            foreach ($template->getChapters() as $chapter) foreach ($chapter->getQuestions() as $question) {
                $key = 'q_' . $question->getId();
                if (!$form->has($key) || !$form->get($key)->isSynchronized()) continue;
                $answer = $form->get($key)->getData();
                if ($question->isRequired() && ($answer === null || $answer === '' || $answer === [] || (is_string($answer) && trim($answer) === ''))) {
                    $form->get($key)->addError(new FormError('Merci de répondre à cette question.'));
                }
                if (is_string($answer) && mb_strlen($answer) > 10000) $form->get($key)->addError(new FormError('Votre réponse doit contenir au maximum 10 000 caractères.'));
            }
        }
        if ($form->isSubmitted() && $form->isValid()) {
            $answers = $this->answers($form->getData() ?? []);
            $em->beginTransaction();
            try {
                $em->lock($access, LockMode::PESSIMISTIC_WRITE);
                foreach ($this->assignments($em, $access) as $current) {
                    if ($current->getAttempt()) $em->refresh($current->getAttempt());
                    if ($current->getAttempt()?->isSubmitted()) {
                        $em->commit();
                        return $this->thankYou($access);
                    }
                    if ($current->getTemplate()?->getId() === $template->getId()) $assignment = $current;
                }
                if (!$assignment) {
                    $assignment = (new SatisfactionAssignment())->setSession($session)->setEntite($access->getEntite())
                        ->setCreateur($session->getCreateur())->setTemplate($template)->setIsRequired(true)
                        ->setStagiaire($access->getInscription()?->getStagiaire())->setInscription($access->getInscription())
                        ->setParticipantAccess($access);
                    $em->persist($assignment);
                }
                $attempt = $assignment->getAttempt() ?? (new SatisfactionAttempt())->setAssignment($assignment)
                    ->setEntite($access->getEntite())->setCreateur($session->getCreateur());
                $attempt->setStartedAt($attempt->getStartedAt() ?? new \DateTimeImmutable())->setAnswers($answers)->setSubmittedAt(new \DateTimeImmutable());
                $kpis->apply($attempt, $template, $answers);
                $em->persist($attempt);
                $em->flush();
                $em->commit();
            } catch (UniqueConstraintViolationException) {
                $em->rollback();
                return $this->unavailable('Une réponse vient d’être enregistrée. Actualisez la page pour consulter son état.', $access, 409);
            } catch (\Throwable $exception) {
                $em->rollback();
                throw $exception;
            }
            return $this->privateResponse($this->redirectToRoute('app_participant_qr_satisfaction', ['token' => $token], 303));
        }
        return $this->privateResponse($this->render('participant_qr/public_satisfaction.html.twig', [
            'access' => $access, 'session' => $session, 'entite' => $access->getEntite(), 'template' => $template, 'form' => $form->createView(),
        ], new Response(status: $form->isSubmitted() ? 422 : 200)));
    }

    /** @return array<string, string> */
    private function periods(Session $session, \DateTimeImmutable $day): array
    {
        $periods = [];
        foreach ($session->getJours() as $jour) {
            $start = $jour->getDateDebut();
            $end = $jour->getDateFin();
            if (!$start || !$end || $start->format('Y-m-d') !== $day->format('Y-m-d') || $end <= $start) continue;
            // Session times are stored as local wall-clock values (Doctrine has no timezone column).
            if ($start->format('H:i') < '13:00') $periods['AM'] = 'Matin';
            if ($end->format('H:i') > '13:00') $periods['PM'] = 'Après-midi';
        }
        return $periods;
    }

    private function attendanceRecord(EntityManagerInterface $em, SessionParticipantAccess $access, \DateTimeImmutable $day, string $period): ?Emargement
    {
        $criteria = ['session' => $access->getSession(), 'entite' => $access->getEntite(), 'dateJour' => $day, 'periode' => DemiJournee::from($period), 'role' => 'stagiaire'];
        if ($user = $access->getInscription()?->getStagiaire()) $criteria['utilisateur'] = $user;
        else $criteria['participantAccess'] = $access;
        return $em->getRepository(Emargement::class)->findOneBy($criteria);
    }

    private function isSigned(Emargement $record): bool
    {
        return $record->getSignedAt() !== null || (bool) $record->getSignaturePath() || (bool) $record->getSignatureDataUrl();
    }

    private function validSignature(string $data): bool
    {
        if (strlen($data) > 350000 || !str_starts_with($data, 'data:image/png;base64,')) return false;
        $binary = base64_decode(substr($data, 22), true);
        if ($binary === false || strlen($binary) < 80) return false;
        $image = @getimagesizefromstring($binary);
        if ($image === false || $image[2] !== IMAGETYPE_PNG || $image[0] < 20 || $image[1] < 20
            || $image[0] > 2048 || $image[1] > 1024) return false;
        $decoded = @imagecreatefromstring($binary);
        if ($decoded === false) return false;
        // Ignore transparent/white canvas pixels: an empty image is not a signature.
        $ink = 0;
        for ($y = 0; $y < $image[1] && $ink < 10; ++$y) {
            for ($x = 0; $x < $image[0] && $ink < 10; ++$x) {
                $pixel = imagecolorsforindex($decoded, imagecolorat($decoded, $x, $y));
                if ($pixel['alpha'] < 100 && min($pixel['red'], $pixel['green'], $pixel['blue']) < 230) ++$ink;
            }
        }
        imagedestroy($decoded);
        return $ink >= 10;
    }

    /** @return list<SatisfactionAssignment> */
    private function assignments(EntityManagerInterface $em, SessionParticipantAccess $access): array
    {
        $criteria = ['session' => $access->getSession(), 'entite' => $access->getEntite()];
        if ($user = $access->getInscription()?->getStagiaire()) $criteria['stagiaire'] = $user;
        else $criteria['participantAccess'] = $access;
        return $em->getRepository(SatisfactionAssignment::class)->findBy($criteria, ['id' => 'DESC']);
    }

    private function answers(array $raw): array
    {
        $answers = [];
        foreach ($raw as $key => $value) {
            if (!str_starts_with((string) $key, 'q_')) continue;
            if (is_iterable($value)) {
                $values = [];
                foreach ($value as $item) $values[] = is_object($item) && method_exists($item, 'getId') ? $item->getId() : (string) $item;
                $value = $values;
            } elseif (is_string($value) && is_numeric($value)) $value = (int) $value;
            $answers[(int) substr((string) $key, 2)] = $value;
        }
        return $answers;
    }

    private function thankYou(SessionParticipantAccess $access): Response
    {
        return $this->privateResponse($this->render('participant_qr/public_message.html.twig', [
            'access' => $access, 'session' => $access->getSession(), 'entite' => $access->getEntite(), 'success' => true,
            'title' => 'Merci pour votre appréciation', 'message' => 'Votre réponse a bien été enregistrée. Vous pouvez fermer cette page.',
        ]));
    }

    private function unavailable(string $message = 'Ce lien n’est plus disponible. Demandez un nouveau QR code à votre formateur.', ?SessionParticipantAccess $access = null, int $status = 404): Response
    {
        return $this->privateResponse($this->render('participant_qr/public_message.html.twig', [
            'access' => $access, 'session' => $access?->getSession(), 'entite' => $access?->getEntite(),
            'title' => 'Accès indisponible', 'message' => $message, 'success' => false,
        ], new Response(status: $status)));
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        return $response;
    }
}
