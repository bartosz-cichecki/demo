<?php

declare(strict_types=1);

namespace App\User\Ui\Http\Api;

use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Ui\Http\Api\AbstractController;
use App\User\Application\OtpChallenge\Command\RequestOtp\RequestOtpCommand;
use App\User\Application\OtpChallenge\Command\VerifyOtp\VerifyOtpCommand;
use App\User\Application\User\Query\UserQueryInterface;
use App\User\Ui\Input\RequestOtpInput;
use App\User\Ui\Input\VerifyOtpInput;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class OtpAuthController extends AbstractController
{
    #[Route('/auth/otp/request', name: 'api_auth_otp_request', methods: ['POST'])]
    public function requestOtp(Request $request): JsonResponse
    {
        /** @var RequestOtpInput $input */
        $input = $this->getValidatedInput($request, RequestOtpInput::class);
        try {
            $email = Email::fromString($input->email);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['ok' => true]);
        }

        $this->executeCommand(new RequestOtpCommand(
            $email,
            $request->getClientIp(),
            $request->headers->get('User-Agent'),
        ));

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/auth/otp/verify', name: 'api_auth_otp_verify', methods: ['POST'])]
    public function verifyOtp(Request $request, UserQueryInterface $userQuery): JsonResponse
    {
        /** @var VerifyOtpInput $input */
        $input = $this->getValidatedInput($request, VerifyOtpInput::class);
        try {
            $email = Email::fromString($input->email);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['ok' => false]);
        }

        $result = $this->executeCommandWithResult(new VerifyOtpCommand($email, $input->code));
        if (!$result->verified) {
            return new JsonResponse(['ok' => false]);
        }

        $user = $userQuery->findByEmail($email);
        if (null === $user) {
            return new JsonResponse(['ok' => false]);
        }

        $session = $request->getSession();
        $session->migrate(true);
        $session->set('user_id', $user->id);

        return new JsonResponse(['ok' => true]);
    }
}
