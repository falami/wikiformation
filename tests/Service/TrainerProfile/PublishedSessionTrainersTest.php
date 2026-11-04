<?php

declare(strict_types=1);

namespace App\Tests\Service\TrainerProfile;

use App\Entity\{Entite, Formateur, Session, TrainerCv};
use App\Service\TrainerProfile\PublishedSessionTrainers;
use Doctrine\ORM\{EntityManagerInterface, EntityRepository};
use PHPUnit\Framework\TestCase;

final class PublishedSessionTrainersTest extends TestCase
{
    public function testOnlyPublishedProfilesOfSessionTrainersAreReturned(): void
    {
        $tenant = $this->createMock(Entite::class);
        $tenant->method('getId')->willReturn(1);
        $otherTenant = $this->createMock(Entite::class);
        $otherTenant->method('getId')->willReturn(2);
        $trainer = $this->createMock(Formateur::class);
        $trainer->method('getEntite')->willReturn($tenant);
        $foreignTrainer = $this->createMock(Formateur::class);
        $foreignTrainer->method('getEntite')->willReturn($otherTenant);
        $session = $this->createMock(Session::class);
        $session->method('getEntite')->willReturn($tenant);
        $session->method('getFormateursEffectifs')->willReturn([$trainer, $foreignTrainer]);
        $cv = new TrainerCv();
        $cv->formateur = $trainer;
        $cv->content = ['headline' => 'Brouillon complété'];
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::exactly(3))->method('findBy')->with(['formateur' => [$trainer]])->willReturn([$cv]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(TrainerCv::class)->willReturn($repository);
        $service = new PublishedSessionTrainers($em);
        self::assertSame([], $service->forSession($session));
        $cv->published = ['headline' => 'Profil publié'];
        self::assertSame([['trainer' => $trainer, 'cv' => $cv]], $service->forSession($session));
        $cv->published = null;
        self::assertSame([], $service->forSession($session));
    }

    public function testNoTrainerDoesNotQueryProfiles(): void
    {
        $session = $this->createMock(Session::class);
        $session->method('getFormateursEffectifs')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');
        self::assertSame([], (new PublishedSessionTrainers($em))->forSession($session));
    }
}
