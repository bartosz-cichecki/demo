<?php

declare(strict_types=1);

namespace App\User\Ui\Http\Security;

use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Application\Tenant\Service\ActiveClientIdResolverService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::RESPONSE)]
final readonly class ActiveClientIdOnLoginSubscriber
{
    public function __construct(
        private ActiveClientIdResolverService $activeClientIdResolver,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ('/api/auth/otp/verify' !== $request->getPathInfo()) {
            return;
        }

        $response = $event->getResponse();
        if (Response::HTTP_OK !== $response->getStatusCode()) {
            return;
        }

        $content = $response->getContent();
        if (!\is_string($content)) {
            return;
        }

        $payload = json_decode($content, true);
        if (!\is_array($payload) || true !== ($payload['ok'] ?? false)) {
            return;
        }

        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $sessionUserId = $session->get('user_id');
        if (!\is_string($sessionUserId)) {
            $event->setResponse(new JsonResponse(['error' => 'Access denied'], Response::HTTP_FORBIDDEN));

            return;
        }

        try {
            $userId = new Id($sessionUserId);
        } catch (\InvalidArgumentException) {
            $event->setResponse(new JsonResponse(['error' => 'Access denied'], Response::HTTP_FORBIDDEN));

            return;
        }

        $resolution = $this->activeClientIdResolver->resolve($userId);
        if (!$resolution->allowed || null === $resolution->clientId) {
            $session->remove('active_client_id');
            $session->remove('user_id');
            $event->setResponse(new JsonResponse(['error' => 'Access denied'], Response::HTTP_FORBIDDEN));

            return;
        }

        $session->set('active_client_id', $resolution->clientId);

        if ($resolution->warning) {
            $this->logger->warning('Multiple active memberships detected. Deterministic active_client_id selected.', [
                'user_id' => (string) $userId,
                'active_client_id' => $resolution->clientId,
            ]);
        }
    }
}
