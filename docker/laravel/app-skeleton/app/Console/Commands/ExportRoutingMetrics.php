<?php

namespace App\Console\Commands;

use App\Support\Diagnostics\RoutingEventExporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Export routing metrics — Proposal 149 §73.
 *
 * The diagnostics page answers "how many". This answers "which ones", in a
 * shape another tool can ingest: flat JSON for spreadsheets and `jq`, or
 * OTLP/JSON log records for a collector (see RoutingEventExporter for the
 * attribute mapping).
 *
 * Stdout by default, `--path` to write a file — the default keeps it usable in
 * a pipe, and the file form is what a cron job would use.
 */
class ExportRoutingMetrics extends Command
{
    private const TABLE = 'nvoos_routing_metrics';

    protected $signature = 'nvoos:export-routing-metrics
                            {--days=30 : Window in days}
                            {--format=json : json (flat events) or otel (OTLP/JSON log records)}
                            {--path= : Write to this file instead of stdout}';

    protected $description = 'Export routing deviations (model fallbacks and provider hops) as JSON.';

    public function handle(RoutingEventExporter $exporter): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['json', 'otel'], true)) {
            $this->error(sprintf('Unknown format "%s"; use json or otel.', $format));

            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));

        if (! Schema::hasTable(self::TABLE)) {
            $this->warn('Routing metrics table is not present; nothing to export.');

            return self::SUCCESS;
        }

        try {
            $rows = DB::table(self::TABLE)
                ->where('created_at', '>=', now()->subDays($days - 1)->startOfDay())
                ->orderBy('id')
                ->get();
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('Could not read routing metrics: '.$exception->getMessage());

            return self::FAILURE;
        }

        $payload = 'otel' === $format
            ? $exporter->otel($rows)
            : $exporter->flat($rows, $days);

        try {
            $json = json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            report($exception);
            $this->error('Could not encode the export: '.$exception->getMessage());

            return self::FAILURE;
        }

        $path = (string) $this->option('path');

        if ('' === $path) {
            $this->line($json);

            return self::SUCCESS;
        }

        try {
            if (false === file_put_contents($path, $json."\n")) {
                throw new InvalidArgumentException('file_put_contents returned false.');
            }
        } catch (\Throwable $exception) {
            report($exception);
            $this->error(sprintf('Could not write %s: %s', $path, $exception->getMessage()));

            return self::FAILURE;
        }

        $this->info(sprintf('%d routing event(s) written to %s.', count($rows), $path));

        return self::SUCCESS;
    }
}
