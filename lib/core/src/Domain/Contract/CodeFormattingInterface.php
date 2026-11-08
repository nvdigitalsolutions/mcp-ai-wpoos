<?php
declare(strict_types=1);
namespace Nvoos\Core\Domain\Contract;

interface CodeFormattingInterface {
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function format(string $code, string $language = 'php', array $options = []): array;
    public function isAvailable(): bool;
    /**
     * @return array<int, string>
     */
    public function getSupportedLanguages(): array;
}
