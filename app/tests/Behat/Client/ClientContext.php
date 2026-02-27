<?php

declare(strict_types=1);

namespace App\Tests\Behat\Client;

use App\Client\Application\Client\Query\ClientQueryInterface;
use App\SharedKernel\Domain\ValueObject\Id;
use App\Tests\Behat\Support\Http\AuthenticatedSessionApplier;
use Behat\Behat\Context\Context;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Assert;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class ClientContext implements Context
{
    private ?string $createdClientId = null;

    public function __construct(
        private readonly KernelBrowser $client,
        private readonly Connection $connection,
        private readonly ClientQueryInterface $clientQuery,
        private readonly AuthenticatedSessionApplier $sessionApplier,
    ) {
    }

    // ========================================
    // When: HTTP endpoints only
    // ========================================

    /**
     * @When I register a new client named :name
     */
    public function iRegisterANewClientNamed(string $name): void
    {
        $this->sessionApplier->apply($this->client);

        $this->client->request(
            'POST',
            '/api/clients',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => $name], \JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();
        Assert::assertSame(201, $response->getStatusCode(), \sprintf(
            'Expected status 201, got %d. Response: %s',
            $response->getStatusCode(),
            (string) $response->getContent(),
        ));

        $content = $response->getContent();
        Assert::assertIsString($content);

        /** @var array{id: string} $data */
        $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        Assert::assertArrayHasKey('id', $data);

        $this->createdClientId = $data['id'];
    }

    // ========================================
    // Then: Query-based state verification
    // ========================================

    /**
     * @Then a client named :name should exist
     */
    public function aClientNamedShouldExist(string $name): void
    {
        Assert::assertNotNull($this->createdClientId, 'No client was created');

        $dto = $this->clientQuery->findById(new Id($this->createdClientId));

        Assert::assertNotNull($dto);
        Assert::assertSame($name, $dto->name);
    }

    /**
     * @Then the registration of :name should be recorded
     */
    public function theRegistrationOfShouldBeRecorded(string $name): void
    {
        Assert::assertNotNull($this->createdClientId, 'No client was created');

        $result = $this->connection->fetchAssociative(
            'SELECT * FROM shared.event_log WHERE event_name = :event_name ORDER BY id DESC LIMIT 1',
            ['event_name' => 'App\\Client\\Domain\\Client\\Event\\ClientCreated'],
        );

        Assert::assertIsArray($result, 'ClientCreated event not found in event_log');
        Assert::assertArrayHasKey('payload', $result);
        Assert::assertIsString($result['payload']);

        /** @var array{name: string, clientId: string} $payload */
        $payload = json_decode($result['payload'], true, 512, \JSON_THROW_ON_ERROR);

        Assert::assertSame($name, $payload['name'], 'Event payload name mismatch');
        Assert::assertSame($this->createdClientId, $payload['clientId'], 'Event payload clientId mismatch');
    }
}
