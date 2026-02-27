<?php

declare(strict_types=1);

namespace App\Tests\Behat\Support\Http;

use App\Tests\Behat\Support\Fixture\FixtureRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;

final readonly class AuthenticatedSessionApplier
{
    public function __construct(
        private FixtureRegistry $registry,
    ) {
    }

    public function apply(KernelBrowser $client): void
    {
        $auth = $this->registry->getAuthenticatedSession();
        if (null === $auth) {
            return;
        }

        $client->request('GET', '/api/health');
        $session = $client->getRequest()->getSession();
        $session->set('user_id', $auth['userId']);
        if (null === $auth['activeClientId']) {
            $session->remove('active_client_id');
        } else {
            $session->set('active_client_id', $auth['activeClientId']);
        }
        $session->set('is_platform_admin', $auth['isPlatformAdmin']);
        $session->save();

        $client->getCookieJar()->set(new Cookie(
            $session->getName(),
            $session->getId(),
        ));
    }
}
