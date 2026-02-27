<?php

declare(strict_types=1);

namespace App\SharedKernel\Ui\Http\Logging;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class AbuseLogger
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function warning(Request $request, string $reasonCode, array $extra = []): void
    {
        $routeName = $request->attributes->get('_route');
        $base = [
            'reason_code' => $reasonCode,
            'route_name' => \is_string($routeName) ? $routeName : 'unknown',
            'method' => $request->getMethod(),
            'ip' => $request->getClientIp() ?? 'unknown',
        ];

        $this->logger->warning('abuse_event', array_merge($extra, $base));
    }

    public function tokenHash8(string $rawToken): string
    {
        return substr(hash('sha256', $rawToken), 0, 8);
    }

    public function hash8(string $hash): string
    {
        return substr($hash, 0, 8);
    }
}
