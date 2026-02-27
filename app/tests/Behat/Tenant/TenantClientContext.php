<?php

declare(strict_types=1);

namespace App\Tests\Behat\Tenant;

use App\Tests\Behat\Support\Http\AuthenticatedSessionApplier;
use Behat\Behat\Context\Context;
use PHPUnit\Framework\Assert;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class TenantClientContext implements Context
{
    private ?int $lastResponseCode = null;

    public function __construct(
        private readonly KernelBrowser $client,
        private readonly AuthenticatedSessionApplier $sessionApplier,
    ) {
    }

    /**
     * @When I try to create a client named :name
     */
    public function iTryToCreateAClientNamed(string $name): void
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
}
