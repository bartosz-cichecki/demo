<?php

declare(strict_types=1);

namespace App\Client\Ui\Http\Api;

use App\Client\Application\Client\Command\OnboardClient\OnboardClientCommand;
use App\Client\Ui\Input\CreateClientInput;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\SharedKernel\Ui\Http\Api\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ClientController extends AbstractController
{
    #[Route('/clients', name: 'platform_clients_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var CreateClientInput $input */
        $input = $this->getValidatedInput($request, CreateClientInput::class);
        try {
            $email = Email::fromString($input->adminEmail);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['errors' => [['field' => 'adminEmail', 'message' => 'Email is not valid.']]], Response::HTTP_BAD_REQUEST);
        }
        $id = Id::new();
        $this->executeCommand(
            new OnboardClientCommand(
                $id,
                $input->name,
                $input->description,
                $email,
            ),
        );

        return new JsonResponse([
            'id' => (string) $id,
        ], Response::HTTP_CREATED);
    }
}
