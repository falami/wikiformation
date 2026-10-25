<?php
declare(strict_types=1);
namespace App\Security;

use App\Entity\{Entite, Utilisateur, Formation, FormationContentNode, ContentBlock};
use App\Entity\Elearning\{ElearningCourse, ElearningNode, ElearningBlock};
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Reject cross-tenant route objects before any administration action can read or mutate them. */
#[AsEventListener(event: 'kernel.controller_arguments', priority: 256)]
final class AdminResourceScope
{
    public function __invoke(ControllerArgumentsEvent $event): void
    {
        $controller=$event->getController();
        if (!is_array($controller) || !is_object($controller[0]) || !str_starts_with($controller[0]::class, 'App\\Controller\\Administrateur\\')) return;
        $arguments=$event->getArguments();$tenant=null;$formation=null;$course=null;
        foreach ($arguments as $argument) {
            if ($argument instanceof Entite) $tenant=$argument;
            if ($argument instanceof Formation) $formation=$argument;
            if ($argument instanceof ElearningCourse) $course=$argument;
        }
        if (!$tenant) return;
        foreach ($arguments as $argument) {
            // User accounts span several entities; their dedicated membership guards apply.
            if (!is_object($argument) || $argument instanceof Entite || $argument instanceof Utilisateur) continue;
            if (str_starts_with($argument::class,'App\\Entity\\') || str_contains($argument::class,'\\__CG__\\App\\Entity\\')) {
                if (method_exists($argument,'getEntite') && $argument->getEntite()?->getId() !== $tenant->getId()) throw new NotFoundHttpException();
                foreach (['getFormation','getEngin','getNode'] as $getter) {
                    if (method_exists($argument,$getter) && ($parent=$argument->$getter()) && method_exists($parent,'getEntite') && $parent->getEntite()?->getId() !== $tenant->getId()) throw new NotFoundHttpException();
                }
            }
            $node=$argument instanceof ContentBlock ? $argument->getNode() : ($argument instanceof FormationContentNode ? $argument : null);
            if ($node && $formation && $node->getFormation()?->getId() !== $formation->getId()) throw new NotFoundHttpException();
            $node=$argument instanceof ElearningBlock ? $argument->getNode() : ($argument instanceof ElearningNode ? $argument : null);
            if ($node && $course && $node->getCourse()?->getId() !== $course->getId()) throw new NotFoundHttpException();
        }
    }
}
