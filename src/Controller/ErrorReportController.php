<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Support\ErrorReport;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class ErrorReportController extends AbstractController
{
    #[Route('/support/error-report', name: 'app_error_report', methods: ['POST'])]
    public function report(Request $request, ErrorReport $reports, #[Autowire(service: 'limiter.error_reports')] RateLimiterFactory $limiter): Response
    {
        $result = 'limited';
        if ($limiter->create(hash('sha256', $request->getClientIp() ?? 'unknown'))->consume()->isAccepted()) {
            try {
                $result = $reports->send($request->request->getString('token'));
            } catch (\Throwable) {
                $result = 'failed';
            }
        }
        $response = $this->render('error/report.html.twig', ['result' => $result]);
        $response->setStatusCode(match ($result) { 'sent' => 200, 'expired' => 410, 'limited' => 429, default => 503 });
        $response->headers->set('Cache-Control', 'no-store, private');
        return $response;
    }
}
