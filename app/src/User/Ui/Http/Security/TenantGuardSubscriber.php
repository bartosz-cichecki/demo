<?php

declare(strict_types=1);

namespace App\User\Ui\Http\Security;

use App\SharedKernel\Domain\ValueObject\Id;
use App\SharedKernel\Ui\Http\Logging\AbuseLogger;
use App\User\Application\Tenant\Query\MembershipForClientQueryInterface;
use App\User\Application\User\Query\UserQueryInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST)]
final readonly class TenantGuardSubscriber
{
    /**
     * @var array<string>
     */
    private const array ADMIN_REQUIRED_ROUTE_NAMES = [
        'api_client_invitations_create',
        'api_client_invitations_revoke',
        'api_client_members_list',
        'api_client_members_replace_roles',
        'api_client_members_suspend',
        'api_client_members_unsuspend',
    ];

    /**
     * @var array<string>
     */
    private const array ACTIVE_CLIENT_OPTIONAL_ROUTE_NAMES = [
        'api_invitations_accept',
        'api_invitations_reject',
        'api_me_clients_list',
        'api_me_invitations_list',
        'api_session_active_client_select',
    ];

    /**
     * @var array<string>
     */
    private const array ALLOWLISTED_ROUTE_NAMES = [
        'api_auth_otp_request',
        'api_auth_otp_verify',
    ];

    public function __construct(
        private UserQueryInterface $userQuery,
        private MembershipForClientQueryInterface $membershipForClientQuery,
        private AbuseLogger $abuseLogger,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        if (!$this->isTenantRoute($path)) {
            return;
        }

        if (!$this->passesCrossOriginChecks($request)) {
            $event->setResponse($this->deny($request, Response::HTTP_FORBIDDEN, 'CORS_MISMATCH'));

            return;
        }

        if ($this->isPlatformRoute($request)) {
            return;
        }

        if ($this->isAllowlistedRequest($request)) {
            return;
        }

        if (!$request->hasSession()) {
            $event->setResponse($this->deny($request, Response::HTTP_UNAUTHORIZED, 'NO_SESSION'));

            return;
        }

        $session = $request->getSession();
        $sessionUserId = $session->get('user_id');
        if (!\is_string($sessionUserId) || '' === $sessionUserId) {
            $event->setResponse($this->deny($request, Response::HTTP_UNAUTHORIZED, 'INVALID_SESSION_USER'));

            return;
        }

        try {
            $userId = new Id($sessionUserId);
        } catch (\InvalidArgumentException) {
            $event->setResponse($this->deny($request, Response::HTTP_FORBIDDEN, 'INVALID_ID_FORMAT'));

            return;
        }

        $user = $this->userQuery->findById($userId);
        if (null === $user) {
            $event->setResponse($this->deny($request, Response::HTTP_UNAUTHORIZED, 'USER_NOT_FOUND'));

            return;
        }

        if ('blocked' === $user->status) {
            $event->setResponse($this->deny($request, Response::HTTP_FORBIDDEN, 'USER_BLOCKED'));

            return;
        }

        if ($this->isActiveClientOptionalRoute($request)) {
            return;
        }

        $sessionClientId = $session->get('active_client_id');
        if (!\is_string($sessionClientId) || '' === $sessionClientId) {
            $event->setResponse($this->deny($request, Response::HTTP_FORBIDDEN, 'MISSING_CLIENT_ID', 'active_client_required'));

            return;
        }

        if (!$this->passesClientScopeCheck($request, $sessionClientId)) {
            $event->setResponse($this->deny($request, Response::HTTP_FORBIDDEN, 'CLIENT_SCOPE_MISMATCH'));

            return;
        }

        try {
            $clientId = new Id($sessionClientId);
        } catch (\InvalidArgumentException) {
            $event->setResponse($this->deny($request, Response::HTTP_FORBIDDEN, 'INVALID_ID_FORMAT'));

            return;
        }

        $membership = $this->membershipForClientQuery->findForUserAndClient($userId, $clientId);
        if (null === $membership || !$membership->isActive) {
            $event->setResponse($this->deny($request, Response::HTTP_FORBIDDEN, 'MEMBERSHIP_INACTIVE'));

            return;
        }

        $routeName = $request->attributes->get('_route');
        if (!\is_string($routeName) || !\in_array($routeName, self::ADMIN_REQUIRED_ROUTE_NAMES, true)) {
            $event->setResponse($this->deny($request, Response::HTTP_FORBIDDEN, 'ROUTE_NOT_MAPPED'));

            return;
        }

        if (!$membership->isAdmin) {
            $event->setResponse($this->deny($request, Response::HTTP_FORBIDDEN, 'INSUFFICIENT_ROLES'));
        }
    }

    private function isTenantRoute(string $path): bool
    {
        return str_starts_with($path, '/api/');
    }

    private function isPlatformRoute(Request $request): bool
    {
        $routeName = $request->attributes->get('_route');

        return \is_string($routeName) && str_starts_with($routeName, 'platform_');
    }

    private function isAllowlistedRequest(Request $request): bool
    {
        if ('/api/health' === $request->getPathInfo()) {
            return true;
        }

        $routeName = $request->attributes->get('_route');
        if (!\is_string($routeName)) {
            return false;
        }

        return \in_array($routeName, self::ALLOWLISTED_ROUTE_NAMES, true);
    }

    private function isActiveClientOptionalRoute(Request $request): bool
    {
        $routeName = $request->attributes->get('_route');

        return \is_string($routeName) && \in_array($routeName, self::ACTIVE_CLIENT_OPTIONAL_ROUTE_NAMES, true);
    }

    private function passesClientScopeCheck(Request $request, string $sessionClientId): bool
    {
        $routeClientId = $request->attributes->get('clientId');
        if (!\is_string($routeClientId)) {
            return true;
        }

        return $routeClientId === $sessionClientId;
    }

    private function deny(Request $request, int $status, string $reasonCode, string $error = 'Access denied'): JsonResponse
    {
        if ($this->shouldLogDeny($request, $reasonCode)) {
            $this->abuseLogger->warning($request, $reasonCode);
        }

        return new JsonResponse(['error' => $error], $status);
    }

    private function shouldLogDeny(Request $request, string $reasonCode): bool
    {
        return 'CORS_MISMATCH' === $reasonCode;
    }

    private function passesCrossOriginChecks(Request $request): bool
    {
        if (!$this->isMutatingMethod($request->getMethod())) {
            return true;
        }

        $origin = $request->headers->get('Origin');
        if (\is_string($origin) && '' !== $origin) {
            if (!$this->isSameOrigin($request, $origin)) {
                return false;
            }

            return true;
        }

        $referer = $request->headers->get('Referer');
        if (\is_string($referer) && '' !== $referer && !$this->isSameOrigin($request, $referer)) {
            return false;
        }

        return true;
    }

    private function isMutatingMethod(string $method): bool
    {
        return \in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    private function isSameOrigin(Request $request, string $headerValue): bool
    {
        $requestOrigin = $this->parseOrigin($request->getSchemeAndHttpHost());
        $headerOrigin = $this->parseOrigin($headerValue);

        if (null === $requestOrigin || null === $headerOrigin) {
            return false;
        }

        return $requestOrigin['scheme'] === $headerOrigin['scheme']
            && $requestOrigin['host'] === $headerOrigin['host']
            && $requestOrigin['port'] === $headerOrigin['port'];
    }

    /**
     * @return array{scheme: string, host: string, port: int}|null
     */
    private function parseOrigin(string $url): ?array
    {
        $parts = parse_url($url);
        if (!\is_array($parts)) {
            return null;
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        $port = $parts['port'] ?? null;

        if (!\is_string($scheme) || !\is_string($host)) {
            return null;
        }

        return [
            'scheme' => strtolower($scheme),
            'host' => strtolower($host),
            'port' => $port ?? $this->defaultPort($scheme),
        ];
    }

    private function defaultPort(string $scheme): int
    {
        return 'https' === strtolower($scheme) ? 443 : 80;
    }
}
