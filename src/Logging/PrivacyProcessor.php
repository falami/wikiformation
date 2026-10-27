<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Monolog\Attribute\AsMonologProcessor;

/** Minimizes production diagnostic logs; infrastructure logs need their own policy. */
#[When(env: 'prod')]
#[AsMonologProcessor]
final class PrivacyProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->text($record->message),
            context: $this->clean($record->context),
            extra: $this->clean($record->extra),
        );
    }

    private function clean(array $data, int $depth = 0): array
    {
        if ($depth > 6) return ['redacted' => true];
        foreach ($data as $key => $value) {
            if (preg_match('/password|passwd|secret|token|authorization|cookie|signature|dsn|credential|payload|request_body|response_body|params|parameters|email|recipient|address|phone|ip_address|user_agent/i', (string) $key)) {
                $data[$key] = '[redacted]';
            } elseif ($value instanceof \Throwable) {
                // Exception messages can contain SQL parameters, credentials and PII.
                $data[$key] = ['class' => $value::class, 'file' => $value->getFile(), 'line' => $value->getLine(),
                    'trace' => array_map(static fn(array $frame): array => array_intersect_key($frame, array_flip(['file', 'line', 'class', 'function'])), array_slice($value->getTrace(), 0, 20))];
            } elseif (is_array($value)) {
                $data[$key] = $this->clean($value, $depth + 1);
            } elseif (is_string($value)) {
                $data[$key] = $this->text($value);
            } elseif (is_object($value)) {
                $data[$key] = ['class' => $value::class];
            }
        }
        return $data;
    }

    private function text(string $value): string
    {
        // Symfony exception messages repeat the raw exception separately from context.
        if (str_starts_with($value, 'Uncaught PHP Exception ')) return 'Uncaught PHP exception (see diagnostic context).';
        $value = preg_replace('#\b(?:mysql|postgres(?:ql)?|smtp|smtps|redis)://[^\s]+#i', '[redacted connection]', $value);
        $value = preg_replace('#https?://[^\s"<>]+#i', '[redacted URL]', $value);
        $value = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted email]', $value);
        $value = preg_replace('/\b(?:sk|rk|pk)_(?:live|test)_[A-Za-z0-9]+\b/', '[redacted key]', $value);
        $value = preg_replace('/\b[a-f0-9]{32,}\b/i', '[redacted token]', $value);
        $value = preg_replace('/\b(?:Bearer|Basic)\s+[A-Za-z0-9+\/=._-]+/i', '[redacted authorization]', $value);
        return mb_substr((string) $value, 0, 2000);
    }
}
