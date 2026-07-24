<?php
declare(strict_types=1);
namespace Nvoos\Core\Domain\Contract;

interface ValidationServiceInterface {
    public function validateEmail(string $email): array;
    public function validatePhone(string $phone, string $countryCode = ''): array;
    public function validateUrl(string $url): array;
    public function validateCreditCard(string $number): array;
    public function sanitize(string $value, string $type = 'text'): string;
    public function isAvailable(): bool;
}
