<?php
/**
 * WordPress adapter: TiktokenServiceInterface implementation.
 *
 * Wraps the Rahul900day\Tiktoken\Tiktoken library (when available)
 * behind the framework-agnostic TiktokenServiceInterface, falling
 * back to the chars/4 heuristic when tiktoken is not installed.
 *
 * @package Nvoos\WordPress
 * @since   1.0.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\TiktokenServiceInterface;

class TiktokenService implements TiktokenServiceInterface {

	private const TIKTOKEN_CLASS = 'Rahul900day\\Tiktoken\\Tiktoken';

	/**
	 * Encoding map: model family → tiktoken encoding name.
	 */
	private const ENCODING_MAP = array(
		'gpt-4o'     => 'o200k_base',
		'gpt-4.1'    => 'o200k_base',
		'gpt-4.5'    => 'o200k_base',
		'gpt-4'      => 'cl100k_base',
		'gpt-3.5'    => 'cl100k_base',
		'gpt-3'      => 'p50k_base',
		'davinci'    => 'p50k_base',
		'text-'      => 'p50k_base',
	);

	public function countTokens( string $text, ?string $model = null ): int {
		if ( '' === $text ) {
			return 0;
		}

		if ( $this->isAvailable() ) {
			try {
				return $this->countTokensAccurate( $text, $model );
			} catch ( \RuntimeException $e ) {
				// Fall through to heuristic.
			}
		}

		return $this->heuristicCount( $text );
	}

	public function countTokensAccurate( string $text, ?string $model = null ): int {
		if ( ! $this->isAvailable() ) {
			throw new \RuntimeException( 'Tiktoken library is not available. Install rahul900day/tiktoken-php.' );
		}

		$class    = self::TIKTOKEN_CLASS;
		$encoding = $this->resolveEncoding( $model );
		$encoder  = $class::getEncoding( $encoding );
		$tokens   = $encoder->encode( $text );

		return \count( $tokens );
	}

	public function isAvailable(): bool {
		return \class_exists( self::TIKTOKEN_CLASS );
	}

	public function resolveEncoding( ?string $model = null ): string {
		if ( null === $model || '' === $model ) {
			return 'cl100k_base';
		}

		$model = \strtolower( \trim( $model ) );

		foreach ( self::ENCODING_MAP as $prefix => $encoding ) {
			if ( \str_starts_with( $model, $prefix ) ) {
				return $encoding;
			}
		}

		return 'cl100k_base';
	}

	/**
	 * Fast heuristic: ~4 characters per token.
	 */
	private function heuristicCount( string $text ): int {
		$count = (int) \ceil( \strlen( $text ) / 4.0 );
		return \max( 0, $count );
	}
}
