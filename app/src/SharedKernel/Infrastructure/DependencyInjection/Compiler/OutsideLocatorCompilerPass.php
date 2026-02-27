<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\DependencyInjection\Compiler;

use App\SharedKernel\Infrastructure\Outside\Attribute\AsOutsideFor;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class OutsideLocatorCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $taggedServices = $container->findTaggedServiceIds('app.outside');
        $outsideMap = [];

        foreach (array_keys($taggedServices) as $serviceId) {
            $definition = $container->getDefinition($serviceId);
            $class = $definition->getClass();

            if (null === $class || !class_exists($class)) {
                continue;
            }

            /** @var \ReflectionClass<object> $reflectionClass */
            $reflectionClass = new \ReflectionClass($class);
            $attributes = $reflectionClass->getAttributes(AsOutsideFor::class);

            if ([] === $attributes) {
                continue;
            }

            /** @var AsOutsideFor $attribute */
            $attribute = $attributes[0]->newInstance();
            $interfaceFqcn = $attribute->interfaceFqcn;

            $outsideMap[$interfaceFqcn] = new ServiceClosureArgument(new Reference($serviceId));
        }

        $locatorDefinition = new Definition(ServiceLocator::class, [$outsideMap]);
        $locatorDefinition->addTag('container.service_locator');

        $container->setDefinition('app.outside_locator', $locatorDefinition);
    }
}
