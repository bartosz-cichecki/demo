<?php

declare(strict_types=1);

namespace App\Tests\User\Application\Tenant\Service;

use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Application\Tenant\Query\ActiveMembershipsQueryInterface;
use App\User\Application\Tenant\Query\Dto\ActiveMembershipDto;
use App\User\Application\Tenant\Service\ActiveClientIdResolverService;
use PHPUnit\Framework\TestCase;

final class ActiveClientIdResolverServiceTest extends TestCase
{
    public function testItDeniesWhenThereAreNoActiveMemberships(): void
    {
        $resolver = new ActiveClientIdResolverService(
            new InMemoryActiveMembershipsQuery([]),
        );

        $resolution = $resolver->resolve(new Id('11111111-1111-4111-8111-111111111111'));

        self::assertFalse($resolution->allowed);
        self::assertNull($resolution->clientId);
        self::assertFalse($resolution->warning);
    }

    public function testItSelectsSingleMembershipWithoutWarning(): void
    {
        $resolver = new ActiveClientIdResolverService(
            new InMemoryActiveMembershipsQuery([
                new ActiveMembershipDto('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'),
            ]),
        );

        $resolution = $resolver->resolve(new Id('22222222-2222-4222-8222-222222222222'));

        self::assertTrue($resolution->allowed);
        self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $resolution->clientId);
        self::assertFalse($resolution->warning);
    }

    public function testItPicksDeterministicallyAndWarnsForManyMemberships(): void
    {
        $resolver = new ActiveClientIdResolverService(
            new InMemoryActiveMembershipsQuery([
                new ActiveMembershipDto('dddddddd-dddd-4ddd-8ddd-dddddddddddd'),
                new ActiveMembershipDto('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'),
                new ActiveMembershipDto('cccccccc-cccc-4ccc-8ccc-cccccccccccc'),
            ]),
        );

        $resolution = $resolver->resolve(new Id('33333333-3333-4333-8333-333333333333'));

        self::assertTrue($resolution->allowed);
        self::assertSame('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $resolution->clientId);
        self::assertTrue($resolution->warning);
    }
}

/**
 * @internal
 */
final readonly class InMemoryActiveMembershipsQuery implements ActiveMembershipsQueryInterface
{
    /**
     * @param array<ActiveMembershipDto> $memberships
     */
    public function __construct(
        private array $memberships,
    ) {
    }

    public function listForUser(Id $userId): array
    {
        return $this->memberships;
    }
}
