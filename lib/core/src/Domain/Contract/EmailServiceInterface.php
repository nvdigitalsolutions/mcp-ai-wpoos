<?php
declare(strict_types=1);
namespace Nvoos\Core\Domain\Contract;

interface EmailServiceInterface {
    public function send(array $message): array;
    public function isAvailable(): bool;
    public function validateRecipient(string $email): bool;
}
