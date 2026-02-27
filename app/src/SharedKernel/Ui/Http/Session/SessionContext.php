<?php

declare(strict_types=1);

namespace App\SharedKernel\Ui\Http\Session;

use App\SharedKernel\Domain\ValueObject\Id;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class SessionContext
{
    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function userId(): Id
    {
        return $this->requireId('user_id');
    }

    public function activeClientId(): Id
    {
        return $this->requireId('active_client_id');
    }

    private function requireId(string $key): Id
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            throw new \LogicException(\sprintf('No current request when reading session key "%s".', $key));
        }

        if (!$request->hasSession()) {
            throw new \LogicException(\sprintf('No session available when reading session key "%s".', $key));
        }

        $value = $request->getSession()->get($key);
        if (!\is_string($value) || '' === $value) {
            throw new \LogicException(\sprintf('Session key "%s" is missing or invalid.', $key));
        }

        return new Id($value);
    }
}
