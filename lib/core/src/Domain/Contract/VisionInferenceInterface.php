<?php
declare(strict_types=1);
namespace Nvoos\Core\Domain\Contract;

interface VisionInferenceInterface {
    public function infer(string $imagePath, string $prompt = '', array $options = []): array;
    public function getAvailableModels(): array;
    public function isAvailable(): bool;
}
