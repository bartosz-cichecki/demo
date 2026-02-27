<?php

declare(strict_types=1);

namespace App\User\Infrastructure\OtpChallenge;

use App\User\Application\OtpChallenge\Service\ValueHasherServiceInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class HmacValueHasherService implements ValueHasherServiceInterface
{
    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    public function hash(string $value): string
    {
        return hash_hmac('sha256', $value, $this->secret);
    }
}
