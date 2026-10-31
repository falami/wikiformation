<?php

declare(strict_types=1);
namespace App\Tests\Unit;

use App\Entity\{EmailLog, Entite, Utilisateur};
use App\Service\Email\{EmailHistoryRecorder, MailerManager};
use Doctrine\ORM\{EntityManagerInterface, EntityRepository, UnitOfWork};
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\{Environment, Loader\ArrayLoader};

final class EmailHistorySnapshotTest extends TestCase
{
    private function recorder(Entite $entity, ?EmailLog $pending = null): array
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn($pending ? [$pending] : []);
        $em->method('getUnitOfWork')->willReturn($uow);
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);
        $em->method('getRepository')->willReturn($repo);
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($entity->getCreateur());
        return [new EmailHistoryRecorder($em, $security), $em];
    }

    public function testRenderedBodyIsTheBodyHandedToTransport(): void
    {
        $entity = (new Entite())->setCreateur(new Utilisateur());
        [$recorder, $em] = $this->recorder($entity);
        $saved = null;
        $em->expects(self::once())->method('persist')->willReturnCallback(function ($log) use (&$saved) { $saved = $log; });
        $mail = $this->createMock(MailerInterface::class);
        $mail->expects(self::once())->method('send')->willReturnCallback(function ($email) use (&$saved) {
            self::assertSame('<p>Bonjour Camille</p>', $email->getHtmlBody());
            self::assertSame($email->getHtmlBody(), $saved->getBodyHtmlSnapshot());
        });
        $manager = new MailerManager($mail, new Environment(new ArrayLoader(['test' => '<p>Bonjour {{ name }}</p>'])), $this->createMock(UrlGeneratorInterface::class), $recorder);
        $manager->sendMailContext('sender@example.test', 'recipient@example.test', 'Rappel', 'test', ['entite'=>$entity, 'name'=>'Camille']);
        self::assertSame('SENT', $saved->getStatus());
    }

    public function testPendingBusinessLogIsReusedAndTextIsEscaped(): void
    {
        $entity = (new Entite())->setCreateur(new Utilisateur());
        $pending = (new EmailLog())->setEntite($entity)->setToEmail('recipient@example.test')->setSubject('Rappel')->setStatus('PENDING');
        [$recorder, $em] = $this->recorder($entity, $pending);
        $em->expects(self::never())->method('persist');
        $email = (new Email())->to('recipient@example.test')->subject('Rappel')->text('<script>test</script>');
        self::assertSame($pending, $recorder->prepare($entity, $email));
        self::assertStringContainsString('&lt;script&gt;', $pending->getBodyHtmlSnapshot());
        $recorder->prepare($entity, $email, false);
        self::assertNull($pending->getBodyHtmlSnapshot());
    }

    public function testAccountPasswordIsNotArchived(): void
    {
        $entity = (new Entite())->setCreateur(new Utilisateur());
        [$recorder, $em] = $this->recorder($entity);
        $saved = null;
        $em->method('persist')->willReturnCallback(function ($log) use (&$saved) { $saved = $log; });
        $mail = $this->createMock(MailerInterface::class);
        $mail->expects(self::once())->method('send');
        $manager = new MailerManager($mail, new Environment(new ArrayLoader(['account' => '{{ plainPassword }}'])), $this->createMock(UrlGeneratorInterface::class), $recorder);
        $manager->sendMailContext('sender@example.test', 'recipient@example.test', 'Compte', 'account', ['entite'=>$entity, 'plainPassword'=>'secret']);
        self::assertNull($saved->getBodyHtmlSnapshot());
    }
}
