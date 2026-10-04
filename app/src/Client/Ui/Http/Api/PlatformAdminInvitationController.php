<?php

declare(strict_types=1);

namespace App\Client\Ui\Http\Api;

use App\Client\Application\ClientInvitation\Command\InviteClientAdmin\InviteClientAdminCommand;
use App\Client\Application\ClientInvitation\Command\RevokeClientAdminInvitation\RevokeClientAdminInvitationCommand;
use App\Client\Domain\Client\Repository\Exception\ClientDoesNotExistException;
use App\Client\Domain\ClientInvitation\Exception\ClientInvitationRoleNotAllowedException;
use App\Client\Domain\ClientInvitation\Exception\InviteeAlreadyMemberException;
use App\Client\Domain\ClientInvitation\Exception\PendingClientInvitationAlreadyExistsException;
use App\Client\Ui\Input\AdminInvitationInput;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\SharedKernel\Ui\Http\Api\AbstractController;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final readonly class PlatformAdminInvitationController extends AbstractController
{
    #[Route('/clients/{clientId}/admin-invitations', name: 'platform_client_admin_invitations_create', requirements: ['clientId' => Requirement::UUID], methods: ['POST'])]
    public function create(Request $request, string $clientId): JsonResponse
    {
        $email = $this->email($request);
        if ($email instanceof JsonResponse) {
            return $email;
        }
        $id = Id::new();
        try {
            $this->executeCommand(new InviteClientAdminCommand($id, new Id($clientId), $email));
        } catch (ClientDoesNotExistException) {
            return new JsonResponse(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        } catch (PendingClientInvitationAlreadyExistsException|UniqueConstraintViolationException) {
            return new JsonResponse(['error' => 'A pending invitation for this email already exists'], Response::HTTP_CONFLICT);
        } catch (InviteeAlreadyMemberException) {
            return new JsonResponse(['error' => 'User is already a member of this client'], Response::HTTP_CONFLICT);
        }

        return new JsonResponse(['id' => (string) $id], Response::HTTP_CREATED);
    }

    #[Route('/clients/{clientId}/admin-invitations/revoke', name: 'platform_client_admin_invitations_revoke', requirements: ['clientId' => Requirement::UUID], methods: ['POST'])]
    public function revoke(Request $request, string $clientId): JsonResponse
    {
        $email = $this->email($request);
        if ($email instanceof JsonResponse) {
            return $email;
        }
        try {
            $this->executeCommand(new RevokeClientAdminInvitationCommand(new Id($clientId), $email));
        } catch (ClientInvitationRoleNotAllowedException) {
            return new JsonResponse(['error' => 'Platform admin can revoke only invitations with role admin'], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function email(Request $request): Email|JsonResponse
    {
        /** @var AdminInvitationInput $input */
        $input = $this->getValidatedInput($request, AdminInvitationInput::class);
        try {
            return Email::fromString($input->email);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['errors' => [['field' => 'email', 'message' => 'Email is not valid.']]], Response::HTTP_BAD_REQUEST);
        }
    }
}
