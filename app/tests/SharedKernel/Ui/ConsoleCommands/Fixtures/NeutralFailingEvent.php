<?php

declare(strict_types=1);

namespace App\Tests\SharedKernel\Ui\ConsoleCommands\Fixtures;

use App\SharedKernel\Application\IntegrationEvent\IntegrationEvent;

final readonly class NeutralFailingEvent implements IntegrationEvent
{
    public function __construct(
        public string $subject,
    ) {
    }
}
