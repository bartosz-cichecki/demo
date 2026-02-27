<?php

declare(strict_types=1);

namespace App\User\Infrastructure\User;

use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Domain\User\Repository\Exception\UserDoesNotExistException;
use App\User\Domain\User\Repository\UserRepositoryInterface;
use App\User\Domain\User\User;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UserRepository implements UserRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function create(User $user): void
    {
        $this->em->persist($user);
    }

    public function get(Id $id): User
    {
        $user = $this->em->find(User::class, $id);
        if (null === $user) {
            throw new UserDoesNotExistException($id);
        }

        return $user;
    }

    public function findByEmail(Email $email): ?User
    {
        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }
}
