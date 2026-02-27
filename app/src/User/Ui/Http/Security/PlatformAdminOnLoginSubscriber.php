<?php

declare(strict_types=1);

namespace App\User\Ui\Http\Security;

use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Application\User\Query\UserQueryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::RESPONSE)]
final readonly class PlatformAdminOnLoginSubscriber
{
    /**
     * @param array<string> $platformAdminEmails
     */
    public function __construct(
        #[Autowire('%app.platform_admin_emails%')]
        private array $platformAdminEmails,
        private UserQueryInterface $userQuery,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if ('api_auth_otp_verify' !== $event->getRequest()->attributes->get('_route')) {
            return;
        }

        if (Response::HTTP_OK !== $event->getResponse()->getStatusCode()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $sessionUserId = $session->get('user_id');
        if (!\is_string($sessionUserId)) {
            return;
        }

        $session->set('is_platform_admin', false);

        try {
            $userId = new Id($sessionUserId);
        } catch (\InvalidArgumentException) {
            return;
        }

        $user = $this->userQuery->findById($userId);
        if (null === $user) {
            return;
        }

        $session->set('is_platform_admin', \in_array($user->email, $this->platformAdminEmails, true));
    }
}
