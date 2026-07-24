<?php
declare(strict_types=1);
namespace Nvoos\Core\Domain\Contract;

interface MusicGenerationInterface {
    public function generate(string $prompt, array $options = []): array;
    public function checkStatus(string $jobId): array;
    public function isAvailable(): bool;
    public function getSupportedModels(): array;
}
