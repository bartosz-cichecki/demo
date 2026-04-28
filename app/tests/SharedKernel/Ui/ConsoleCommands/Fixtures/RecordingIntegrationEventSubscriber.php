<?php

declare(strict_types=1);

namespace App\Tests\SharedKernel\Ui\ConsoleCommands\Fixtures;

final class RecordingIntegrationEventSubscriber
{
    /**
     * @var list<string>
     */
    public static array $handledSubjects = [];

    public static function reset(): void
    {
        self::$handledSubjects = [];
    }

    public function onNeutralHandled(NeutralHandledEvent $event): void
    {
        self::$handledSubjects[] = $event->subject;
    }
}
