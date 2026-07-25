<?php
declare(strict_types=1);
namespace Nvoos\Core\Domain\Contract;

interface LanguageDetectionInterface {
    public function detect(string $text): array;
    public function getLanguageName(string $isoCode): string;
    public function getSupportedLanguages(): array;
}
