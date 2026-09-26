<?php

declare(strict_types=1);

namespace App\Tests\User\Application\OtpChallenge;

use App\SharedKernel\Domain\Event\InMemoryDomainEventsCollector;
use App\SharedKernel\Domain\ValueObject\Email;
use App\Tests\User\Domain\OtpChallenge\FakeOtpChallengeOutside;
use App\User\Application\OtpChallenge\Command\RequestOtp\RequestOtpCommand;
use App\User\Application\OtpChallenge\Command\RequestOtp\RequestOtpCommandHandler;
use App\User\Application\OtpChallenge\Service\OtpCodeSenderServiceInterface;
use App\User\Domain\OtpChallenge\Factory\OtpChallengeFactory;
use App\User\Domain\OtpChallenge\OtpChallenge;
use App\User\Domain\OtpChallenge\Repository\OtpChallengeRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class RequestOtpCommandHandlerTest extends TestCase
{
    public function testAllowedRequestSavesChallengeBeforeSendingItsCode(): void
    {
        $outside = new FakeOtpChallengeOutside(new InMemoryDomainEventsCollector());
        $repository = $this->createMock(OtpChallengeRepositoryInterface::class);
        $sender = $this->createMock(OtpCodeSenderServiceInterface::class);
        $email = Email::fromString('recipient@example.com');
        $savedChallenge = null;

        $repository->expects($this->once())->method('create')->willReturnCallback(
            static function (OtpChallenge $challenge) use (&$savedChallenge): void {
                $savedChallenge = $challenge;
            },
        );
        $sender->expects($this->once())->method('send')->with($email, '123456')->willReturnCallback(
            function (Email $recipient, string $plainCode) use (&$savedChallenge): void {
                $this->assertInstanceOf(OtpChallenge::class, $savedChallenge);
                $this->assertTrue($savedChallenge->verify($plainCode, 5));
            },
        );

        $handler = new RequestOtpCommandHandler(new OtpChallengeFactory($outside), $repository, $sender);
        $handler(new RequestOtpCommand($email, '192.0.2.1'));
    }

    public function testBlockedRequestNeitherSavesNorSends(): void
    {
        $outside = new FakeOtpChallengeOutside(new InMemoryDomainEventsCollector());
        $email = Email::fromString('recipient@example.com');
        $outside->lastSentAtByEmail[(string) $email] = $outside->now();
        $repository = $this->createMock(OtpChallengeRepositoryInterface::class);
        $sender = $this->createMock(OtpCodeSenderServiceInterface::class);
        $repository->expects($this->never())->method('create');
        $sender->expects($this->never())->method('send');

        $handler = new RequestOtpCommandHandler(new OtpChallengeFactory($outside), $repository, $sender);
        $handler(new RequestOtpCommand($email));

        $this->assertSame(0, $outside->generatedCodes);
        $this->assertSame(0, $outside->hashedCodes);
    }
}
