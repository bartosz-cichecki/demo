<?php

declare(strict_types=1);

namespace App\SharedKernel\Ui\Http\EventListener;

use App\SharedKernel\Ui\Http\Exception\ValidationException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
final readonly class ValidationExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof ValidationException) {
            return;
        }

        $response = new JsonResponse(
            ['errors' => $exception->getErrors()],
            Response::HTTP_BAD_REQUEST,
        );
        $event->setResponse($response);
    }
}
