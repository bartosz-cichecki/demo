<?php

declare(strict_types=1);

namespace App\Tests\SharedKernel\Ui\Http\Session;

use App\SharedKernel\Ui\Http\Session\SessionContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class SessionContextTest extends TestCase
{
    private const string VALID_UUID = '550e8400-e29b-41d4-a716-446655440000';

    public function testUserIdReturnsIdFromSession(): void
    {
        $context = $this->createContextWithSession(['user_id' => self::VALID_UUID]);

        $result = $context->userId();

        $this->assertSame(self::VALID_UUID, (string) $result);
    }

    public function testActiveClientIdReturnsIdFromSession(): void
    {
        $context = $this->createContextWithSession(['active_client_id' => self::VALID_UUID]);

        $result = $context->activeClientId();

        $this->assertSame(self::VALID_UUID, (string) $result);
    }

    public function testThrowsWhenNoCurrentRequest(): void
    {
        $requestStack = new RequestStack();
        $context = new SessionContext($requestStack);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No current request');

        $context->userId();
    }

    public function testThrowsWhenNoSession(): void
    {
        $request = new Request();
        $requestStack = new RequestStack();
        $requestStack->push($request);
        $context = new SessionContext($requestStack);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No session available');

        $context->userId();
    }

    public function testThrowsWhenSessionKeyMissing(): void
    {
        $context = $this->createContextWithSession([]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Session key "user_id" is missing or invalid');

        $context->userId();
    }

    public function testThrowsWhenSessionValueIsEmptyString(): void
    {
        $context = $this->createContextWithSession(['user_id' => '']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Session key "user_id" is missing or invalid');

        $context->userId();
    }

    public function testThrowsWhenSessionValueIsNotString(): void
    {
        $context = $this->createContextWithSession(['user_id' => 42]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Session key "user_id" is missing or invalid');

        $context->userId();
    }

    /**
     * @param array<string, mixed> $sessionData
     */
    private function createContextWithSession(array $sessionData): SessionContext
    {
        $session = $this->createStub(SessionInterface::class);
        $session->method('get')
            ->willReturnCallback(static fn (string $key) => $sessionData[$key] ?? null);

        $request = new Request();
        $request->setSession($session);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        return new SessionContext($requestStack);
    }
}
