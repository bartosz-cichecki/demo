<?php

declare(strict_types=1);

namespace App\Client\Ui\Http\Api;

use App\Client\Application\ClientInvitation\Command\AcceptClientInvitation\AcceptClientInvitationCommand;
use App\Client\Application\ClientInvitation\Command\CreateClientInvitation\CreateClientInvitationCommand;
use App\Client\Application\ClientInvitation\Command\RejectClientInvitation\RejectClientInvitationCommand;
use App\Client\Application\ClientInvitation\Command\RevokeClientInvitation\RevokeClientInvitationCommand;
use App\Client\Application\ClientInvitation\Query\ClientInvitationQueryInterface;
use App\Client\Application\ClientInvitation\Query\Dto\ClientInvitationDto;
use App\Client\Application\UserAccount\Query\UserAccountQueryInterface;
use App\Client\Domain\ClientInvitation\Exception\ClientInvitationNotPendingException;
use App\Client\Domain\ClientInvitation\Exception\ClientInvitationRoleNotAllowedException;
use App\Client\Domain\ClientInvitation\Exception\InviteeAlreadyMemberException;
use App\Client\Domain\ClientInvitation\Exception\PendingClientInvitationAlreadyExistsException;
use App\Client\Domain\ClientMember\Repository\Exception\ClientMemberAlreadyExistsException;
use App\Client\Ui\Input\CreateClientInvitationInput;
use App\SharedKernel\Application\CommandBus\CommandInterface;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\SharedKernel\Ui\Http\Api\AbstractController;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final readonly class ClientInvitationController extends AbstractController
{
    private const string ERROR_PENDING_EXISTS = 'A pending invitation for this email already exists';
    private const string ERROR_ALREADY_MEMBER = 'User is already a member of this client';

    #[Route('/clients/{clientId}/invitations', name: 'api_client_invitations_create', methods: ['POST'])]
    public function create(Request $request, string $clientId): JsonResponse
    {
        /** @var CreateClientInvitationInput $input */
        $input = $this->getValidatedInput($request, CreateClientInvitationInput::class);
        try {
            $email = Email::fromString($input->email);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['errors' => [['field' => 'email', 'message' => 'Email is not valid.']]], Response::HTTP_BAD_REQUEST);
        }

        $id = Id::new();
        try {
            $this->executeCommand(new CreateClientInvitationCommand($id, new Id($clientId), $email, $input->role));
        } catch (ClientInvitationRoleNotAllowedException) {
            return new JsonResponse(['error' => 'Client admin can invite only with role user'], Response::HTTP_FORBIDDEN);
        } catch (PendingClientInvitationAlreadyExistsException|UniqueConstraintViolationException) {
            return new JsonResponse(['error' => self::ERROR_PENDING_EXISTS], Response::HTTP_CONFLICT);
        } catch (InviteeAlreadyMemberException) {
            return new JsonResponse(['error' => self::ERROR_ALREADY_MEMBER], Response::HTTP_CONFLICT);
        }

        return new JsonResponse(['id' => (string) $id], Response::HTTP_CREATED);
    }

    #[Route(
        '/clients/{clientId}/invitations/{invitationId}/revoke',
        name: 'api_client_invitations_revoke',
        requirements: ['invitationId' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function revoke(string $clientId, string $invitationId): JsonResponse
    {
        try {
            $conflict = $this->executeTransition(new RevokeClientInvitationCommand(new Id($invitationId), new Id($clientId)));
        } catch (ClientInvitationRoleNotAllowedException) {
            return new JsonResponse(['error' => 'Client admin can revoke only invitations with role user'], Response::HTTP_FORBIDDEN);
        }

        return $conflict ?? new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/me/invitations', name: 'api_me_invitations_list', methods: ['GET'])]
    public function listMine(
        ClientInvitationQueryInterface $clientInvitationQuery,
        UserAccountQueryInterface $userAccountQuery,
    ): JsonResponse {
        $email = $userAccountQuery->findEmailByUserId($this->requireUserId());
        $invitations = null === $email ? [] : $clientInvitationQuery->listPendingByEmail($email);

        return new JsonResponse(array_map(
            static fn (ClientInvitationDto $invitation): array => [
                'id' => $invitation->id,
                'clientId' => $invitation->clientId,
                'clientName' => $invitation->clientName,
                'role' => $invitation->role,
                'createdAt' => $invitation->createdAt,
            ],
            $invitations,
        ));
    }

    #[Route(
        '/invitations/{invitationId}/accept',
        name: 'api_invitations_accept',
        requirements: ['invitationId' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function accept(
        Request $request,
        string $invitationId,
        ClientInvitationQueryInterface $clientInvitationQuery,
    ): JsonResponse {
        $id = new Id($invitationId);
        try {
            $conflict = $this->executeTransition(new AcceptClientInvitationCommand($id, $this->requireUserId()));
        } catch (UniqueConstraintViolationException) {
            return new JsonResponse(['error' => self::ERROR_ALREADY_MEMBER], Response::HTTP_CONFLICT);
        }
        if (null !== $conflict) {
            return $conflict;
        }

        // The command has committed: only now may the new client become the active one.
        $invitation = $clientInvitationQuery->findById($id);
        if (null === $invitation) {
            throw new \LogicException(\sprintf('Accepted invitation %s was not found.', $id));
        }

        $session = $request->getSession();
        $session->migrate(true);
        $session->set('active_client_id', $invitation->clientId);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route(
        '/invitations/{invitationId}/reject',
        name: 'api_invitations_reject',
        requirements: ['invitationId' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function reject(string $invitationId): JsonResponse
    {
        $conflict = $this->executeTransition(new RejectClientInvitationCommand(new Id($invitationId), $this->requireUserId()));

        return $conflict ?? new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Runs a status transition and maps domain refusals to 409.
     * A missing invitation or one addressed to someone else propagates as 404.
     */
    private function executeTransition(CommandInterface $command): ?JsonResponse
    {
        try {
            $this->executeCommand($command);
        } catch (ClientInvitationNotPendingException) {
            return new JsonResponse(['error' => 'Invitation is not pending'], Response::HTTP_CONFLICT);
        } catch (ClientMemberAlreadyExistsException) {
            return new JsonResponse(['error' => self::ERROR_ALREADY_MEMBER], Response::HTTP_CONFLICT);
        }

        return null;
    }
}
