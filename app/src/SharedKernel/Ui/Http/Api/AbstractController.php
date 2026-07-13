<?php

declare(strict_types=1);

namespace App\SharedKernel\Ui\Http\Api;

use App\SharedKernel\Application\CommandBus\CommandBusInterface;
use App\SharedKernel\Application\CommandBus\CommandInterface;
use App\SharedKernel\Application\CommandBus\CommandWithResultInterface;
use App\SharedKernel\Domain\ValueObject\Id;
use App\SharedKernel\Ui\Http\Exception\ValidationException;
use App\SharedKernel\Ui\Http\Session\SessionContext;
use App\SharedKernel\Ui\Input\Input;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Validator\ValidatorInterface;

abstract readonly class AbstractController
{
    public function __construct(
        private ValidatorInterface $validator,
        private CommandBusInterface $commandBus,
        private SessionContext $sessionContext,
    ) {
    }

    protected function requireUserId(): Id
    {
        return $this->sessionContext->userId();
    }

    protected function requireActiveClientId(): Id
    {
        return $this->sessionContext->activeClientId();
    }

    protected function executeCommand(CommandInterface $command): void
    {
        $this->commandBus->dispatch($command);
    }

    /**
     * @template TResult of object
     *
     * @param CommandWithResultInterface<TResult> $command
     *
     * @return TResult
     */
    protected function executeCommandWithResult(CommandWithResultInterface $command): object
    {
        return $this->commandBus->dispatchWithResult($command);
    }

    /**
     * @param class-string<Input> $inputClassName
     */
    protected function getValidatedInput(
        Request $request,
        string $inputClassName,
    ): Input {
        /** @var array<string, mixed> $payload */
        $payload = $request->toArray();

        $schema = $inputClassName::getSchema();
        $violations = $this->validator->validate($payload, $schema);

        if ($violations->count() > 0) {
            throw new ValidationException($violations);
        }

        return $inputClassName::create($payload);
    }
}
