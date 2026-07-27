<?php
declare(strict_types=1);
namespace Nvoos\Core\Domain\Contract;

interface CodeFormattingInterface {
    public function format(string $code, string $language = 'php', array $options = []): array;
    public function isAvailable(): bool;
    public function getSupportedLanguages(): array;
}
