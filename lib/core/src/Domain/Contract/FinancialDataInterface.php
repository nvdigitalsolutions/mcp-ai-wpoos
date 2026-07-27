<?php
declare(strict_types=1);
namespace Nvoos\Core\Domain\Contract;

interface FinancialDataInterface {
    public function getQuote(string $symbol): array;
    public function getHistory(string $symbol, string $period = '1mo'): array;
    public function search(string $query): array;
    public function isAvailable(): bool;
}
