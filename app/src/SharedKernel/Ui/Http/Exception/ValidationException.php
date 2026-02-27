<?php

declare(strict_types=1);

namespace App\SharedKernel\Ui\Http\Exception;

use Symfony\Component\Validator\ConstraintViolationListInterface;

final class ValidationException extends \RuntimeException
{
    /** @var array<array{field: string, message: string}> */
    private array $errors;

    public function __construct(ConstraintViolationListInterface $violations)
    {
        $this->errors = [];
        foreach ($violations as $violation) {
            $field = trim((string) $violation->getPropertyPath(), '[]');
            $this->errors[] = [
                'field' => $field,
                'message' => (string) $violation->getMessage(),
            ];
        }
        parent::__construct('Validation failed');
    }

    /** @return array<array{field: string, message: string}> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
