<?php

declare(strict_types=1);

namespace App\User\Ui\Input;

use App\SharedKernel\Ui\Input\Input;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Collection;

final readonly class RequestOtpInput implements Input
{
    public function __construct(
        public string $email,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function create(array $payload): Input
    {
        /** @var string $email */
        $email = $payload['email'];

        return new self($email);
    }

    public static function getSchema(): Collection
    {
        return new Collection([
            'email' => [
                new Assert\NotBlank(message: 'Email is required.'),
                new Assert\Type('string'),
                new Assert\Length(max: 255, maxMessage: 'Email must not exceed 255 characters.'),
            ],
        ]);
    }
}
