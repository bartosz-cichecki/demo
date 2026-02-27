<?php

declare(strict_types=1);

namespace App\Client\Ui\Http\Api;

use App\Client\Application\Client\Command\CreateClient\CreateClientCommand;
use App\Client\Ui\Input\CreateClientInput;
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
        $id = Id::new();
        $this->executeCommand(
            new CreateClientCommand(
                $id,
                $input->name,
                $input->description,
            ),
        );

        return new JsonResponse([
            'id' => (string) $id,
        ], Response::HTTP_CREATED);
    }
}
