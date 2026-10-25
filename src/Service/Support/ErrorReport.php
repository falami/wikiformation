<?php

declare(strict_types=1);

namespace App\Service\Support;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

final class ErrorReport
{
    public function __construct(
        #[Autowire(service: 'cache.app')] private CacheItemPoolInterface $cache,
        private TransportInterface $transport,
        #[Autowire(service: 'monolog.logger.error_report')] private LoggerInterface $logger,
    ) {}

    public function capture(\Throwable $exception, Request $request, int $status): array
    {
        $token = bin2hex(random_bytes(32));
        $reference = 'WK-'.strtoupper(bin2hex(random_bytes(8)));
        $report = [
            'reference' => $reference,
            'status' => $status,
            'time' => gmdate('Y-m-d H:i:s').' UTC',
            'route' => $request->attributes->get('_route', '(route inconnue)'),
            'method' => $request->getMethod(),
            'type' => $exception::class,
            'sent' => false,
        ];
        // No URL parameters, request body, cookies or exception message in the email.
        $item = $this->cache->getItem('error_report.'.hash('sha256', $token));
        $item->set($report)->expiresAfter(3600);
        if (!$this->cache->save($item)) throw new \RuntimeException('Cannot store error report');
        $this->logger->error('Incident {reference}', $report + ['exception' => $exception]);
        return ['reference' => $reference, 'token' => $token];
    }

    public function send(string $token): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return 'expired';
        $item = $this->cache->getItem('error_report.'.hash('sha256', $token));
        if (!$item->isHit()) return 'expired';
        $report = $item->get();
        if ($report['sent']) return 'sent';
        $body = "Un utilisateur a signalé une erreur sur WikiFormation.\n\n";
        foreach (['reference' => 'Référence', 'status' => 'Code HTTP', 'time' => 'Date', 'route' => 'Route', 'method' => 'Méthode', 'type' => 'Type'] as $key => $label) {
            $body .= $label.' : '.$report[$key]."\n";
        }
        $body .= "\nRechercher cette référence dans les logs du canal error_report pour retrouver l’exception et sa trace.\nAucun contenu de formulaire, cookie ou paramètre d’URL n’est inclus dans cet e-mail.";
        // Direct transport: success is shown only after the transport accepts the message.
        $this->transport->send((new Email())->from('contact@wikiformation.fr')->to('contact@wikiformation.fr')
            ->subject('[WikiFormation] Erreur '.$report['status'].' — '.$report['reference'])->text($body));
        $report['sent'] = true;
        $item->set($report);
        $this->cache->save($item);
        return 'sent';
    }
}
