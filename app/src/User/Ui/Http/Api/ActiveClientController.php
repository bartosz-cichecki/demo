<?php

declare(strict_types=1);

namespace App\User\Ui\Http\Api;

use App\SharedKernel\Domain\ValueObject\Id;
use App\SharedKernel\Ui\Http\Api\AbstractController;
use App\User\Application\Tenant\Query\ActiveMembershipsQueryInterface;
use App\User\Application\Tenant\Query\Dto\ActiveMembershipDto;
use App\User\Application\Tenant\Query\MembershipForClientQueryInterface;
use App\User\Ui\Input\SelectActiveClientInput;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ActiveClientController extends AbstractController
{
    #[Route('/me/clients', name: 'api_me_clients_list', methods: ['GET'])]
    public function list(ActiveMembershipsQueryInterface $activeMembershipsQuery): JsonResponse
    {
        $memberships = $activeMembershipsQuery->listForUser($this->requireUserId());

        return new JsonResponse(array_map(
            static fn (ActiveMembershipDto $membership): array => [
                'clientId' => $membership->clientId,
                'clientName' => $membership->clientName,
                'roles' => $membership->roles,
            ],
            $memberships,
        ));
    }

    #[Route('/session/active-client', name: 'api_session_active_client_select', methods: ['POST'])]
    public function select(
        Request $request,
        MembershipForClientQueryInterface $membershipForClientQuery,
    ): JsonResponse {
        /** @var SelectActiveClientInput $input */
        $input = $this->getValidatedInput($request, SelectActiveClientInput::class);
        $clientId = new Id(strtolower($input->clientId));

        $membership = $membershipForClientQuery->findForUserAndClient($this->requireUserId(), $clientId);
        if (null === $membership || !$membership->isActive) {
            return new JsonResponse(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $session = $request->getSession();
        $session->migrate(true);
        $session->set('active_client_id', (string) $clientId);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
