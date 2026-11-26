<?php

namespace App\Support\Models;

use App\Models\ModelConfig;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The bundled text-model catalogue — Proposal 149 §68.
 *
 * `resources/model-catalog.json` is generated from the WordPress canonical
 * catalogue by `bin/generate-laravel-model-catalog.php`. It carries 251 chat /
 * text models with context, rate limits, per-1M input and output prices,
 * lifecycle status and a fallback.
 *
 * The JSON is the seed, the table is the working copy. `sync()` only adds
 * models that are absent: an operator edit is never silently overwritten by a
 * later sync. That is the same relationship the WordPress layer has between
 * its CCT defaults and its stored overrides, made explicit.
 *
 * Media models are not here. `media-model-catalog.json` describes capabilities
 * (kind, modes, aspect ratios, resolutions, duration) and its prices are per
 * image or per second; see §68.5.
 */
final class ModelConfigCatalogue
{
    /**
     * Parsed document, cached for the request.
     *
     * @var array{models: list<array<string, mixed>>}|null
     */
    private static ?array $document = null;

    /**
     * Human labels for the provider slugs in the catalogue.
     *
     * @var array<string, string>
     */
    private const PROVIDER_LABELS = [
        'anthropic' => 'Anthropic',
        'azure' => 'Azure OpenAI',
        'baseten' => 'Baseten',
        'cloudflare' => 'Cloudflare Workers AI',
        'deepseek' => 'DeepSeek',
        'digitalocean' => 'DigitalOcean',
        'embedded' => 'Embedded (local)',
        'gemini' => 'Google Gemini',
        'huggingface' => 'Hugging Face',
        'kimi' => 'Kimi (Moonshot AI)',
        'lm_studio' => 'LM Studio (local)',
        'nvidia' => 'NVIDIA NIM',
        'ollama' => 'Ollama (local)',
        'openai' => 'OpenAI',
        'openrouter' => 'OpenRouter',
        'typesafe' => 'TypeSafe (Jev)',
        'webllm' => 'WebLLM (browser)',
        'zai' => 'Z.AI (GLM)',
    ];

    /**
     * Absolute path to the bundled document.
     */
    public static function path(): string
    {
        return resource_path('model-catalog.json');
    }

    /**
     * The catalogue entries, in document order.
     *
     * Fails closed rather than returning an empty list: a missing or malformed
     * catalogue would make "Sync" look like it succeeded with nothing to add.
     *
     * @return list<array<string, mixed>>
     */
    public static function entries(): array
    {
        if (null !== self::$document) {
            return self::$document['models'];
        }

        $path = self::path();

        if (! is_file($path)) {
            throw new RuntimeException('Model catalogue not found at '.$path);
        }

        $document = json_decode((string) file_get_contents($path), true);

        if (! is_array($document) || ! isset($document['models']) || ! is_array($document['models'])) {
            throw new RuntimeException('Model catalogue is not a {models: []} document: '.$path);
        }

        $models = array_values(array_filter(
            $document['models'],
            static fn ($model): bool => is_array($model) && isset($model['model_id'], $model['provider'])
        ));

        self::$document = ['models' => $models];

        return $models;
    }

    /**
     * Provider slug => label, for the form and the filters.
     *
     * @return array<string, string>
     */
    public static function providerOptions(): array
    {
        $providers = array_values(array_unique(array_column(self::entries(), 'provider')));
        sort($providers);

        $options = [];

        foreach ($providers as $provider) {
            $options[$provider] = self::providerLabel($provider);
        }

        return $options;
    }

