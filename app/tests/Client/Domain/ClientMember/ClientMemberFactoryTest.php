<?php

declare(strict_types=1);

namespace App\Tests\Client\Domain\ClientMember;

use App\Client\Domain\ClientMember\Event\ClientMemberCreated;
use App\Client\Domain\ClientMember\Factory\ClientMemberFactory;
use App\Client\Domain\ClientMember\Repository\Exception\ClientMemberAlreadyExistsException;
use App\SharedKernel\Domain\Event\InMemoryDomainEventsCollector;
use App\SharedKernel\Domain\ValueObject\Id;
use PHPUnit\Framework\TestCase;

final class ClientMemberFactoryTest extends TestCase
{
    public function testCreatesMemberWhenMembershipIsAbsent(): void
    {
        $collector = new InMemoryDomainEventsCollector();
        $outside = new FakeClientMemberOutside($collector);
        $id = Id::new();
        $clientId = Id::new();
        $userId = Id::new();

        (new ClientMemberFactory($outside))->create($id, $clientId, $userId, ['user']);

        self::assertSame([[$clientId, $userId]], $outside->membershipLookups);
        $events = $collector->pull();
        self::assertCount(1, $events);
        self::assertInstanceOf(ClientMemberCreated::class, $events[0]);
        self::assertEquals($id, $events[0]->clientMemberId);
        self::assertEquals($clientId, $events[0]->clientId);
        self::assertEquals($userId, $events[0]->userId);
        self::assertSame(['user'], $events[0]->roles);
        self::assertEquals($outside->now(), $events[0]->occurredAt);
    }

    public function testRejectsDuplicateBeforeConstructionAndRecordingEvent(): void
    {
        $collector = new InMemoryDomainEventsCollector();
        $outside = new FakeClientMemberOutside($collector, membershipExists: true);
        $clientId = Id::new();
        $userId = Id::new();

        try {
            // Invalid roles would throw if the member constructor were reached.
            (new ClientMemberFactory($outside))->create(Id::new(), $clientId, $userId, ['invalid']);
            self::fail('Expected duplicate membership to be rejected.');
        } catch (ClientMemberAlreadyExistsException $exception) {
            self::assertSame(
                (new ClientMemberAlreadyExistsException($clientId, $userId))->getMessage(),
                $exception->getMessage(),
            );
        }

        self::assertSame([[$clientId, $userId]], $outside->membershipLookups);
        self::assertSame([], $collector->pull());
    }
}
