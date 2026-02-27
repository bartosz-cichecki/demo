<?php

declare(strict_types=1);

namespace App\Tests\SharedKernel\Infrastructure\Doctrine;

use App\Client\Domain\Client\Client;
use App\Client\Domain\Client\Outside\ClientOutsideInterface;
use App\Client\Domain\Client\Repository\ClientRepositoryInterface;
use App\SharedKernel\Domain\Attribute\OutsideField;
use App\SharedKernel\Domain\ValueObject\Id;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OutsidePostLoadSubscriberIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ClientRepositoryInterface $clientRepository;
    private ClientOutsideInterface $clientOutside;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $em = $container->get(EntityManagerInterface::class);
        $clientRepository = $container->get(ClientRepositoryInterface::class);
        $clientOutside = $container->get(ClientOutsideInterface::class);

        $this->assertInstanceOf(EntityManagerInterface::class, $em);
        $this->assertInstanceOf(ClientRepositoryInterface::class, $clientRepository);
        $this->assertInstanceOf(ClientOutsideInterface::class, $clientOutside);

        $this->em = $em;
        $this->clientRepository = $clientRepository;
        $this->clientOutside = $clientOutside;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function testItAttachesOutsideOnPostLoad(): void
    {
        $id = Id::new();
        $client = new Client($this->clientOutside, $id, 'ACME Corp', null);

        $this->clientRepository->create($client);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->clientRepository->get($id);

        $outside = $this->readOutsideViaMarker($reloaded);
        $this->assertInstanceOf(ClientOutsideInterface::class, $outside);
    }

    private function readOutsideViaMarker(object $entity): ?object
    {
        $reflection = new \ReflectionClass($entity);

        foreach ($reflection->getProperties() as $property) {
            if ([] !== $property->getAttributes(OutsideField::class)) {
                $property->setAccessible(true);

                $value = $property->getValue($entity);

                return \is_object($value) ? $value : null;
            }
        }

        return null;
    }
}
