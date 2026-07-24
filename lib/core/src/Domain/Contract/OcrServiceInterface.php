<?php
declare(strict_types=1);
namespace Nvoos\Core\Domain\Contract;

interface OcrServiceInterface {
    public function extractText(string $filePath, array $options = []): array;
    public function getAvailableProviders(): array;
    public function isAvailable(): bool;
}