    public static function providerLabel(string $provider): string
    {
        return self::PROVIDER_LABELS[$provider] ?? ucfirst(str_replace('_', ' ', $provider));
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            'active' => 'Active',
            'preview' => 'Preview',
            'deprecated' => 'Deprecated',
            'retired' => 'Retired',
        ];
    }

    /**
     * Servable model options: model id => display name.
     *
     * The value space is the bare model id, because that is what a settings
     * field and a `fallback_model_id` store. Two providers can publish the same
     * id (`gpt-4o` is an Azure deployment and an OpenAI model), so a repeated id
     * gets its provider appended: one option per value is unavoidable, and an
     * unnamed choice between two rows would be a coin toss.
     *
     * Not cached statically: an Octane worker would keep serving a list that
     * predates a create/edit until the next deploy. The table is small and a
     * settings form asks for at most one provider's options per field.
     *
     * @return array<string, string>
     */
    public static function options(bool $includeDeprecated = false): array
    {
        try {
            if (! Schema::hasTable('nvoos_model_configs')) {
                return [];
            }

            $statuses = $includeDeprecated
                ? array_merge(ModelConfig::SERVABLE_STATUSES, ['deprecated'])
                : ModelConfig::SERVABLE_STATUSES;

            $rows = ModelConfig::query()
                ->whereIn('status', $statuses)
                ->orderBy('name')
                ->get(['model_id', 'name', 'provider', 'status']);
        } catch (\Throwable $exception) {
            // A settings form must still render before the migration has run.
            report($exception);

            return [];
        }

        $counts = $rows->countBy('model_id');
        $servable = array_flip(ModelConfig::SERVABLE_STATUSES);
        $chosen = [];

        foreach ($rows as $row) {
            $id = (string) $row->model_id;
            $isServable = isset($servable[$row->status]);

            // Two rows can share an id; the label follows the row a new
            // selection would actually get, i.e. a servable one.
            if (! isset($chosen[$id]) || ($isServable && ! $chosen[$id]['servable'])) {
                $chosen[$id] = [
                    'name' => (string) $row->name,
                    'provider' => (string) $row->provider,
                    'servable' => $isServable,
                ];
            }
        }

        $options = [];

        foreach ($chosen as $id => $row) {
            $label = $row['name'];

            if (($counts[$id] ?? 0) > 1) {
                $label .= ' - '.self::providerLabel($row['provider']);
            }

            $options[$id] = $label;
        }

        return $options;
    }

    /**
     * The servable models for one provider.
     *
     * @return array<string, string>
     */
    public static function optionsForProvider(string $provider, bool $includeDeprecated = false): array
    {
        try {
            $ids = ModelConfig::query()
                ->where('provider', $provider)
                ->whereIn('status', $includeDeprecated
                    ? array_merge(ModelConfig::SERVABLE_STATUSES, ['deprecated'])
                    : ModelConfig::SERVABLE_STATUSES)
                ->pluck('model_id')
                ->all();
        } catch (\Throwable $exception) {
            report($exception);

            return [];
        }

        return array_intersect_key(self::options($includeDeprecated), array_flip($ids));
    }

    /**
     * Resolve a fallback id against the row that names it.
     *
     * A fallback is stored as a bare id, matching the WordPress layer, so an id
     * that two providers both publish needs a tie-break. The naming row's own
     * provider wins, which is what an operator means by "fall back to gpt-4o"
     * on an Azure row; otherwise the first row in name order is used.
     */
    public static function resolveFallback(string $provider, ?string $modelId): ?ModelConfig
    {
        if (null === $modelId || '' === $modelId) {
            return null;
        }

        return ModelConfig::query()
            ->where('model_id', $modelId)
            ->orderByRaw('case when provider = ? then 0 else 1 end', [$provider])
            ->orderBy('name')
            ->first();
    }

    /**
     * Fallback options, keeping a current value that is no longer servable so
     * an existing row stays editable.
     *
     * @return array<string, string>
     */
    public static function fallbackOptions(?string $current = null): array
    {
        $options = self::options();

        if (null !== $current && '' !== $current && ! array_key_exists($current, $options)) {
            $name = null;

            try {
                $name = ModelConfig::query()->where('model_id', $current)->value('name');
            } catch (\Throwable $exception) {
                report($exception);
            }

            $options[$current] = (is_string($name) && '' !== $name ? $name : $current).' (current)';
        }

        return $options;
    }

    /**
     * Insert the models that are not in the table yet.
     *
     * Identity is the `(provider, model_id)` pair, not the id alone: the same id
     * may be published by two providers. Existing rows are never updated. A
     * sync that silently overwrote an operator's price edit would be the
     * settings-port bug in reverse: the data would look refreshed while the
     * deployment still used the edit.
     *
     * @return array{added: int, existing: int}
     */
    public static function sync(): array
    {
        $entries = self::entries();

        $pair = static fn (array $entry): string => ((string) $entry['provider']).'|'.((string) $entry['model_id']);

        $wanted = [];

        foreach ($entries as $entry) {
            $wanted[$pair($entry)] = true;
        }

        $present = [];

        $existing = ModelConfig::query()
            ->whereIn('model_id', array_column($entries, 'model_id'))
            ->get(['provider', 'model_id']);

        foreach ($existing as $row) {
            $key = ((string) $row->provider).'|'.((string) $row->model_id);

            if (isset($wanted[$key])) {
                $present[$key] = true;
            }
        }

        $now = now();

        $rows = [];
        $seen = [];

        foreach ($entries as $entry) {
            $key = $pair($entry);

            if (isset($present[$key]) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $modelId = (string) $entry['model_id'];
            $fallback = (string) ($entry['fallback_model_id'] ?? '');
            $sunset = (string) ($entry['sunset_date'] ?? '');
            $tier = (string) ($entry['tier'] ?? '');
            $notes = (string) ($entry['notes'] ?? '');
            $status = (string) ($entry['status'] ?? 'active');

            $rows[] = [
                'model_id' => $modelId,
                'name' => (string) ($entry['name'] ?? $modelId),
                'provider' => (string) $entry['provider'],
                'context_window' => (int) ($entry['context_window'] ?? 0),
                'max_output_tokens' => (int) ($entry['max_output_tokens'] ?? 0),
                'tpm_limit' => (int) ($entry['tpm_limit'] ?? 0),
                'rpm_limit' => (int) ($entry['rpm_limit'] ?? 0),
                'tpd_limit' => (int) ($entry['tpd_limit'] ?? 0),
                'rpd_limit' => (int) ($entry['rpd_limit'] ?? 0),
                'input_cost_per_million' => (float) ($entry['input_cost_per_million'] ?? 0),
                'output_cost_per_million' => (float) ($entry['output_cost_per_million'] ?? 0),
                'fallback_model_id' => '' !== $fallback ? $fallback : null,
                'status' => in_array($status, ModelConfig::STATUSES, true) ? $status : 'active',
                'sunset_date' => '' !== $sunset ? $sunset : null,
                'supports_streaming' => (int) (bool) ($entry['supports_streaming'] ?? false),
                'supports_function_calling' => (int) (bool) ($entry['supports_function_calling'] ?? false),
                'supports_vision' => (int) (bool) ($entry['supports_vision'] ?? false),
                'tier' => '' !== $tier ? $tier : null,
                'notes' => '' !== $notes ? $notes : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            ModelConfig::query()->insertOrIgnore($chunk);
        }

        return ['added' => count($rows), 'existing' => count($present)];
    }
}
