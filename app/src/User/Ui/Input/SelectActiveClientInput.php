<?php

declare(strict_types=1);

namespace App\User\Ui\Input;

use App\SharedKernel\Ui\Input\Input;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Collection;

final readonly class SelectActiveClientInput implements Input
{
    public function __construct(
        public string $clientId,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function create(array $payload): Input
    {
        /** @var string $clientId */
        $clientId = $payload['clientId'];

        return new self($clientId);
    }

    public static function getSchema(): Collection
    {
        return new Collection([
            'clientId' => [
                new Assert\NotBlank(message: 'Client id is required.'),
                new Assert\Type('string'),
                new Assert\Uuid(message: 'Client id must be a valid UUID.'),
            ],
        ]);
    }
}
