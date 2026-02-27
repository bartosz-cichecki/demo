<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

final class PlatformRouteNamingTest extends KernelTestCase
{
    public function testPlatformRoutesMustStartWithPlatformUnderscore(): void
    {
        self::bootKernel();

        $router = self::getContainer()->get(RouterInterface::class);
        \assert($router instanceof RouterInterface);

        $violations = [];
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            if (str_contains($name, 'platform') && !str_starts_with($name, 'platform_')) {
                $violations[] = $name;
            }
        }

        self::assertSame([], $violations, \sprintf(
            'Routes containing "platform" must start with "platform_". Violations: %s',
            implode(', ', $violations),
        ));
    }
}
