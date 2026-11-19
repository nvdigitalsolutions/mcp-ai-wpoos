<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 149 §73 — routing metric retention and export.
 *
 * Two properties matter and are easy to get wrong in opposite directions:
 *
 *  - **retention deletes only what is outside the window**, and says how much
 *    before it does it (`--dry-run`); disabling it (0 days) must delete nothing;
 *  - **the export is faithful and portable** — flat events for tools, OTLP/JSON
 *    log records with the GenAI attribute names for a collector, and neither
 *    invents a model on a provider hop.
 */
class RoutingRetentionExportTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'nvoos_routing_metrics';

    public function test_pruning_deletes_only_rows_outside_the_window(): void
    {
        $this->insert(['created_at' => now()->subDays(40)]);
        $this->insert(['created_at' => now()->subDays(5)]);

        $this->artisan('nvoos:prune-routing-metrics', ['--days' => 30])
            ->assertSuccessful();

        $this->assertSame(1, DB::table(self::TABLE)->count());
        $this->assertSame(
            1,
            DB::table(self::TABLE)->where('created_at', '>=', now()->subDays(10))->count()
        );
    }

    public function test_a_dry_run_counts_without_deleting(): void
    {
        $this->insert(['created_at' => now()->subDays(40)]);

        $this->artisan('nvoos:prune-routing-metrics', ['--days' => 30, '--dry-run' => true])
            ->expectsOutputToContain('would be deleted')
            ->assertSuccessful();

        $this->assertSame(1, DB::table(self::TABLE)->count());
    }

    public function test_zero_retention_disables_pruning(): void
    {
        $this->insert(['created_at' => now()->subDays(400)]);

        $this->artisan('nvoos:prune-routing-metrics', ['--days' => 0])
            ->expectsOutputToContain('disabled')
            ->assertSuccessful();

        $this->assertSame(1, DB::table(self::TABLE)->count());
    }

    public function test_the_flat_export_writes_both_kinds(): void
    {
        $this->insert(['created_at' => now()->subMinute()]);
        $this->insert([
            'kind' => 'provider_hop',
            'provider' => 'gemini',
            'from_provider' => 'openai',
            'from_model' => '',
            'to_model' => '',
            'succeeded' => false,
            'created_at' => now()->subMinute(),
        ]);

        $path = $this->path();

        $this->artisan('nvoos:export-routing-metrics', [
            '--format' => 'json',
            '--days' => 30,
            '--path' => $path,
        ])->assertSuccessful();

        $document = json_decode((string) file_get_contents($path), true);

        $this->assertSame(30, $document['window_days']);
        $this->assertCount(2, $document['events']);

        $fallback = $document['events'][0];
        $this->assertSame(1, $fallback['id'], 'The metric row id is the idempotency key.');
        $this->assertSame('model_fallback', $fallback['kind']);
        $this->assertSame('gpt-5', $fallback['from_model']);
        $this->assertSame('gpt-5-mini', $fallback['to_model']);
        $this->assertNull($fallback['from_provider']);
        $this->assertTrue($fallback['succeeded']);

        $hop = $document['events'][1];
        $this->assertSame('provider_hop', $hop['kind']);
        $this->assertSame('openai', $hop['from_provider']);
        $this->assertSame('gemini', $hop['provider']);
        $this->assertNull($hop['from_model'], 'A hop has no model to report as a model.');
        $this->assertFalse($hop['succeeded']);

        File::delete($path);
    }

    public function test_the_otel_export_uses_the_gen_ai_attribute_names(): void
    {
        $this->insert(['created_at' => now()->subMinute()]);
        $this->insert([
            'kind' => 'provider_hop',
            'provider' => 'gemini',
            'from_provider' => 'openai',
            'from_model' => '',
            'to_model' => '',
            'succeeded' => false,
            'created_at' => now()->subMinute(),
        ]);

        $path = $this->path();

        $this->artisan('nvoos:export-routing-metrics', [
            '--format' => 'otel',
            '--days' => 30,
            '--path' => $path,
        ])->assertSuccessful();

        $document = json_decode((string) file_get_contents($path), true);

        $resource = $document['resourceLogs'][0];
        $this->assertSame('service.name', $resource['resource']['attributes'][0]['key']);
        $this->assertSame('nvoos-runtime', $resource['resource']['attributes'][0]['value']['stringValue']);

        $scope = $resource['scopeLogs'][0];
        $this->assertSame('nvoos.routing', $scope['scope']['name']);

        $records = $scope['logRecords'];
        $this->assertCount(2, $records);

        $fallback = $records[0];
        $attributes = $this->attributes($fallback);

        $this->assertSame('openai', $attributes['gen_ai.provider.name']);
        $this->assertSame('gpt-5', $attributes['gen_ai.request.model']);
        $this->assertSame('gpt-5-mini', $attributes['gen_ai.response.model']);
        $this->assertSame('model_fallback', $attributes['nvoos.routing.kind']);
        $this->assertTrue($attributes['nvoos.routing.succeeded']);
        $this->assertSame('INFO', $fallback['severityText']);
        $this->assertSame(9, $fallback['severityNumber']);
        $this->assertMatchesRegularExpression('/^\d{19}$/', (string) $fallback['timeUnixNano']);

        $hop = $records[1];
        $hopAttributes = $this->attributes($hop);

        $this->assertSame('gemini', $hopAttributes['gen_ai.provider.name']);
        $this->assertSame('openai', $hopAttributes['nvoos.routing.from_provider']);
        $this->assertArrayNotHasKey('gen_ai.request.model', $hopAttributes);
        $this->assertSame('WARN', $hop['severityText']);
        $this->assertSame(13, $hop['severityNumber']);

        File::delete($path);
    }

    public function test_an_unknown_format_fails(): void
    {
        $this->artisan('nvoos:export-routing-metrics', ['--format' => 'csv'])
            ->assertFailed();
    }

    /**
     * Flatten OTLP attributes into key => value.
     *
     * @param array<string, mixed> $record OTLP log record.
     * @return array<string, mixed>
     */
    private function attributes(array $record): array
    {
        $flat = [];

        foreach ((array) ($record['attributes'] ?? []) as $attribute) {
            $value = $attribute['value'] ?? [];
            $flat[(string) $attribute['key']] = $value['stringValue']
                ?? $value['boolValue']
                ?? $value['intValue']
                ?? null;
        }

        return $flat;
    }

    /**
     * @param array<string, mixed> $overrides Column overrides.
     */
    private function insert(array $overrides = []): void
    {
        DB::table(self::TABLE)->insert(array_merge([
            'kind' => 'model_fallback',
            'provider' => 'openai',
            'from_provider' => null,
            'from_model' => 'gpt-5',
            'to_model' => 'gpt-5-mini',
            'succeeded' => true,
            'created_at' => now(),
        ], $overrides));
    }

    private function path(): string
    {
        $directory = storage_path('framework/testing');
        File::ensureDirectoryExists($directory);

        return $directory.'/routing-export-'.uniqid('', true).'.json';
    }
}
