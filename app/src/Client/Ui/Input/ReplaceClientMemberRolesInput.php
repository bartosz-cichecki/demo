<?php

declare(strict_types=1);

namespace App\Client\Ui\Input;

use App\Client\Domain\ClientMember\ClientMember;
use App\SharedKernel\Ui\Input\Input;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Collection;

final readonly class ReplaceClientMemberRolesInput implements Input
{
    /**
     * @param array<string> $roles
     */
    public function __construct(
        public array $roles,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function create(array $payload): Input
    {
        /** @var array<string> $roles */
        $roles = $payload['roles'];

        return new self(
            roles: $roles,
        );
    }

    public static function getSchema(): Collection
    {
        return new Collection([
            'roles' => [
                new Assert\NotBlank(message: 'Roles are required.'),
                new Assert\Type('array'),
                new Assert\Count(min: 1, minMessage: 'At least one role is required.'),
                new Assert\All([
                    new Assert\Type('string'),
                    new Assert\Choice(
                        choices: ClientMember::ALLOWED_ROLES,
                        message: 'Invalid role: {{ value }}.',
                    ),
                ]),
            ],
        ]);
    }
}
