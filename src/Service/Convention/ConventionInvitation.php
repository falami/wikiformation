<?php

declare(strict_types=1);
namespace App\Service\Convention;
use App\Entity\{ConventionContrat, ConventionSignatureInvitation, Utilisateur};
use App\Service\Entreprise\EntreprisePortalContext;
use App\Service\Email\MailerManager;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\{QrCode, Writer\PngWriter};
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

final class ConventionInvitation
{
    public function __construct(private EntityManagerInterface $em, private ConventionDocument $documents,
        private UrlGeneratorInterface $router, private MailerManager $mailer, private Environment $twig) {}

    public function assertActive(ConventionSignatureInvitation $invitation): void
    {
        $c = $invitation->getConvention();
        if (!$invitation->isActive() || !$c->getPdfPath() || !$c->getEntreprise()
            || $invitation->getCompanyId() !== $c->getEntreprise()->getId() || $invitation->getTenantId() !== $c->getEntite()?->getId()
            || $c->getEntreprise()->getEntite()?->getId() !== $c->getEntite()?->getId()
            || $c->getSession()?->getEntite()?->getId() !== $c->getEntite()?->getId()
            || ($c->getDateSignatureEntreprise() && $c->getSignatureDataUrlEntreprise())
            || !EntreprisePortalContext::canRepresent($invitation->getInvitedBy(), $c->getEntreprise())
            || !hash_equals($invitation->getDocumentHash(), $this->documents->fingerprint($c))) {
            throw new \DomainException('Ce lien a expiré, a été remplacé ou le document a déjà été signé ou modifié. Demandez une nouvelle invitation à votre contact formation.');
        }
    }

    public function resolve(string $token): ?ConventionSignatureInvitation
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return null;
        $invitation = $this->em->getRepository(ConventionSignatureInvitation::class)->findOneBy(['tokenHash' => hash('sha256', $token)]);
        if (!$invitation) return null;
        try { $this->assertActive($invitation); } catch (\DomainException) { return null; }
        return $invitation;
    }

    public function send(ConventionContrat $c, Utilisateur $actor, string $email, string $name, string $position): void
    {
        $email = trim($email); $name = trim($name); $position = trim($position);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 180 || $name === '' || mb_strlen($name) > 160 || $position === '' || mb_strlen($position) > 120) {
            throw new \DomainException('Renseignez le nom, la fonction et une adresse e-mail valide pour le signataire.');
        }
        if (!EntreprisePortalContext::canRepresent($actor, $c->getEntreprise()) || !$c->getPdfPath()
            || ($c->getDateSignatureEntreprise() && $c->getSignatureDataUrlEntreprise())) throw new \DomainException('Cette convention ne peut pas être proposée à la signature.');
        $previous = $this->em->getRepository(ConventionSignatureInvitation::class)->findBy(['convention' => $c]);
        foreach ($previous as $old) if ($old->getCreatedAt() > new \DateTimeImmutable('-1 minute')) throw new \DomainException('Patientez une minute avant d’envoyer une nouvelle invitation.');
        $token = bin2hex(random_bytes(32));
        $invitation = new ConventionSignatureInvitation($c, $actor, hash('sha256', $token), $email, $name, $position, $this->documents->fingerprint($c), new \DateTimeImmutable('+48 hours'));
        $url = $this->router->generate('app_convention_invitation_review', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
        $qr = (new PngWriter())->write(new QrCode(data: $url, size: 300, margin: 16))->getString();
        $html = $this->twig->render('emails/convention_invitation.html.twig', ['invitation' => $invitation, 'c' => $c, 'url' => $url]);
        $this->em->persist($invitation);
        foreach ($previous as $old) $old->revoke();
        $this->em->flush();
        try {
            $this->mailer->sendParticipantReminder($c->getEntite(), $email, 'Votre signature est attendue · '.$c->getNumero(), $html,
                "Bonjour $name,\nVous êtes invité(e) à consulter et signer la convention ".$c->getNumero()." pour ".$c->getEntreprise()->getRaisonSociale().".\n$url\nCe lien personnel est valable 48 heures et devient inutilisable après signature. Ne le transférez pas.", ['convention-qr' => $qr]);
        } catch (\Throwable $error) {
            $invitation->revoke(); $this->em->flush();
            throw new \DomainException('L’invitation n’a pas pu être envoyée. Réessayez dans une minute.', 0, $error);
        }
    }
}
