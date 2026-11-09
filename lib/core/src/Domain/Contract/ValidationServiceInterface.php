<?php

/**
 * File header.
 *
 * @package WP_MCP_AI
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Nvoos\Core\Domain\Contract;

interface ValidationServiceInterface {
    /**
     * @return array{valid: bool, error?: string}
     */
    public function validateEmail(string $email): array;
    /**
     * @return array{valid: bool, error?: string}
     */
    public function validatePhone(string $phone, string $countryCode = ''): array;
    /**
     * @return array{valid: bool, error?: string}
     */
    public function validateUrl(string $url): array;
    /**
     * @return array{valid: bool, error?: string}
     */
    public function validateCreditCard(string $number): array;
    public function sanitize(string $value, string $type = 'text'): string;
    public function isAvailable(): bool;
}
