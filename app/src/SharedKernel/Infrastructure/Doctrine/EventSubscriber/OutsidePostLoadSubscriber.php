<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Doctrine\EventSubscriber;

use App\SharedKernel\Domain\Attribute\OutsideField;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsDoctrineListener(event: Events::postLoad)]
final class OutsidePostLoadSubscriber
{
    /** @var array<class-string, \ReflectionProperty|null> */
    private array $propertyCache = [];

    public function __construct(
        #[Autowire(service: 'app.outside_locator')]
        private readonly ContainerInterface $outsideLocator,
    ) {
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();
        $entityClass = $entity::class;

        if (!str_starts_with($entityClass, 'App\\') || !str_contains($entityClass, '\\Domain\\')) {
            return;
        }

        $property = $this->findOutsideProperty($entityClass);
        if (null === $property) {
            return;
        }

        $currentValue = $property->getValue($entity);
        if (null !== $currentValue) {
            return;
        }

        $outsideInterface = $this->resolveOutsideInterface($entityClass);
        if (!$this->outsideLocator->has($outsideInterface)) {
            throw new \LogicException(\sprintf('Outside service for entity %s not found. Expected service ID: %s', $entityClass, $outsideInterface));
        }

        $property->setValue($entity, $this->outsideLocator->get($outsideInterface));
    }

    /**
     * @param class-string $entityClass
     */
    private function findOutsideProperty(string $entityClass): ?\ReflectionProperty
    {
        if (\array_key_exists($entityClass, $this->propertyCache)) {
            return $this->propertyCache[$entityClass];
        }

        $reflection = new \ReflectionClass($entityClass);
        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(OutsideField::class);
            if ([] !== $attributes) {
                $type = $property->getType();
                if (!$type instanceof \ReflectionNamedType || !$type->allowsNull()) {
                    throw new \LogicException(\sprintf('Property %s::%s marked with #[OutsideField] must be nullable.', $entityClass, $property->getName()));
                }
                $property->setAccessible(true);
                $this->propertyCache[$entityClass] = $property;

                return $property;
            }
        }

        $this->propertyCache[$entityClass] = null;

        return null;
    }

    /**
     * @param class-string $entityClass
     */
    private function resolveOutsideInterface(string $entityClass): string
    {
        $parts = explode('\\', $entityClass);
        $entityName = array_pop($parts);
        $namespace = implode('\\', $parts);

        return $namespace . '\\Outside\\' . $entityName . 'OutsideInterface';
    }
}
