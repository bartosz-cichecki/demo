<?php

declare(strict_types=1);

namespace App\Tests\User\Infrastructure\Security;

use App\User\Infrastructure\OtpChallenge\HmacValueHasherService;
use PHPUnit\Framework\TestCase;

final class HmacValueHasherTest extends TestCase
{
    public function testHashReturnsDeterministicResult(): void
    {
        $hasher = new HmacValueHasherService('test-secret');

        $hash1 = $hasher->hash('127.0.0.1');
        $hash2 = $hasher->hash('127.0.0.1');

        $this->assertSame($hash1, $hash2);
    }

    public function testHashReturnsDifferentResultForDifferentInput(): void
    {
        $hasher = new HmacValueHasherService('test-secret');

        $this->assertNotSame(
            $hasher->hash('127.0.0.1'),
            $hasher->hash('192.168.1.1'),
        );
    }

    public function testHashReturnsDifferentResultForDifferentSecret(): void
    {
        $hasher1 = new HmacValueHasherService('secret-a');
        $hasher2 = new HmacValueHasherService('secret-b');

        $this->assertNotSame(
            $hasher1->hash('127.0.0.1'),
            $hasher2->hash('127.0.0.1'),
        );
    }

    public function testHashMatchesExpectedHmacSha256(): void
    {
        $hasher = new HmacValueHasherService('test-secret');

        $expected = hash_hmac('sha256', '127.0.0.1', 'test-secret');

        $this->assertSame($expected, $hasher->hash('127.0.0.1'));
    }
}
