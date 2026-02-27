<?php

declare(strict_types=1);

namespace App\User\Ui\Http\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST)]
final readonly class PlatformAdminGuardSubscriber
{
    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $routeName = $request->attributes->get('_route');

        if (!\is_string($routeName) || !str_starts_with($routeName, 'platform_')) {
            return;
        }

        if (!$request->hasSession()) {
            $event->setResponse(new JsonResponse(['error' => 'Access denied'], Response::HTTP_FORBIDDEN));

            return;
        }

        $session = $request->getSession();
        $sessionUserId = $session->get('user_id');
        if (!\is_string($sessionUserId) || '' === $sessionUserId) {
            $event->setResponse(new JsonResponse(['error' => 'Access denied'], Response::HTTP_FORBIDDEN));

            return;
        }

        if (true !== $session->get('is_platform_admin')) {
            $event->setResponse(new JsonResponse(['error' => 'Access denied'], Response::HTTP_FORBIDDEN));
        }
    }
}
