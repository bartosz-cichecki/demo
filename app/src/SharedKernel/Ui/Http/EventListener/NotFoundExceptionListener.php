<?php

declare(strict_types=1);

namespace App\SharedKernel\Ui\Http\EventListener;

use App\SharedKernel\Domain\Exception\ResourceDoesNotExistException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
final readonly class NotFoundExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof ResourceDoesNotExistException) {
            return;
        }

        $response = new JsonResponse(
            ['error' => 'Not found'],
            Response::HTTP_NOT_FOUND,
        );
        $event->setResponse($response);
    }
}
