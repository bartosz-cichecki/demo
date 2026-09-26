<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class BoundedContextDependenciesTest extends TestCase
{
    private string $projectDir;
    private string $workingDir;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->projectDir = \dirname(__DIR__, 2);
        $this->workingDir = sys_get_temp_dir() . '/deptrac-architecture-' . bin2hex(random_bytes(8));
        $this->filesystem = new Filesystem();
        $this->filesystem->mkdir($this->workingDir);
        $this->filesystem->mirror($this->projectDir . '/src', $this->workingDir . '/src');
        // Use the production configuration verbatim, including paths and exclusions.
        $this->filesystem->copy($this->projectDir . '/deptrac.php', $this->workingDir . '/deptrac.php');
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->workingDir);
    }

    public function testExistingCodeAndPublicContractsAreAllowed(): void
    {
        // The copied sources include the real provisioning/ACL adapters and all Outsides.
        $this->addDependency('Client\\Infrastructure\\QueryProbe', 'User\\Application\\User\\Query\\UserQueryInterface');
        $this->addDependency('Client\\Infrastructure\\CommandProbe', 'User\\Application\\User\\Command\\UpsertUserByEmail\\UpsertUserByEmailCommand');
        $this->addDependency('Client\\Infrastructure\\DtoProbe', 'User\\Application\\User\\Query\\Dto\\UserDto');
        $this->addDependency('Client\\Domain\\OutsideProbe', 'Client\\Domain\\Client\\Outside\\ClientOutsideInterface');
        $this->addDependency('Client\\Application\\IntegrationEventSubscriber\\UserRegisteredSubscriber', 'User\\Application\\IntegrationEvent\\UserRegisteredIntegrationEvent');
        $this->addDependency('SharedKernel\\Application\\EventProbe', 'User\\Application\\IntegrationEvent\\UserRegisteredIntegrationEvent');
        $this->addDependency('SharedKernel\\Infrastructure\\SubscriberProbe', 'User\\Application\\IntegrationEventSubscriber\\SendUserRegisteredNotificationSubscriber');
        $this->addDependency('SharedKernel\\Infrastructure\\ClientQueryProbe', 'Client\\Infrastructure\\Client\\ClientQuery');
        $this->addDependency('SharedKernel\\Ui\\ClientOutsideProbe', 'Client\\Domain\\Client\\Outside\\ClientOutsideInterface');

        $process = $this->analyse();
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertSame([], $this->violationMessages($process));
    }

    public function testForbiddenDependenciesAreRejected(): void
    {
        $dependencies = [
            'Client\\Application\\ForeignEvent' => 'User\\Application\\IntegrationEvent\\UserRegisteredIntegrationEvent',
            'Client\\Domain\\ForeignEvent' => 'User\\Application\\IntegrationEvent\\UserRegisteredIntegrationEvent',
            'Client\\Ui\\ForeignEvent' => 'User\\Application\\IntegrationEvent\\UserRegisteredIntegrationEvent',
            'Client\\Infrastructure\\ForeignEvent' => 'User\\Application\\IntegrationEvent\\UserRegisteredIntegrationEvent',
            'Client\\Application\\Other\\WrongPathSubscriber' => 'User\\Application\\IntegrationEvent\\UserRegisteredIntegrationEvent',
            'Client\\Application\\IntegrationEventSubscriber\\WrongSuffix' => 'User\\Application\\IntegrationEvent\\UserRegisteredIntegrationEvent',
            'Client\\Application\\IntegrationEventSubscriber\\ForeignQuerySubscriber' => 'User\\Application\\User\\Query\\UserQueryInterface',
            'Client\\Application\\IntegrationEventSubscriber\\ForeignHandlerSubscriber' => 'User\\Application\\User\\Command\\UpsertUserByEmail\\UpsertUserByEmailCommandHandler',
            'Client\\Application\\IntegrationEventSubscriber\\OwnOutsideSubscriber' => 'Client\\Domain\\Client\\Outside\\ClientOutsideInterface',
            'Client\\Application\\ForeignQuery' => 'User\\Application\\User\\Query\\UserQueryInterface',
            'Client\\Ui\\ForeignCommand' => 'User\\Application\\User\\Command\\UpsertUserByEmail\\UpsertUserByEmailCommand',
            'Client\\Domain\\ForeignDto' => 'User\\Application\\User\\Query\\Dto\\UserDto',
            'Client\\Infrastructure\\ForeignRepository' => 'User\\Domain\\User\\Repository\\UserRepositoryInterface',
            'Client\\Infrastructure\\ForeignHandler' => 'User\\Application\\User\\Command\\UpsertUserByEmail\\UpsertUserByEmailCommandHandler',
            'Client\\Infrastructure\\ForeignImplementation' => 'User\\Infrastructure\\User\\UserQuery',
            'Client\\Infrastructure\\ForeignService' => 'User\\Application\\OtpChallenge\\Service\\ValueHasherServiceInterface',
            'Client\\Application\\OwnOutside' => 'Client\\Domain\\Client\\Outside\\ClientOutsideInterface',
            'Client\\Domain\\Client\\Outside\\ForeignQuery' => 'User\\Application\\User\\Query\\UserQueryInterface',
            'SharedKernel\\Application\\ClientOutsideProbe' => 'Client\\Domain\\Client\\Outside\\ClientOutsideInterface',
            'SharedKernel\\Application\\ClientInfrastructureProbe' => 'Client\\Infrastructure\\Client\\ClientQuery',
            'SharedKernel\\Ui\\ClientInfrastructureProbe' => 'Client\\Infrastructure\\Client\\ClientQuery',
            'Client\\Domain\\OwnApplication' => 'Client\\Application\\Client\\Query\\ClientQueryInterface',
            'Client\\Application\\OwnInfrastructure' => 'Client\\Infrastructure\\Client\\ClientQuery',
            'Client\\Ui\\OwnInfrastructure' => 'Client\\Infrastructure\\Client\\ClientQuery',
            'SharedKernel\\Domain\\Clock\\ForbiddenValueObjectDirection' => 'SharedKernel\\Domain\\Event\\DomainEvent',
            'SharedKernel\\Domain\\ForbiddenInfrastructure' => 'SharedKernel\\Infrastructure\\Outside\\Attribute\\AsOutsideFor',
        ];
        $this->assertRejectedDependencies($dependencies);
    }

    public function testNewContextAutomaticallyReceivesTheStandardContract(): void
    {
        $this->filesystem->dumpFile($this->workingDir . '/src/Foo/Domain/Standalone.php', <<<'SOURCE'
            <?php
            namespace App\Foo\Domain;
            final class Standalone {}
            SOURCE);
        $process = $this->deptrac(['debug:layer', 'FooDomain']);
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertStringContainsString('App\\Foo\\Domain\\Standalone', $process->getOutput());

        // A new BC can both consume and expose public contracts without config edits.
        foreach (['Query\\FooQueryInterface', 'Command\\Create\\CreateFooCommand', 'Command\\DirectCommand', 'Query\\Dto\\FooDto'] as $index => $contract) {
            $target = 'Foo\\Application\\Foo\\' . $contract;
            $this->addDependency($target, 'Foo\\Domain\\Standalone');
            $this->addDependency('Client\\Infrastructure\\FooContract' . $index, $target);
        }
        $this->addDependency('Foo\\Infrastructure\\ForeignQuery', 'Client\\Application\\Client\\Query\\ClientQueryInterface');
        $this->addDependency('Foo\\Domain\\Foo\\Outside\\FooOutsideInterface', 'Foo\\Domain\\Standalone');
        $this->addDependency('Foo\\Domain\\OutsideProbe', 'Foo\\Domain\\Foo\\Outside\\FooOutsideInterface');
        $this->addDependency('SharedKernel\\Application\\FooDomainProbe', 'Foo\\Domain\\Standalone');
        foreach (['CreatedIntegrationEvent', 'Nested\\UpdatedIntegrationEvent'] as $index => $event) {
            $target = 'Foo\\Application\\IntegrationEvent\\' . $event;
            $this->addDependency($target, 'SharedKernel\\Application\\IntegrationEvent\\IntegrationEvent');
            $this->addDependency('Client\\Application\\IntegrationEventSubscriber\\FooEvent' . $index . 'Subscriber', $target);
        }
        $this->addDependency('Foo\\Application\\IntegrationEventSubscriber\\UserRegisteredSubscriber', 'User\\Application\\IntegrationEvent\\UserRegisteredIntegrationEvent');
        $this->addDependency('Foo\\Application\\IntegrationEventSubscriber\\OwnCommandSubscriber', 'Foo\\Application\\Foo\\Command\\DirectCommand');
        $this->addDependency('Foo\\Application\\PublishEvent', 'Foo\\Application\\IntegrationEvent\\CreatedIntegrationEvent');
        $this->addDependency('Foo\\Application\\IntegrationEvent\\Helper', 'Foo\\Domain\\Standalone');
        $this->addDependency('Foo\\Application\\Other\\PrivateIntegrationEvent', 'Foo\\Domain\\Standalone');
        $this->addDependency('Foo\\Application\\Foo\\Query\\Nested\\PrivateQueryInterface', 'Foo\\Domain\\Standalone');
        $this->addDependency('Foo\\Application\\Foo\\Query\\Dto\\Nested\\PrivateDto', 'Foo\\Domain\\Standalone');

        $process = $this->analyse();
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        self::assertSame([], $this->violationMessages($process));

        $this->assertRejectedDependencies([
            'Foo\\Application\\IntegrationEventSubscriber\\Nested\\UserRegisteredSubscriber' => 'User\\Application\\IntegrationEvent\\UserRegisteredIntegrationEvent',
            'Foo\\Application\\ForeignEvent' => 'User\\Application\\IntegrationEvent\\UserRegisteredIntegrationEvent',
            'Client\\Application\\ForeignFooEvent' => 'Foo\\Application\\IntegrationEvent\\CreatedIntegrationEvent',
            'Client\\Infrastructure\\ForeignFooEvent' => 'Foo\\Application\\IntegrationEvent\\CreatedIntegrationEvent',
            'Client\\Application\\IntegrationEventSubscriber\\ForeignHelperSubscriber' => 'Foo\\Application\\IntegrationEvent\\Helper',
            'Client\\Application\\IntegrationEventSubscriber\\WrongEventPathSubscriber' => 'Foo\\Application\\Other\\PrivateIntegrationEvent',
            'Client\\Infrastructure\\ForeignNestedQuery' => 'Foo\\Application\\Foo\\Query\\Nested\\PrivateQueryInterface',
            'Client\\Infrastructure\\ForeignNestedDto' => 'Foo\\Application\\Foo\\Query\\Dto\\Nested\\PrivateDto',
            'Foo\\Application\\ForeignQuery' => 'Client\\Application\\Client\\Query\\ClientQueryInterface',
            'Foo\\Domain\\ForeignDto' => 'Client\\Application\\Client\\Query\\Dto\\ClientDto',
            'Foo\\Ui\\ForeignCommand' => 'Client\\Application\\Client\\Command\\CreateClient\\CreateClientCommand',
            'Foo\\Infrastructure\\ForeignRepository' => 'Client\\Domain\\Client\\Repository\\ClientRepositoryInterface',
            'Foo\\Infrastructure\\ForeignHandler' => 'Client\\Application\\Client\\Command\\CreateClient\\CreateClientCommandHandler',
            'Foo\\Infrastructure\\ForeignImplementation' => 'Client\\Infrastructure\\Client\\ClientQuery',
            'Foo\\Application\\OwnOutside' => 'Foo\\Domain\\Foo\\Outside\\FooOutsideInterface',
            'Foo\\Application\\Foo\\Query\\ForbiddenQueryInterface' => 'Foo\\Domain\\Foo\\Outside\\FooOutsideInterface',
            'Client\\Application\\ForeignFooQuery' => 'Foo\\Application\\Foo\\Query\\FooQueryInterface',
            'Client\\Infrastructure\\FooDomainProbe' => 'Foo\\Domain\\Standalone',
            'SharedKernel\\Application\\FooOutsideProbe' => 'Foo\\Domain\\Foo\\Outside\\FooOutsideInterface',
        ]);
    }

    /** @param array<string, string> $dependencies */
    private function assertRejectedDependencies(array $dependencies): void
    {
        foreach ($dependencies as $source => $target) {
            $this->addDependency($source, $target);
        }

        $process = $this->analyse();
        self::assertSame(1, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        $messages = $this->violationMessages($process);
        self::assertCount(\count($dependencies), $messages);
        foreach ($dependencies as $source => $target) {
            self::assertStringContainsString('App\\' . $source . ' must not depend on App\\' . $target, implode("\n", $messages));
        }
    }

    private function addDependency(string $source, string $target): void
    {
        $separator = strrpos($source, '\\');
        self::assertNotFalse($separator);
        $namespace = 'App\\' . substr($source, 0, $separator);
        $name = substr($source, $separator + 1);
        $this->filesystem->dumpFile(
            $this->workingDir . '/src/' . str_replace('\\', '/', $source) . '.php',
            "<?php\nnamespace $namespace;\nfinal class $name { public const DEPENDENCY = \\App\\$target::class; }\n",
        );
    }

    private function analyse(): Process
    {
        return $this->deptrac(['analyse', '--formatter=json', '--no-progress', '--report-uncovered', '--fail-on-uncovered']);
    }

    /** @return list<string> */
    private function violationMessages(Process $process): array
    {
        /** @var array{Report: array<string, int>, files: array<string, array{messages: list<array{message: string}>}>} $report */
        $report = json_decode($process->getOutput(), true, 512, \JSON_THROW_ON_ERROR);
        foreach (['Uncovered', 'Skipped violations', 'Warnings', 'Errors'] as $key) {
            self::assertSame(0, $report['Report'][$key], $process->getOutput());
        }
        self::assertGreaterThan(0, $report['Report']['Allowed']);
        $messages = [];
        foreach ($report['files'] as $file) {
            foreach ($file['messages'] as $message) {
                $messages[] = $message['message'];
            }
        }

        return $messages;
    }

    /** @param list<string> $arguments */
    private function deptrac(array $arguments): Process
    {
        $process = new Process([\PHP_BINARY, $this->projectDir . '/vendor/bin/deptrac', '--config-file=deptrac.php', ...$arguments, '--no-cache', '--no-interaction', '--no-ansi'], $this->workingDir);
        $process->setTimeout(60);
        $process->run();

        return $process;
    }
}
