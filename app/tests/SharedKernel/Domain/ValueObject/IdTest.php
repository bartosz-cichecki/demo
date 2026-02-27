<?php

declare(strict_types=1);

namespace App\Tests\SharedKernel\Domain\ValueObject;

use App\SharedKernel\Domain\ValueObject\Id;
use PHPUnit\Framework\TestCase;

final class IdTest extends TestCase
{
    public function testCanBeCreatedFromValidUuid(): void
    {
        // Given: a valid UUID string
        $uuid = '550e8400-e29b-41d4-a716-446655440000';

        // When: creating Id
        $id = new Id($uuid);

        // Then: string representation matches
        $this->assertSame($uuid, (string) $id);
    }

    public function testNewGeneratesValidId(): void
    {
        // When: generating new Id
        $id = Id::new();

        // Then: it can be converted to string (valid UUID)
        $this->assertNotEmpty((string) $id);
    }

    public function testCannotBeCreatedFromEmptyString(): void
    {
        // Then: exception is thrown
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Id must be a valid UUID.');

        // When: creating with empty string
        new Id('');
    }

    public function testCannotBeCreatedFromInvalidUuid(): void
    {
        // Then: exception is thrown
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Id must be a valid UUID.');

        // When: creating with invalid string
        new Id('not-a-uuid');
    }

    public function testEqualsReturnsTrueForSameValue(): void
    {
        // Given: two Ids with same UUID
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id1 = new Id($uuid);
        $id2 = new Id($uuid);

        // Then: they are equal
        $this->assertTrue($id1->equals($id2));
    }

    public function testEqualsReturnsFalseForDifferentValue(): void
    {
        // Given: two different Ids
        $id1 = Id::new();
        $id2 = Id::new();

        // Then: they are not equal
        $this->assertFalse($id1->equals($id2));
    }
}
