<?php

declare(strict_types=1);

use Deptrac\Deptrac\Contract\Config\Collector\BoolConfig;
use Deptrac\Deptrac\Contract\Config\Collector\ClassNameRegexConfig;
use Deptrac\Deptrac\Contract\Config\Collector\DirectoryConfig;
use Deptrac\Deptrac\Contract\Config\Collector\LayerConfig;
use Deptrac\Deptrac\Contract\Config\CollectorConfig;
use Deptrac\Deptrac\Contract\Config\DeptracConfig;
use Deptrac\Deptrac\Contract\Config\Layer;
use Deptrac\Deptrac\Contract\Config\Ruleset;

return static function (DeptracConfig $config): void {
    $config->paths('src')->excludeFiles('#src/Kernel\.php#');

    $layer = static function (string $name, CollectorConfig ...$collectors) use ($config): Layer {
        $layer = Layer::withName($name)->collectors(...$collectors);
        $config->layers($layer);

        return $layer;
    };

    $clock = $layer('SharedKernelDomainClock', DirectoryConfig::create('src/SharedKernel/Domain/Clock/.*'));
    $valueObject = $layer('SharedKernelDomainValueObject', DirectoryConfig::create('src/SharedKernel/Domain/ValueObject/.*'));
    $core = $layer('SharedKernelDomainCore', DirectoryConfig::create('src/SharedKernel/Domain/(?!(Clock|ValueObject)/).*'));
    $sharedApplication = $layer('SharedKernelApplication', DirectoryConfig::create('src/SharedKernel/Application/.*'));
    $sharedInfrastructure = $layer('SharedKernelInfrastructure', DirectoryConfig::create('src/SharedKernel/Infrastructure/.*'));
    $sharedUi = $layer('SharedKernelUi', DirectoryConfig::create('src/SharedKernel/Ui/.*'));
    $sharedDomain = [$clock, $core, $valueObject];

    $doctrineMapping = $layer('DoctrineMapping', ClassNameRegexConfig::create('#^Doctrine\\ORM\\Mapping\\#'));
    $external = $layer('External',
        ClassNameRegexConfig::create('#^Symfony\\#'),
        ClassNameRegexConfig::create('#^Doctrine\\(?!ORM\\Mapping\\)#'),
        ClassNameRegexConfig::create('#^Monolog\\#'),
        ClassNameRegexConfig::create('#^Twig\\#'),
    );
    $psr = $layer('PsrContracts', ClassNameRegexConfig::create('#^Psr\\#'));
    $uuid = $layer('UuidVendor', ClassNameRegexConfig::create('#^Ramsey\\Uuid\\#'));

    // SharedKernel keeps its own rules; it is not a bounded context.
    $config->rulesets(
        Ruleset::forLayer($clock)->accesses($valueObject),
        Ruleset::forLayer($core)->accesses($clock),
        Ruleset::forLayer($valueObject)->accesses($core, $uuid),
        $sharedApplicationRules = Ruleset::forLayer($sharedApplication)->accesses(...$sharedDomain),
        $sharedInfrastructureRules = Ruleset::forLayer($sharedInfrastructure)->accesses($sharedApplication, $external, $psr, ...$sharedDomain),
        $sharedUiRules = Ruleset::forLayer($sharedUi)->accesses($sharedApplication, $external, $psr, ...$sharedDomain),
        Ruleset::forLayer($doctrineMapping),
        Ruleset::forLayer($external),
        Ruleset::forLayer($psr),
        Ruleset::forLayer($uuid),
    );

    $contracts = [];
    $integrationEvents = [];
    $integrationEventSubscribers = [];
    foreach (glob(__DIR__ . '/src/*', \GLOB_ONLYDIR) as $directory) {
        $bc = basename($directory);
        if ('SharedKernel' === $bc) {
            continue;
        }

        $namespace = '#^App\\' . preg_quote($bc, '#') . '\\Application\\';
        // Query/DTO live directly in their directories; Command may have subnamespaces.
        $contracts[$bc] = $layer($bc . 'Contracts', ClassNameRegexConfig::create(
            $namespace . '(?:[^\\]+\\)+(Query\\[^\\]+QueryInterface|Command\\(?:[^\\]+\\)*[^\\]+Command|Query\\Dto\\[^\\]+Dto)$#',
        ));
        $integrationEvents[$bc] = $layer($bc . 'IntegrationEvents', ClassNameRegexConfig::create(
            $namespace . 'IntegrationEvent\\(?:[^\\]+\\)*[^\\]+IntegrationEvent$#',
        ));
        $integrationEventSubscribers[$bc] = $layer($bc . 'IntegrationEventSubscribers', ClassNameRegexConfig::create(
            $namespace . 'IntegrationEventSubscriber\\[^\\]+Subscriber$#',
        ));
    }

    // One contract for every discovered BC, including future contexts.
    foreach ($contracts as $bc => $contract) {
        $events = $integrationEvents[$bc];
        $subscribers = $integrationEventSubscribers[$bc];
        $path = 'src/' . preg_quote($bc, '#');
        $application = $layer($bc . 'Application', BoolConfig::create()
            ->must(DirectoryConfig::create($path . '/Application/.*'))
            ->mustNot(LayerConfig::create($contract->name), LayerConfig::create($events->name), LayerConfig::create($subscribers->name)));
        $outside = $layer($bc . 'Outside', DirectoryConfig::create($path . '/Domain/.*/Outside/.*'));
        $domain = $layer($bc . 'Domain', BoolConfig::create()
            ->must(DirectoryConfig::create($path . '/Domain/.*'))
            ->mustNot(LayerConfig::create($outside->name)));
        $infrastructure = $layer($bc . 'Infrastructure', DirectoryConfig::create($path . '/Infrastructure/.*'));
        $ui = $layer($bc . 'Ui', DirectoryConfig::create($path . '/Ui/.*'));

        $ownApplication = [$application, $contract, $events, $subscribers];
        $applicationDependencies = [...$ownApplication, $domain, $sharedApplication, ...$sharedDomain];
        $foreignContracts = array_diff_key($contracts, [$bc => $contract]);
        $config->rulesets(
            Ruleset::forLayer($domain)->accesses($outside, $doctrineMapping, ...$sharedDomain),
            Ruleset::forLayer($outside)->accesses($domain, $doctrineMapping, ...$sharedDomain),
            Ruleset::forLayer($application)->accesses(...$applicationDependencies),
            Ruleset::forLayer($contract)->accesses(...$applicationDependencies),
            Ruleset::forLayer($events)->accesses(...$applicationDependencies),
            Ruleset::forLayer($subscribers)->accesses(...$applicationDependencies, ...array_values($integrationEvents)),
            Ruleset::forLayer($infrastructure)->accesses(
                $application, $contract, $events, $subscribers, $domain, $outside,
                $sharedApplication, $sharedInfrastructure, $external, $psr,
                ...$sharedDomain, ...array_values($foreignContracts),
            ),
            Ruleset::forLayer($ui)->accesses(
                $application, $contract, $events, $subscribers, $domain, $outside,
                $sharedApplication, $sharedUi, $external, $psr, ...$sharedDomain,
            ),
        );

        // SharedKernel/Application must not access Outside (§6).
        $sharedApplicationRules->accesses($domain, ...$ownApplication);
        $sharedInfrastructureRules->accesses($application, $contract, $events, $subscribers, $domain, $outside, $infrastructure);
        $sharedUiRules->accesses($application, $contract, $events, $subscribers, $domain, $outside, $ui);
    }
};
