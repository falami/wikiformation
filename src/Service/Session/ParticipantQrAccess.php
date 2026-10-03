<?php

declare(strict_types=1);

namespace App\Service\Session;

use App\Entity\{ConventionContrat, Inscription, Session, SessionParticipantAccess};
use App\Enum\{StatusInscription, StatusSession};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** QR capabilities are scoped to one participant, one session and one purpose. */
final class ParticipantQrAccess
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.secret%')] private readonly string $signingSecret,
    ) {}

    /** Call only after verifying permission to view this session's complete participant roster.
     * @return list<SessionParticipantAccess>
     */
    public function roster(Session $session): array
    {
        $this->requireSession($session);
        return $this->em->wrapInTransaction(function () use ($session): array {
            $this->em->lock($session, LockMode::PESSIMISTIC_WRITE);
            return $this->synchronizeRoster($session);
        });
    }

    private function synchronizeRoster(Session $session): array
    {
        $existing = [];
        foreach ($this->em->getRepository(SessionParticipantAccess::class)->createQueryBuilder('a')->where('a.session = :session')->setParameter('session', $session)->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getResult() as $access) {
            $existing[$access->getSourceKey()] = $access;
        }
        $roster = [];
        foreach ($this->em->getRepository(Inscription::class)->findBy(['session' => $session, 'entite' => $session->getEntite()], ['id' => 'ASC']) as $inscription) {
            if (!$this->validInscription($inscription, $session)) continue;
            $key = $this->inscriptionKey($inscription);
            $access = $existing[$key] ?? $this->create($session, $key);
            $access->setInscription($inscription)->setDisplayName($this->learnerName($inscription));
            $this->activate($access, $session);
            $roster[] = $access;
            unset($existing[$key]);
        }
        foreach ($this->em->getRepository(ConventionContrat::class)->findBy(['session' => $session, 'entite' => $session->getEntite()], ['id' => 'ASC']) as $convention) {
            foreach ($this->guestSources($convention) as $key => $name) {
                $access = $existing[$key] ?? $this->create($session, $key);
                $access->setConvention($convention)->setDisplayName($name);
                $this->activate($access, $session);
                $roster[] = $access;
                unset($existing[$key]);
            }
        }
        // Never remove signed evidence when a participant is renamed or removed.
        foreach ($existing as $access) $access->setActive(false);
        $this->em->flush();
        return $roster;
    }

    /** Allows a participant's dashboard to obtain only their own access. */
    public function forInscription(Inscription $inscription): SessionParticipantAccess
    {
        $session = $inscription->getSession();
        if (!$session) throw new \InvalidArgumentException('Inscription sans session.');
        $this->requireSession($session);
        return $this->em->wrapInTransaction(function () use ($inscription, $session): SessionParticipantAccess {
            $this->em->lock($session, LockMode::PESSIMISTIC_WRITE);
            if (!$this->validInscription($inscription, $session)) {
                throw new \InvalidArgumentException('Inscription inactive ou étrangère à cette session.');
            }
            $key = $this->inscriptionKey($inscription);
            $access = $this->em->getRepository(SessionParticipantAccess::class)->createQueryBuilder('a')
                ->where('a.session = :session')->andWhere('a.sourceKey = :key')
                ->setParameter('session', $session)->setParameter('key', $key)->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult() ?? $this->create($session, $key);
            $access->setInscription($inscription)->setDisplayName($this->learnerName($inscription));
            $this->activate($access, $session);
            return $access;
        });
    }

    public function token(SessionParticipantAccess $access, string $purpose): string
    {
        $this->requirePurpose($purpose);
        $payload = $access->getPublicId() . '.' . $access->getVersion() . '.' . $access->getExpiresAt()->getTimestamp();
        return $payload . '.' . hash_hmac('sha256', $purpose . ':' . $payload, $this->signingSecret);
    }

    public function resolve(string $token, string $purpose): ?SessionParticipantAccess
    {
        $this->requirePurpose($purpose);
        if (!preg_match('/^([a-f0-9]{48})\.([1-9][0-9]{0,8})\.([0-9]{1,12})\.([a-f0-9]{64})$/D', $token, $parts)) return null;
        [, $publicId, $version, $expiry, $mac] = $parts;
        $payload = $publicId . '.' . $version . '.' . $expiry;
        if (!hash_equals(hash_hmac('sha256', $purpose . ':' . $payload, $this->signingSecret), $mac) || (int) $expiry <= time()) return null;
        $access = $this->em->getRepository(SessionParticipantAccess::class)->findOneBy(['publicId' => $publicId]);
        if (!$access || !$access->isActive() || $access->getVersion() !== (int) $version || $access->getExpiresAt()->getTimestamp() < (int) $expiry) return null;
        $session = $access->getSession();
        if (!$session || $session->getStatus() === StatusSession::CANCELED || !$session->getEntite() || $session->getEntite()->isActive() === false || $access->getEntite()?->getId() !== $session->getEntite()->getId()) return null;
        if ($session->getDateFin() && $session->getDateFin()->modify('+90 days')->getTimestamp() <= time()) return null;
        // The source is checked again on every anonymous visit: a convention edit revokes links immediately.
        if ($access->isGuest()) {
            $convention = $access->getConvention();
            if (!$convention || $convention->getSession()?->getId() !== $session->getId() || $convention->getEntite()?->getId() !== $session->getEntite()->getId()) return null;
            if (!array_key_exists($access->getSourceKey(), $this->guestSources($convention))) return null;
        } else {
            $inscription = $access->getInscription();
            if (!$inscription || !$this->validInscription($inscription, $session) || $access->getSourceKey() !== $this->inscriptionKey($inscription)) return null;
        }
        return $access;
    }

    private function create(Session $session, string $key): SessionParticipantAccess
    {
        $access = (new SessionParticipantAccess())->setSession($session)->setEntite($session->getEntite())->setSourceKey($key);
        $this->em->persist($access);
        return $access;
    }

    private function activate(SessionParticipantAccess $access, Session $session): void
    {
        $access->setActive($session->getStatus() !== StatusSession::CANCELED);
        if ($session->getDateFin()) $access->setExpiresAt($session->getDateFin()->modify('+90 days'));
    }

    private function requireSession(Session $session): void
    {
        if (!$session->getId() || !$session->getEntite()?->getId()) throw new \InvalidArgumentException('La session doit être enregistrée.');
    }

    private function requirePurpose(string $purpose): void
    {
        if (!in_array($purpose, ['attendance', 'satisfaction'], true)) throw new \InvalidArgumentException('Usage du QR code inconnu.');
    }

    private function validInscription(Inscription $inscription, Session $session): bool
    {
        return $inscription->getId() !== null && $inscription->getStagiaire() !== null
            && !in_array($inscription->getStatus(), [StatusInscription::ANNULE, StatusInscription::ABSENT], true)
            && $inscription->getSession()?->getId() === $session->getId()
            && $inscription->getEntite()?->getId() === $session->getEntite()?->getId();
    }

    private function inscriptionKey(Inscription $inscription): string
    {
        return 'inscription:' . $inscription->getId() . ':' . $inscription->getStagiaire()?->getId();
    }

    private function learnerName(Inscription $inscription): string
    {
        $user = $inscription->getStagiaire();
        return trim($user->getPrenom() . ' ' . $user->getNom());
    }

    /** Occurrences keep two homonyms distinct without using mutable line numbers. */
    private function guestSources(ConventionContrat $convention): array
    {
        $sources = $occurrences = [];
        foreach ($convention->getParticipantsLibresListe() as $name) {
            $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)), 'UTF-8');
            $occurrence = $occurrences[$normalized] = ($occurrences[$normalized] ?? 0) + 1;
            $key = 'guest:' . $convention->getId() . ':' . hash('sha256', $normalized . ':' . $occurrence);
            $sources[$key] = $name;
        }
        return $sources;
    }
}
