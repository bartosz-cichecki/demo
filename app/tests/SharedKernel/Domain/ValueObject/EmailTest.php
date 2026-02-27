<?php

declare(strict_types=1);

namespace App\Tests\SharedKernel\Domain\ValueObject;

use App\SharedKernel\Domain\ValueObject\Email;
use PHPUnit\Framework\TestCase;

final class EmailTest extends TestCase
{
    public function testFromStringNormalizesTrimAndLowercase(): void
    {
        $email = Email::fromString('  John.Doe@Example.COM  ');

        $this->assertSame('john.doe@example.com', (string) $email);
    }

    public function testFromStringThrowsForInvalidEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Email must be a valid address.');

        Email::fromString('not-an-email');
    }

    public function testEquals(): void
    {
        $emailA = Email::fromString('John@example.com');
        $emailB = Email::fromString(' john@example.com ');
        $emailC = Email::fromString('jane@example.com');

        $this->assertTrue($emailA->equals($emailB));
        $this->assertFalse($emailA->equals($emailC));
    }
}
