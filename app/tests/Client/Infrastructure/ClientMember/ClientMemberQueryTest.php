<?php

declare(strict_types=1);

namespace App\Tests\Client\Infrastructure\ClientMember;

use App\Client\Infrastructure\ClientMember\ClientMemberQuery;
use App\SharedKernel\Domain\ValueObject\Id;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientMemberQueryTest extends TestCase
{
    /**
     * @param list<string> $roles
     */
    #[DataProvider('membershipStates')]
    public function testItProjectsActivityAndAdminRoleIndependently(
        string $status,
        array $roles,
        bool $isActive,
        bool $isAdmin,
    ): void {
        $id = Id::new();
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAssociative')->willReturn([
            'id' => (string) $id,
            'client_id' => (string) Id::new(),
            'user_id' => (string) Id::new(),
            'roles_json' => json_encode($roles, \JSON_THROW_ON_ERROR),
            'status' => $status,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $membership = (new ClientMemberQuery($connection))->findById($id);

        self::assertNotNull($membership);
        self::assertSame($isActive, $membership->isActive);
        self::assertSame($isAdmin, $membership->isAdmin);
        self::assertSame($status, $membership->status);
        self::assertSame($roles, $membership->roles);
    }

    /**
     * @return iterable<string, array{string, list<string>, bool, bool}>
     */
    public static function membershipStates(): iterable
    {
        yield 'active admin' => ['active', ['admin'], true, true];
        yield 'active user' => ['active', ['user'], true, false];
        yield 'suspended admin' => ['suspended', ['admin'], false, true];
        yield 'suspended user' => ['suspended', ['user'], false, false];
        yield 'multiple roles' => ['active', ['user', 'admin'], true, true];
        yield 'no roles' => ['active', [], true, false];
        yield 'unknown status' => ['unknown', ['admin'], false, true];
        yield 'unknown role' => ['active', ['unknown'], true, false];
    }
}
