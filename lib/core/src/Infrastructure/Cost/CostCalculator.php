<?php
/**
 * Cost calculator — estimates USD cost for AI API usage.
 *
 * Uses provider-specific pricing tables to calculate the dollar cost
 * of prompt and completion tokens. Handles 12 providers with model-
 * specific pricing.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Infrastructure\Cost;

class CostCalculator {

	/**
	 * Pricing per million tokens: [prompt_price, completion_price].
	 *
	 * Prices in USD per 1M tokens. Source: official provider pricing pages
	 * as of May 2026. Update when providers change pricing.
	 *
	 * @var array<string, array<string, array{float, float}>>
	 */
	private const PRICING = array(
		'openai'       => array(
			'gpt-5-nano'   => array( 0.15, 0.60 ),
			'gpt-5-mini'   => array( 0.30, 1.20 ),
			'gpt-5'        => array( 1.25, 5.00 ),
			'gpt-4o'       => array( 2.50, 10.00 ),
			'gpt-4o-mini'  => array( 0.15, 0.60 ),
			'gpt-4.1'      => array( 2.00, 8.00 ),
			'gpt-4.1-mini' => array( 0.40, 1.60 ),
			'gpt-4.1-nano' => array( 0.10, 0.40 ),
			'o4-mini'      => array( 1.10, 4.40 ),
			'o3'           => array( 10.00, 40.00 ),
			'o3-mini'      => array( 1.10, 4.40 ),
			'gpt-image-1'  => array( 0.00, 4.00 ),   // Image gen: flat per-image
			'default'      => array( 2.50, 10.00 ),
		),
		'anthropic'    => array(
			'claude-opus-4-6'   => array( 15.00, 75.00 ),
			'claude-sonnet-4-6' => array( 3.00, 15.00 ),
			'claude-haiku-4-6'  => array( 1.00, 5.00 ),
			'default'           => array( 3.00, 15.00 ),
		),
		'gemini'       => array(
			'gemini-2.5-pro'        => array( 2.50, 10.00 ),
			'gemini-2.5-flash'      => array( 0.30, 1.20 ),
			'gemini-2.0-flash'      => array( 0.15, 0.60 ),
			'gemini-2.0-flash-lite' => array( 0.075, 0.30 ),
			'default'               => array( 0.15, 0.60 ),
		),
		'deepseek'     => array(
			'deepseek-chat'     => array( 0.27, 1.10 ),
			'deepseek-reasoner' => array( 0.55, 2.19 ),
			'default'           => array( 0.27, 1.10 ),
		),
		'openrouter'   => array(
			'default' => array( 2.00, 8.00 ),   // Varies wildly by model
		),
		'kimi'         => array(
			'kimi-k2.6' => array( 0.60, 2.40 ),
			'default'   => array( 0.60, 2.40 ),
		),
		'digitalocean' => array(
			'default' => array( 0.00, 0.00 ),   // Priced per GPU-second
		),
		'cloudflare'   => array(
			'default' => array( 0.00, 0.00 ),   // Usage-based, varies
		),
		'nvidia_nim'   => array(
			'default' => array( 0.00, 0.00 ),   // Priced per GPU-hour
		),
		'huggingface'  => array(
			'default' => array( 0.00, 0.00 ),   // Free tier or per-hour
		),
		'ollama'       => array(
			'default' => array( 0.00, 0.00 ),   // Local — free
		),
		'lm_studio'    => array(
			'default' => array( 0.00, 0.00 ),   // Local — free
		),
	);

	/**
	 * Calculate the USD cost of a chat completion.
	 *
	 * @param string $provider         Provider slug (openai, gemini, etc.).
	 * @param string $model            Model identifier.
	 * @param int    $promptTokens     Tokens consumed by the prompt.
	 * @param int    $completionTokens Tokens generated in the response.
	 *
	 * @return float  Cost in USD. 0.0 when pricing data is unavailable.
	 */
	public function calculate(
		string $provider,
		string $model,
		int $promptTokens,
		int $completionTokens,
	): float {
		if ( $promptTokens <= 0 && $completionTokens <= 0 ) {
			return 0.0;
		}

		$pricing = $this->resolvePricing( $provider, $model );

		[$promptPrice, $completionPrice] = $pricing;

		$promptCost     = ( $promptTokens / 1_000_000 ) * $promptPrice;
		$completionCost = ( $completionTokens / 1_000_000 ) * $completionPrice;

		return \round( $promptCost + $completionCost, 6 );
	}

	/**
	 * Calculate cost from a full response array that includes usage data.
	 *
	 * @param array  $response  Response with ['usage']['prompt_tokens'] etc.
	 * @param string $provider
	 * @param string $model
	 *
	 * @return array{ cost_usd: float, provider: string, model: string, is_estimated: bool }|null
	 */
	public function calculateFromResponse(
		array $response,
		string $provider,
		string $model,
	): ?array {
		$usage = $response['usage'] ?? array();

		if ( ! is_array( $usage ) ) {
			return null;
		}

		$promptTokens     = (int) ( $usage['prompt_tokens'] ?? 0 );
		$completionTokens = (int) ( $usage['completion_tokens'] ?? 0 );

		if ( $promptTokens <= 0 && $completionTokens <= 0 ) {
			return null;
		}

		$cost = $this->calculate( $provider, $model, $promptTokens, $completionTokens );

		return array(
			'cost_usd'     => $cost,
			'provider'     => $provider,
			'model'        => $model,
			'is_estimated' => $this->isEstimated( $provider ),
		);
	}

	/**
	 * Whether the pricing for this provider is estimated (no official
	 * per-token pricing) rather than exact.
	 */
	public function isEstimated( string $provider ): bool {
		return in_array(
			$provider,
			array(
				'digitalocean',
				'cloudflare',
				'nvidia_nim',
				'huggingface',
				'openrouter',
			),
			true
		);
	}

	/**
	 * Resolve pricing for a provider+model combination.
	 *
	 * @return array{float, float}  [prompt_price_per_1M, completion_price_per_1M]
	 */
	private function resolvePricing( string $provider, string $model ): array {
		$providerPricing = self::PRICING[ $provider ] ?? array();

		if ( array() === $providerPricing ) {
			return array( 0.0, 0.0 );
		}

		// Try exact model match.
		if ( isset( $providerPricing[ $model ] ) ) {
			return $providerPricing[ $model ];
		}

		// Try prefix match (e.g., 'gpt-4o-mini-2024-07-18' → 'gpt-4o-mini').
		foreach ( $providerPricing as $knownModel => $pricing ) {
			if ( 'default' === $knownModel ) {
				continue;
			}
			if ( \str_starts_with( $model, $knownModel ) ) {
				return $pricing;
			}
		}

		// Fall back to provider default.
		return $providerPricing['default'];
	}
}
