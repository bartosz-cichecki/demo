<?php

declare(strict_types=1);

namespace App\Tests\SharedKernel\Ui\ConsoleCommands\Fixtures;

final class FailingOnceIntegrationEventSubscriber
{
    public static int $attempts = 0;

    public static function reset(): void
    {
        self::$attempts = 0;
    }

    public function onNeutralFailing(NeutralFailingEvent $event): void
    {
        ++self::$attempts;

        if (1 === self::$attempts) {
            throw new \RuntimeException('Planned handler failure.');
        }
    }
}
