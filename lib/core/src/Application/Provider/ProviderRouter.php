<?php

/**
 * Provider router — routes chat requests to the correct AI provider.
 *
 * Selects the appropriate provider client based on the assistant's
 * configured provider (openai, gemini, anthropic, etc.) and falls
 * back to the site default when not specified.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Provider;

use Nvoos\Core\Domain\Contract\ModelRoutingCatalogueInterface;
use Nvoos\Core\Domain\Contract\RoutingMetricsInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Infrastructure\Provider\AbstractProviderClient;

class ProviderRouter
{
    /**
     * Registered provider clients, keyed by provider slug.
     *
     * @var array<string, AbstractProviderClient>
     */
    private array $providers = array();

    /**
     * Health tracker for provider failover.
     *
     * @var ProviderHealthTracker|null
     */
    private ?ProviderHealthTracker $healthTracker = null;

    /**
     * Ordered fallback chain (Proposal 091, Track D) — normalized slugs.
     *
     * @var string[]
     */
    private array $fallbackChain = array();

    /**
     * Provider cooldown expiry timestamps (unix seconds).
     *
     * @var array<string, int>
     */
    private array $cooldowns = array();

    /**
     * Optional model-routing catalogue (Proposal 149 §69-§70).
     *
     * When absent, a model-level failure goes straight to provider failover,
     * which is the behaviour before the catalogue existed.
     *
     * @var ModelRoutingCatalogueInterface|null
     */
    private ?ModelRoutingCatalogueInterface $modelCatalogue = null;

    /**
     * Optional routing metrics sink (Proposal 149 §71).
     *
     * A fallback succeeds silently; this is where it stops being invisible.
     *
     * @var RoutingMetricsInterface|null
     */
    private ?RoutingMetricsInterface $metrics = null;

    /**
     * Default cooldown TTL when the provider sends no Retry-After.
     *
     * @since 1.2.13
     *
     * @var int
     */
    private const DEFAULT_COOLDOWN_SECONDS = 60;

    /**
     * Hard cap on cooldown TTLs (never cool a provider down longer).
     *
     * @since 1.2.13
     *
     * @var int
     */
    private const MAX_COOLDOWN_SECONDS = 300;

    public function __construct(
        private readonly SettingsStoreInterface $settings,
        private readonly ErrorFactoryInterface $errors,
    ) {
    }

    /**
     * Set the health tracker for failover support.
     *
     * @since 1.2.5
     *
     * @param ProviderHealthTracker $tracker Health tracker instance.
     * @return void
     */
    public function setHealthTracker(ProviderHealthTracker $tracker): void
    {
        $this->healthTracker = $tracker;
    }

    /**
     * Set the ordered fallback chain (Proposal 091, Track D).
     *
     * Slugs are normalized and de-duplicated; unknown slugs are kept for
     * late registration but skipped at failover time.
     *
     * @since 1.2.13
     *
     * @param string[] $slugs Ordered provider slugs.
     * @return void
     */
    public function setFallbackChain(array $slugs): void
    {
        $normalized = array();
        foreach ($slugs as $slug) {
            $slug = $this->normalizeSlug((string) $slug);
            if ('' !== $slug && ! \in_array($slug, $normalized, true)) {
                $normalized[] = $slug;
            }
        }
        $this->fallbackChain = $normalized;
    }

    /**
     * Attach a model-routing catalogue (Proposal 149 §69).
     *
     * The catalogue answers two questions — "which servable sibling model may
     * I retry on this provider?" and "does this provider publish this id?" —
     * and the router asks them before abandoning a provider. Without one,
     * behaviour is unchanged.
     *
     * @since 1.5.0
     *
     * @param ModelRoutingCatalogueInterface $catalogue Catalogue implementation.
     * @return void
     */
    public function setModelCatalogue(ModelRoutingCatalogueInterface $catalogue): void
    {
        $this->modelCatalogue = $catalogue;
    }

    /**
     * The attached model-routing catalogue, if any.
     *
     * @since 1.5.0
     */
    public function getModelCatalogue(): ?ModelRoutingCatalogueInterface
    {
        return $this->modelCatalogue;
    }

    /**
     * Attach a metrics sink for fallback attempts (Proposal 149 §71).
     *
     * @since 1.7.0
     */
    public function setMetrics(RoutingMetricsInterface $metrics): void
    {
        $this->metrics = $metrics;
    }

    /**
     * The attached metrics sink, if any.
     *
     * @since 1.7.0
     */
    public function getMetrics(): ?RoutingMetricsInterface
    {
        return $this->metrics;
    }

    /**
     * Get the configured ordered fallback chain.
     *
     * @since 1.2.13
     *
     * @return string[]
     */
    public function getFallbackChain(): array
    {
        return $this->fallbackChain;
    }

    /**
     * Cool a provider down for a TTL (Proposal 091, Track D).
     *
     * @since 1.2.13
     *
     * @param string $slug       Provider slug.
     * @param int    $ttlSeconds Cooldown seconds (clamped ≥ 1).
     * @return void
     */
    public function coolDown(string $slug, int $ttlSeconds = self::DEFAULT_COOLDOWN_SECONDS): void
    {
        $slug                          = $this->normalizeSlug($slug);
        $this->cooldowns[ $slug ] = \time() + \max(1, $ttlSeconds);
    }

    /**
     * Whether a provider is currently cooled down.
     *
     * @since 1.2.13
     *
     * @param string $slug Provider slug.
     * @return bool
     */
    public function isCooledDown(string $slug): bool
    {
        $slug = $this->normalizeSlug($slug);
        return isset($this->cooldowns[ $slug ]) && $this->cooldowns[ $slug ] > \time();
    }

    /**
     * Check if provider failover is enabled and available.
     *
     * @since 1.2.5
     *
     * @return bool True when failover is active.
     */
    private function failoverEnabled(): bool
    {
        if (null === $this->healthTracker) {
            return false;
        }

        $settings = $this->settings->get('enable_provider_failover', false);
        return (bool) $settings;
    }

    /**
     * Register a provider client.
     */
    public function register(AbstractProviderClient $client): void
    {
        $this->providers[ $client->getProviderSlug() ] = $client;
    }

    /**
     * Get a specific provider client by slug.
     *
     * @return AbstractProviderClient|null  Null if provider not registered.
     */
    public function get(string $slug): ?AbstractProviderClient
    {
        return $this->providers[ $slug ] ?? null;
    }

    /**
     * Get all registered provider slugs.
     *
     * @return string[]
     */
    public function getRegisteredSlugs(): array
    {
        return \array_keys($this->providers);
    }

    /**
     * Resolve the provider client to use for a chat request.
     *
     * Priority:
     *  1. options['provider'] — explicitly requested
     *  2. assistantConfig['provider'] — the assistant's configured provider
     *  3. site default — as configured in settings
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $assistantConfig
     * @return AbstractProviderClient|null  Null if no provider resolves.
     */
    public function resolveForChat(array $options = array(), array $assistantConfig = array()): ?AbstractProviderClient
    {
        $providerSlug = '';

        if (! empty($options['provider'])) {
            $providerSlug = (string) $options['provider'];
        } elseif (! empty($assistantConfig['provider'])) {
            $providerSlug = (string) $assistantConfig['provider'];
        } else {
            $providerSlug = $this->settings->getDefaultProvider();
        }

        // Normalize aliases.
        $providerSlug = $this->normalizeSlug($providerSlug);

        return $this->providers[ $providerSlug ] ?? null;
    }

    /**
     * Send a chat completion through the appropriate provider.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed> $options
     * @param array<string, mixed> $assistantConfig
     * @return mixed  Provider response or error.
     */
    public function chat(
        array $messages,
        array $options = array(),
        array $assistantConfig = array(),
    ): mixed {
        $provider = $this->resolveForChat($options, $assistantConfig);

        if (null === $provider) {
            $slug = $options['provider'] ?? $assistantConfig['provider'] ?? 'unknown';
            return $this->errors->create(
                'provider_not_found',
                "AI provider '{$slug}' is not registered or configured.",
                array( 'status' => 400 ),
            );
        }

        $result = $provider->chat($messages, $options);

        // Record outcome and attempt failover on error.
        $slug = $provider->getProviderSlug();
        if ($this->isError($result)) {
            if ($this->healthTracker) {
                $this->healthTracker->recordFailure($slug, $this->classifyError($result));
            }

            // A single model can fail while its provider is healthy: retry the
            // catalogue's servable sibling before giving up on the provider.
            $modelFallback = $this->attemptModelFallback($messages, $options, $assistantConfig, $provider);
            if (null !== $modelFallback) {
                return $modelFallback;
            }

            $fallback = $this->attemptFailover($messages, $options, $assistantConfig, $slug, null, $result);
            if (null !== $fallback) {
                return $fallback;
            }
        } else {
            if ($this->healthTracker) {
                $this->healthTracker->recordSuccess($slug, 0);
            }
        }

        return $result;
    }

    /**
     * Stream a chat completion through the appropriate provider.
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed> $options
     * @param array<string, mixed> $assistantConfig
     */
    public function stream(
        array $messages,
        array $options = array(),
        array $assistantConfig = array(),
        ?callable $onChunk = null,
    ): mixed {
        $provider = $this->resolveForChat($options, $assistantConfig);

        if (null === $provider) {
            $slug = $options['provider'] ?? $assistantConfig['provider'] ?? 'unknown';
            return $this->errors->create(
                'provider_not_found',
                "AI provider '{$slug}' is not registered.",
                array( 'status' => 400 ),
            );
        }

        $result = $provider->stream($messages, $options, $onChunk);

        $slug = $provider->getProviderSlug();
        if ($this->isError($result)) {
            if ($this->healthTracker) {
                $this->healthTracker->recordFailure($slug, $this->classifyError($result));
            }

            $modelFallback = $this->attemptModelFallback($messages, $options, $assistantConfig, $provider, $onChunk);
            if (null !== $modelFallback) {
                return $modelFallback;
            }

            $fallback = $this->attemptFailover($messages, $options, $assistantConfig, $slug, $onChunk, $result);
            if (null !== $fallback) {
                return $fallback;
            }
        } else {
            if ($this->healthTracker) {
                $this->healthTracker->recordSuccess($slug, 0);
            }
        }

        return $result;
    }

    /**
     * List available models across all registered providers.
     *
     * @return array<string, string[]>  Provider slug → [model IDs].
     */
    public function listAllModels(): array
    {
        $all = array();

        foreach ($this->providers as $slug => $provider) {
            $models = $provider->listModels();

            if (is_array($models)) {
                $all[ $slug ] = $models;
            }
        }

        return $all;
    }

    /**
     * Check if a provider is registered.
     */
    public function has(string $slug): bool
    {
        return isset($this->providers[ $this->normalizeSlug($slug) ]);
    }

    // ─── Failover helpers ───────────────────────────────────────────────

    /**
     * Determine if a provider response is an error.
     *
     * @since 1.2.5
     *
     * @param mixed $result Provider response.
     * @return bool True when the result represents an error.
     */
    /**
     * Whether a provider result is a platform error object.
     *
     * Duck-typed on purpose: the core must not import WP_Error. Any error
     * type that exposes `get_error_message()` (WP_Error, the Laravel
     * equivalent, a test double) is recognised.
     *
     * @param mixed $result Provider response.
     */
    private function isPlatformError(mixed $result): bool
    {
        return \is_object($result) && \method_exists($result, 'get_error_message');
    }

    private function isError(mixed $result): bool
    {
        if ($this->isPlatformError($result)) {
            return true;
        }

        if (\is_array($result) && isset($result['error'])) {
            return true;
        }

        return false;
    }

    /**
     * Classify an error for health-tracker scoring.
     *
     * @since 1.2.5
     *
     * @param mixed $result Provider response.
     * @return string Error classification.
     */
    private function classifyError(mixed $result): string
    {
        if ($this->isPlatformError($result)) {
            $message = strtolower($result->get_error_message());
            if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) {
                return 'timeout';
            }
            if (str_contains($message, 'rate') || str_contains($message, '429')) {
                return 'rate_limit';
            }
            if (str_contains($message, '500') || str_contains($message, '502') || str_contains($message, '503')) {
                return '5xx';
            }
            return 'api_error';
        }
        return 'unknown';
    }

    /**
     * Retry the request on the same provider with the catalogue's fallback.
     *
     * Ordering is deliberate: a model-level retry is cheaper and safer than a
     * provider hop — same auth, same response format, same billing account — so
     * it runs first and the ordered provider chain only gets a turn when the
     * sibling model fails too.
     *
     * Returns null when there is nothing to try: no catalogue, no explicit
     * model, no servable sibling, or the sibling failed as well. In every one
     * of those cases the caller continues with provider-level failover.
     *
     * @since 1.5.0
     *
     * @param array<int, array<string, mixed>> $messages        Chat messages.
     * @param array<string, mixed>             $options         Request options.
     * @param array<string, mixed>             $assistantConfig Assistant config.
     * @param AbstractProviderClient           $provider        The client that just failed.
     * @param callable|null                    $onChunk         Optional stream callback.
     * @return mixed|null Fallback result, or null when there is no retry to make.
     */
    private function attemptModelFallback(
        array $messages,
        array $options,
        array $assistantConfig,
        AbstractProviderClient $provider,
        ?callable $onChunk = null
    ): mixed {
        if (null === $this->modelCatalogue) {
            return null;
        }

        $modelId = (string) ( $options['model'] ?? $assistantConfig['model'] ?? '' );

        // Without an explicit model the provider is already using its own
        // default; switching it would be a provider decision, not a model one.
        if ('' === $modelId) {
            return null;
        }

        $slug      = $provider->getProviderSlug();
        $fallbackId = $this->modelCatalogue->fallbackFor($slug, $modelId);

        // A catalogue that names the same id as its own fallback would loop.
        if (null === $fallbackId || '' === $fallbackId || $fallbackId === $modelId) {
            return null;
        }

        $fallbackOptions          = $options;
        $fallbackOptions['model'] = $fallbackId;

        $result = null !== $onChunk
            ? $provider->stream($messages, $fallbackOptions, $onChunk)
            : $provider->chat($messages, $fallbackOptions);

        $succeeded = ! $this->isError($result);

        // Counted before the caller sees the result, and never allowed to
        // affect routing: the operator's view of fallbacks must not cost a
        // request.
        if (null !== $this->metrics) {
            $this->metrics->recordModelFallback($slug, $modelId, $fallbackId, $succeeded);
        }

        if (! $succeeded) {
            if ($this->healthTracker) {
                $this->healthTracker->recordFailure($slug, $this->classifyError($result));
            }

            return null;
        }

        if ($this->healthTracker) {
            $this->healthTracker->recordSuccess($slug, 0);
        }

        return $this->annotateRouting($result, $slug, $fallbackId, $options, 'model_fallback', 2);
    }

    /**
     * Prepare request options for a hop to another provider.
     *
     * A model id is provider-scoped: `gpt-4o` is an OpenAI id, not a Gemini
     * one, and carrying it into a fallback hop turns the failover into a second
     * failure. When the catalogue positively knows the target does not publish
     * the id, the override is dropped so the provider answers with its own
     * default.
     *
     * `null` from the catalogue is "no opinion" and keeps the model: an
     * unlisted id may be a deployment name, a fine-tune, or a model released
     * after the last sync, and none of those should lose their override.
     *
     * @since 1.6.0
     *
     * @param array<string, mixed> $options Request options.
     * @param string               $slug    Target provider slug.
     * @return array<string, mixed>
     */
    private function optionsForProvider(array $options, string $slug): array
    {
        $modelId = (string) ( $options['model'] ?? '' );

        if ('' === $modelId || null === $this->modelCatalogue) {
            return $options;
        }

        if (false === $this->modelCatalogue->serves($slug, $modelId)) {
            unset($options['model']);
        }

        return $options;
    }

    /**
     * Record which provider and model actually answered, and what was asked.
     *
     * Every deviation from the request is annotated — a model fallback, an
     * ordered-chain hop, health-based failover, an accepted cascade tier 1 —
     * because both the caller and the billing path need to know who answered.
     *
     * The vocabulary follows the OpenTelemetry GenAI conventions: `requested_*`
     * carries the meaning of `gen_ai.request.model`, while `provider` / `model`
     * carry the meaning of `gen_ai.provider.name` and `gen_ai.response.model` —
     * the pair that actually produced the answer. `via` and `attempt` mirror
     * what gateways expose as attempted-fallback counters (LiteLLM's
     * `x-litellm-attempted-fallbacks`), so an operator can tell a first-try
     * answer from a rescued one.
     *
     * `provider` and `model` keep the names §69/§70 introduced, so a consumer
     * that only wants the serving pair is unaffected by the extra keys.
     *
     * @since 1.6.0
     *
     * @param mixed                $result         Provider response.
     * @param string               $provider       Serving provider slug.
     * @param string|null          $model          Serving model id, when known.
     * @param array<string, mixed> $requestOptions The options the caller supplied.
     * @param string               $via            How the serving pair was chosen.
     * @param int                  $attempt        1-based attempt that answered.
     * @return mixed The response, annotated when it is a successful array.
     */
    protected function annotateRouting(
        mixed $result,
        string $provider,
        ?string $model = null,
        array $requestOptions = array(),
        string $via = '',
        int $attempt = 1
    ): mixed {
        if (! \is_array($result) || $this->isError($result)) {
            return $result;
        }

        $routing = array( 'provider' => $provider );

        if (null !== $model && '' !== $model) {
            $routing['model'] = $model;
        }

        $requestedProvider = (string) ( $requestOptions['provider'] ?? '' );
        if ('' !== $requestedProvider) {
            $routing['requested_provider'] = $requestedProvider;
        }

        $requestedModel = (string) ( $requestOptions['model'] ?? '' );
        if ('' !== $requestedModel) {
            $routing['requested_model'] = $requestedModel;
        }

        if ('' !== $via) {
            $routing['via'] = $via;
        }

        if ($attempt > 1) {
            $routing['attempt'] = $attempt;
        }

        $result['_nvoos_routing'] = $routing;

        return $result;
    }

    /**
     * Attempt provider failover when the primary provider fails.
     *
     * @since 1.2.5
     *
     * @param array<int, array<string, mixed>>    $messages        Chat messages.
     * @param array<string, mixed>    $options         Request options.
     * @param array<string, mixed>    $assistantConfig Assistant config.
     * @param string   $failedSlug      Slug of the provider that just failed.
     * @param callable $onChunk         Optional stream callback.
     * @return mixed|null Fallback result, or null if no fallback available.
     */
    private function attemptFailover(
        array $messages,
        array $options,
        array $assistantConfig,
        string $failedSlug,
        ?callable $onChunk = null,
        mixed $failedResult = null
    ): mixed {
        // Ordered fallback chains (Proposal 091, Track D) take precedence
        // over health-based failover whenever one is configured.
        $chain = $this->resolveFallbackCandidates($assistantConfig);
        if (! empty($chain)) {
            $this->coolDown($failedSlug, $this->retryAfterSeconds($failedResult));
            return $this->attemptChain($messages, $options, $chain, $onChunk, $failedSlug);
        }

        if (! $this->failoverEnabled() || null === $this->healthTracker) {
            return null;
        }

        $fallbacks = $this->healthTracker->getFallbackChain($failedSlug);
        $from      = $failedSlug;
        $attempt   = 2;

        foreach ($fallbacks as $fallback) {
            $fallbackProvider = $this->get($fallback['slug']);
            if (! $fallbackProvider) {
                continue;
            }

            $candidateOptions = $this->optionsForProvider($options, $fallback['slug']);

            if (null !== $onChunk) {
                $result = $fallbackProvider->stream($messages, $candidateOptions, $onChunk);
            } else {
                $result = $fallbackProvider->chat($messages, $candidateOptions);
            }

            $succeeded = ! $this->isError($result);

            if (null !== $this->metrics) {
                $this->metrics->recordProviderHop($from, $fallback['slug'], $succeeded);
            }

            if ($succeeded) {
                $this->healthTracker->recordSuccess($fallback['slug'], 0);
                return $this->annotateRouting(
                    $result,
                    $fallback['slug'],
                    $candidateOptions['model'] ?? null,
                    $options,
                    'health_failover',
                    $attempt
                );
            }

            $this->healthTracker->recordFailure($fallback['slug'], $this->classifyError($result));
            $from = $fallback['slug'];
            ++$attempt;
        }

        return null;
    }

    /**
     * Walk the ordered fallback chain, skipping cooled-down providers.
     *
     * @since 1.2.13
     *
     * @param array<int, array<string, mixed>>         $messages Chat messages.
     * @param array<string, mixed>         $options  Request options.
     * @param string[]      $chain    Ordered provider slugs.
     * @param callable|null $onChunk  Optional stream callback.
     * @param string        $failedSlug Provider that just failed.
     * @return mixed|null First successful result, or null when exhausted.
     */
    private function attemptChain(
        array $messages,
        array $options,
        array $chain,
        ?callable $onChunk,
        string $failedSlug
    ): mixed {
        $from    = $failedSlug;
        $attempt = 2;

        foreach ($chain as $slug) {
            if ($this->isCooledDown($slug)) {
                continue;
            }

            $provider = $this->get($slug);
            if (null === $provider) {
                continue;
            }

            $candidateOptions = $this->optionsForProvider($options, $slug);

            $result = null !== $onChunk
                ? $provider->stream($messages, $candidateOptions, $onChunk)
                : $provider->chat($messages, $candidateOptions);

            $succeeded = ! $this->isError($result);

            if (null !== $this->metrics) {
                $this->metrics->recordProviderHop($from, $slug, $succeeded);
            }

            if ($succeeded) {
                if ($this->healthTracker) {
                    $this->healthTracker->recordSuccess($slug, 0);
                }
                return $this->annotateRouting(
                    $result,
                    $slug,
                    $candidateOptions['model'] ?? null,
                    $options,
                    'provider_fallback',
                    $attempt
                );
            }

            if ($this->healthTracker) {
                $this->healthTracker->recordFailure($slug, $this->classifyError($result));
            }
            $this->coolDown($slug, $this->retryAfterSeconds($result));
            $from = $slug;
            ++$attempt;
        }

        return null;
    }

    /**
     * Resolve the ordered chain: assistant config wins over the router-level
     * chain; only registered, normalized, unique slugs are returned.
     *
     * @since 1.2.13
     *
     * @param array<string, mixed> $assistantConfig Assistant config.
     * @return string[]
     */
    private function resolveFallbackCandidates(array $assistantConfig): array
    {
        $chain = array();
        if (! empty($assistantConfig['fallback_chain']) && \is_array($assistantConfig['fallback_chain'])) {
            $chain = $assistantConfig['fallback_chain'];
        } elseif (! empty($this->fallbackChain)) {
            $chain = $this->fallbackChain;
        }

        $normalized = array();
        foreach ($chain as $slug) {
            $slug = $this->normalizeSlug((string) $slug);
            if (isset($this->providers[ $slug ]) && ! \in_array($slug, $normalized, true)) {
                $normalized[] = $slug;
            }
        }

        return $normalized;
    }

    /**
     * Extract a Retry-After value from a failed provider result (best effort).
     *
     * @since 1.2.13
     *
     * @param mixed $result Failed provider response.
     * @return int Cooldown seconds (default when absent; capped).
     */
    private function retryAfterSeconds(mixed $result): int
    {
        $retryAfter = null;

        if ($this->isPlatformError($result)) {
            $data = $result->get_error_data();
            if (\is_array($data)) {
                if (isset($data['retry_after']) && \is_numeric($data['retry_after'])) {
                    $retryAfter = (int) $data['retry_after'];
                } elseif (isset($data['headers']['retry-after']) && \is_numeric($data['headers']['retry-after'])) {
                    $retryAfter = (int) $data['headers']['retry-after'];
                }
            }
        } elseif (\is_array($result) && isset($result['error']['data']['retry_after']) && \is_numeric($result['error']['data']['retry_after'])) {
            $retryAfter = (int) $result['error']['data']['retry_after'];
        }

        if (null === $retryAfter || $retryAfter < 0) {
            return self::DEFAULT_COOLDOWN_SECONDS;
        }

        return \min($retryAfter, self::MAX_COOLDOWN_SECONDS);
    }

    // ─── Helpers ──────────────────────────────────────────────────────

    /**
     * Normalize provider slugs — Google's provider is 'gemini' in settings
     * but may arrive as 'google'.
     */
    private function normalizeSlug(string $slug): string
    {
        return match (\strtolower(\trim($slug))) {
            'google' => 'gemini',
            'claude' => 'anthropic',
            'moonshot', 'moonshot_ai' => 'kimi',
            'nvidia'  => 'nvidia_nim',
            'cloudflare_ai', 'workers_ai' => 'cloudflare',
            'lmstudio' => 'lm_studio',
            'hugging_face', 'hf' => 'huggingface',
            'open_router' => 'openrouter',
            'digital_ocean', 'do' => 'digitalocean',
            default => $slug,
        };
    }
}
