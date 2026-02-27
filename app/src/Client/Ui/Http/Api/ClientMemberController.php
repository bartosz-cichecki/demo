<?php

declare(strict_types=1);

namespace App\Client\Ui\Http\Api;

use App\Client\Application\ClientMember\Command\ProvisionClientMember\ProvisionClientMemberCommand;
use App\Client\Application\ClientMember\Command\ReplaceClientMemberRoles\ReplaceClientMemberRolesCommand;
use App\Client\Application\ClientMember\Command\SuspendClientMember\SuspendClientMemberCommand;
use App\Client\Application\ClientMember\Command\UnsuspendClientMember\UnsuspendClientMemberCommand;
use App\Client\Application\ClientMember\Query\ClientMemberQueryInterface;
use App\Client\Domain\ClientMember\Repository\Exception\ClientMemberAlreadyExistsException;
use App\Client\Ui\Input\ProvisionClientMemberInput;
use App\Client\Ui\Input\ReplaceClientMemberRolesInput;
use App\SharedKernel\Domain\ValueObject\Id;
use App\SharedKernel\Ui\Http\Api\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ClientMemberController extends AbstractController
{
    #[Route('/client-members', name: 'api_client_members_provision', methods: ['POST'])]
    public function provision(Request $request): JsonResponse
    {
        /** @var ProvisionClientMemberInput $input */
        $input = $this->getValidatedInput($request, ProvisionClientMemberInput::class);

        $activeClientId = $this->requireActiveClientId();

        try {
            $this->executeCommand(
                new ProvisionClientMemberCommand(
                    $activeClientId,
                    $input->email,
                ),
            );
        } catch (ClientMemberAlreadyExistsException) {
            return new JsonResponse(
                ['error' => 'Member already exists for this client'],
                Response::HTTP_CONFLICT,
            );
        }

        return new JsonResponse(null, Response::HTTP_CREATED);
    }

    #[Route('/clients/{clientId}/members', name: 'api_client_members_list', methods: ['GET'])]
    public function list(
        string $clientId,
        ClientMemberQueryInterface $clientMemberQuery,
    ): JsonResponse {
        $members = $clientMemberQuery->listByClient(new Id($clientId));

        return new JsonResponse(array_map(
            static fn ($dto) => [
                'id' => $dto->id,
                'clientId' => $dto->clientId,
                'userId' => $dto->userId,
                'roles' => $dto->roles,
                'status' => $dto->status,
                'createdAt' => $dto->createdAt,
                'updatedAt' => $dto->updatedAt,
            ],
            $members,
        ));
    }

    #[Route('/clients/{clientId}/members/{userId}/roles', name: 'api_client_members_replace_roles', methods: ['PUT'])]
    public function replaceRoles(
        Request $request,
        string $clientId,
        string $userId,
    ): JsonResponse {
        /** @var ReplaceClientMemberRolesInput $input */
        $input = $this->getValidatedInput($request, ReplaceClientMemberRolesInput::class);

        $this->executeCommand(
            new ReplaceClientMemberRolesCommand(
                new Id($clientId),
                new Id($userId),
                $input->roles,
            ),
        );

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/clients/{clientId}/members/{userId}/suspend', name: 'api_client_members_suspend', methods: ['POST'])]
    public function suspend(
        string $clientId,
        string $userId,
    ): JsonResponse {
        $this->executeCommand(
            new SuspendClientMemberCommand(
                new Id($clientId),
                new Id($userId),
            ),
        );

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/clients/{clientId}/members/{userId}/unsuspend', name: 'api_client_members_unsuspend', methods: ['POST'])]
    public function unsuspend(
        string $clientId,
        string $userId,
    ): JsonResponse {
        $this->executeCommand(
            new UnsuspendClientMemberCommand(
                new Id($clientId),
                new Id($userId),
            ),
        );

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
