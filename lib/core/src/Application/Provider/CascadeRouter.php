<?php
/**
 * CascadeRouter — cheap-model-first routing with judge-verified escalation.
 *
 * Wraps {@see ProviderRouter} and implements the FrugalGPT / RouteLLM /
 * AutoMix cascade pattern: a complexity classifier routes the easy majority
 * of requests to a cheap tier-1 model, and a response validator acts as the
 * "stop judge" — a tier-1 answer is only returned when it validates, and the
 * request escalates to the primary (frontier) provider otherwise.
 *
 * The router is deliberately inert by default:
 *   - No classifier or validator → every request passes through to
 *     {@see ProviderRouter::chat()} unchanged.
 *   - No tier-1 configuration → passthrough.
 *   - Tier-1 errors → fail-open escalation to the primary provider.
 *
 * Configuration is read from `options` / `assistantConfig` keys so the
 * WordPress bridge can expose it as assistant meta without a core change:
 *   - `cascade_tier_1_model`    — model override on the same provider.
 *   - `cascade_tier_1_provider` — different provider slug for tier 1.
 *   - `cascade_confidence_threshold` — minimum validator confidence (0..1).
 *
 * @link    https://arxiv.org/abs/2305.05176 FrugalGPT (Stanford/TMLR 2024)
 * @credit  Cascade-routing concept inspired by affaan-m/ECC cost-aware LLM
 *          pipeline skill (MIT) and the FrugalGPT/RouteLLM literature.
 * @package Nvoos\Core
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Provider;

use Nvoos\Core\Domain\Contract\ComplexityClassifierInterface;
use Nvoos\Core\Domain\Contract\ResponseValidatorInterface;
use Nvoos\Core\Infrastructure\Provider\AbstractProviderClient;

class CascadeRouter {

	/**
	 * Default validator-confidence threshold for accepting a tier-1 answer.
	 */
	private const DEFAULT_CONFIDENCE_THRESHOLD = 0.85;

	/**
	 * Routing statistics, keyed by counter name.
	 *
	 * @var array<string, int>
	 */
	private array $stats = array(
		'requests'       => 0,
		'routed_simple'  => 0,
		'accepted'       => 0,
		'escalated'      => 0,
		'tier1_errors'   => 0,
		'passthrough'    => 0,
	);

	public function __construct(
		private readonly ProviderRouter $router,
		private readonly ?ComplexityClassifierInterface $classifier = null,
		private readonly ?ResponseValidatorInterface $validator = null,
	) {}

	/**
	 * Send a chat completion through the cascade.
	 *
	 * @param array<int, array<string, mixed>> $messages        Chat messages.
	 * @param array<string, mixed>             $options         Request options.
	 * @param array<string, mixed>             $assistantConfig Assistant
	 *                                                          configuration.
	 * @return mixed Provider response or error, identical to
	 *               {@see ProviderRouter::chat()}.
	 */
	public function chat( array $messages, array $options = array(), array $assistantConfig = array() ): mixed {
		$this->stats['requests']++;

		// Inert without both judge components and a tier-1 target.
		if ( null === $this->classifier || null === $this->validator || ! $this->hasTier1Target( $options, $assistantConfig ) ) {
			$this->stats['passthrough']++;
			return $this->router->chat( $messages, $options, $assistantConfig );
		}

		$threshold = $this->resolveThreshold( $options, $assistantConfig );

		$classification = $this->classifier->classify( $messages, $options );
		$tier = \is_array( $classification ) ? ( $classification['tier'] ?? '' ) : '';

		if ( 'simple' !== $tier ) {
			// Hard requests skip the cheap tier entirely.
			return $this->router->chat( $messages, $options, $assistantConfig );
		}

		$this->stats['routed_simple']++;

		$tier1Client   = $this->resolveTier1Client( $options, $assistantConfig );
		$tier1Options  = $this->buildTier1Options( $options, $assistantConfig );

		if ( null === $tier1Client ) {
			$this->stats['passthrough']++;
			return $this->router->chat( $messages, $options, $assistantConfig );
		}

		$tier1Result = $tier1Client->chat( $messages, $tier1Options );

		if ( $this->isError( $tier1Result ) ) {
			$this->stats['tier1_errors']++;
			$this->stats['escalated']++;
			return $this->router->chat( $messages, $options, $assistantConfig );
		}

		$verdict = $this->validator->validate( $tier1Result, $messages, $options );
		$acceptable = \is_array( $verdict ) && ! empty( $verdict['acceptable'] );
		$confidence = \is_array( $verdict ) && isset( $verdict['confidence'] )
			? (float) $verdict['confidence']
			: 0.0;

		if ( ! $acceptable || $confidence < $threshold ) {
			$this->stats['escalated']++;
			return $this->router->chat( $messages, $options, $assistantConfig );
		}

		$this->stats['accepted']++;
		return $tier1Result;
	}

	/**
	 * Stream a chat completion through the cascade.
	 *
	 * Streaming cannot be validated after the fact, so this path routes the
	 * full request through the primary provider and never through tier 1 —
	 * a cascade that streams a weak answer cannot retract it.
	 *
	 * @param array<int, array<string, mixed>> $messages    Chat messages.
	 * @param array<string, mixed>             $options     Request options.
	 * @param array<string, mixed>             $assistantConfig Assistant config.
	 * @param callable|null                    $onChunk     Chunk callback.
	 * @return mixed Provider response or error.
	 */
	public function stream( array $messages, array $options = array(), array $assistantConfig = array(), ?callable $onChunk = null ): mixed {
		$this->stats['requests']++;
		return $this->router->stream( $messages, $options, $assistantConfig, $onChunk );
	}

	/**
	 * Resolve the primary provider client (the cascade's escalation target).
	 *
	 * @param array<string, mixed> $options         Request options.
	 * @param array<string, mixed> $assistantConfig Assistant configuration.
	 * @return AbstractProviderClient|null
	 */
	public function resolvePrimary( array $options = array(), array $assistantConfig = array() ): ?AbstractProviderClient {
		return $this->router->resolveForChat( $options, $assistantConfig );
	}

	/**
	 * Routing statistics: requests, routed_simple, accepted, escalated,
	 * tier1_errors, passthrough.
	 *
	 * @return array<string, int>
	 */
	public function getStats(): array {
		return $this->stats;
	}

	/**
	 * Whether any tier-1 target is configured.
	 *
	 * @param array<string, mixed> $options         Request options.
	 * @param array<string, mixed> $assistantConfig Assistant configuration.
	 * @return bool
	 */
	private function hasTier1Target( array $options, array $assistantConfig ): bool {
		return '' !== (string) ( $options['cascade_tier_1_model'] ?? $assistantConfig['cascade_tier_1_model'] ?? '' )
			|| '' !== (string) ( $options['cascade_tier_1_provider'] ?? $assistantConfig['cascade_tier_1_provider'] ?? '' );
	}

	/**
	 * Resolve the validator confidence threshold (0..1).
	 *
	 * @param array<string, mixed> $options         Request options.
	 * @param array<string, mixed> $assistantConfig Assistant configuration.
	 * @return float
	 */
	private function resolveThreshold( array $options, array $assistantConfig ): float {
		$raw = $options['cascade_confidence_threshold'] ?? $assistantConfig['cascade_confidence_threshold'] ?? self::DEFAULT_CONFIDENCE_THRESHOLD;
		$threshold = (float) $raw;

		return \max( 0.0, \min( 1.0, $threshold ) );
	}

	/**
	 * Resolve the tier-1 client.
	 *
	 * `cascade_tier_1_provider` wins when set (with slug normalization
	 * delegated to the wrapped router's registry); otherwise the primary
	 * client carries the tier-1 model override.
	 *
	 * @param array<string, mixed> $options         Request options.
	 * @param array<string, mixed> $assistantConfig Assistant configuration.
	 * @return AbstractProviderClient|null
	 */
	private function resolveTier1Client( array $options, array $assistantConfig ): ?AbstractProviderClient {
		$tier1Provider = (string) ( $options['cascade_tier_1_provider'] ?? $assistantConfig['cascade_tier_1_provider'] ?? '' );

		if ( '' !== $tier1Provider ) {
			return $this->router->get( $tier1Provider );
		}

		return $this->router->resolveForChat( $options, $assistantConfig );
	}

	/**
	 * Build the tier-1 request options.
	 *
	 * A tier-1 model override is applied when present. When routing to a
	 * different provider without an explicit tier-1 model, the primary model
	 * override is dropped so the tier-1 provider's own default model is used
	 * (the primary model may not exist on that provider).
	 *
	 * @param array<string, mixed> $options         Request options.
	 * @param array<string, mixed> $assistantConfig Assistant configuration.
	 * @return array<string, mixed>
	 */
	private function buildTier1Options( array $options, array $assistantConfig ): array {
		$tier1Model    = (string) ( $options['cascade_tier_1_model'] ?? $assistantConfig['cascade_tier_1_model'] ?? '' );
		$tier1Provider = (string) ( $options['cascade_tier_1_provider'] ?? $assistantConfig['cascade_tier_1_provider'] ?? '' );
		$tier1Options  = $options;

		if ( '' !== $tier1Model ) {
			$tier1Options['model'] = $tier1Model;
		} elseif ( '' !== $tier1Provider ) {
			unset( $tier1Options['model'] );
		}

		return $tier1Options;
	}

	/**
	 * Whether a provider result represents an error.
	 *
	 * Mirrors {@see ProviderRouter} error detection: a \WP_Error instance or
	 * an array envelope carrying an `error` key.
	 *
	 * @param mixed $result Provider response.
	 * @return bool
	 */
	private function isError( mixed $result ): bool {
		if ( $result instanceof \WP_Error ) {
			return true;
		}

		return \is_array( $result ) && isset( $result['error'] );
	}
}
