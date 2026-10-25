<?php

declare(strict_types=1);
namespace App\EventSubscriber;

use App\Exception\BillingQuotaExceededException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\{JsonResponse, Response};
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Twig\Environment;

#[AsEventListener(event: 'kernel.exception', priority: 128)]
final class BillingQuotaResponseSubscriber
{
    public function __construct(private Environment $twig) {}
    public function __invoke(ExceptionEvent $event): void
    {
        $error = $event->getThrowable();
        if (!$error instanceof BillingQuotaExceededException) return;
        $request = $event->getRequest();
        if ($request->isXmlHttpRequest() || str_contains($request->headers->get('Accept', ''), 'application/json')) {
            $response = new JsonResponse(['ok'=>false, 'success'=>false, 'error'=>$error->getMessage(), 'message'=>$error->getMessage(), 'limitReached'=>true, 'quota'=>$error->getQuotaKey(), 'current'=>$error->getCurrent(), 'limit'=>$error->getLimit()], 409);
        } else {
            $response = new Response($this->twig->render('error/quota.html.twig', ['message'=>$error->getMessage()]), 409);
        }
        $response->headers->set('Cache-Control', 'no-store, private');
        $event->allowCustomResponseCode();
        $event->setResponse($response);
    }
}
