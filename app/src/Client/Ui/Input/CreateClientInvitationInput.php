<?php

declare(strict_types=1);

namespace App\Client\Ui\Input;

use App\Client\Domain\ClientMember\ClientMember;
use App\SharedKernel\Ui\Input\Input;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Collection;

final readonly class CreateClientInvitationInput implements Input
{
    public function __construct(
        public string $email,
        public string $role,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function create(array $payload): Input
    {
        /** @var string $email */
        $email = $payload['email'];
        /** @var string $role */
        $role = $payload['role'];

        return new self(
            email: $email,
            role: $role,
        );
    }

    public static function getSchema(): Collection
    {
        return new Collection([
            'email' => [
                new Assert\NotBlank(message: 'Email is required.'),
                new Assert\Type('string'),
                new Assert\Email(message: 'Email is not valid.'),
                new Assert\Length(max: 255, maxMessage: 'Email must not exceed 255 characters.'),
            ],
            'role' => [
                new Assert\NotBlank(message: 'Role is required.'),
                new Assert\Type('string'),
                new Assert\Choice(
                    choices: ClientMember::ALLOWED_ROLES,
                    message: 'Invalid role: {{ value }}.',
                ),
            ],
        ]);
    }
}
