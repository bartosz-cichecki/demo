<?php

declare(strict_types=1);

namespace App\Tests\Behat\Platform;

use App\Client\Application\Client\Query\ClientQueryInterface;
use App\SharedKernel\Domain\ValueObject\Id;
use App\Tests\Behat\Support\Http\AuthenticatedSessionApplier;
use Behat\Behat\Context\Context;
use PHPUnit\Framework\Assert;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class PlatformClientContext implements Context
{
    private ?string $createdClientId = null;
    private ?int $lastResponseCode = null;

    public function __construct(
        private readonly KernelBrowser $client,
        private readonly ClientQueryInterface $clientQuery,
        private readonly AuthenticatedSessionApplier $sessionApplier,
    ) {
    }

    /**
     * @When I create a client named :name via platform API
     */
    public function iCreateAClientNamedViaPlatformApi(string $name): void
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

        $this->lastResponseCode = $this->client->getResponse()->getStatusCode();

        if (201 === $this->lastResponseCode) {
            $content = $this->client->getResponse()->getContent();
            Assert::assertIsString($content);

            /** @var array{id: string} $data */
            $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
            $this->createdClientId = $data['id'];
        }
    }

    /**
     * @Then the response status should be :statusCode
     */
    public function theResponseStatusShouldBe(int $statusCode): void
    {
        Assert::assertSame($statusCode, $this->lastResponseCode, \sprintf(
            'Expected status %d, got %d. Response: %s',
            $statusCode,
            $this->lastResponseCode,
            (string) $this->client->getResponse()->getContent(),
        ));
    }

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
}
