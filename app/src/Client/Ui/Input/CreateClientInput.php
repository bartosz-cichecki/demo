<?php

declare(strict_types=1);

namespace App\Client\Ui\Input;

use App\SharedKernel\Ui\Input\Input;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Collection;

final readonly class CreateClientInput implements Input
{
    public function __construct(
        public string $name,
        public ?string $description,
        public string $adminEmail,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function create(array $payload): Input
    {
        /** @var string $name */
        $name = $payload['name'];
        /** @var string|null $description */
        $description = $payload['description'] ?? null;

        /** @var string $adminEmail */
        $adminEmail = $payload['adminEmail'];

        return new self(
            name: $name,
            description: $description,
            adminEmail: $adminEmail,
        );
    }

    public static function getSchema(): Collection
    {
        return new Collection([
            'adminEmail' => [
                new Assert\NotBlank(message: 'Admin email is required.'),
                new Assert\Type('string'),
                new Assert\Email(message: 'Email is not valid.'),
                new Assert\Length(max: 255, maxMessage: 'Email must not exceed 255 characters.'),
            ],
            'name' => [
                new Assert\NotBlank(message: 'Name is required.'),
                new Assert\Type('string'),
                new Assert\Length(min: 2, max: 120, minMessage: 'Name must be at least 2 characters.', maxMessage: 'Name must not exceed 120 characters.'),
            ],
            'description' => new Assert\Optional([
                new Assert\Type('string'),
                new Assert\Length(max: 1000, maxMessage: 'Description must not exceed 1000 characters.'),
            ]),
        ]);
    }
}
