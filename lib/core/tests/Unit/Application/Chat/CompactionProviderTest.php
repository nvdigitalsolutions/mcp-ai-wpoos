<?php
/**
 * Tests for CompactionProvider — trigger policy and strategy cascade.
 *
 * @package Nvoos\Core\Tests
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Chat;

use Nvoos\Core\Application\Chat\CompactionProvider;
use Nvoos\Core\Domain\Contract\ContextCompressionInterface;
use Nvoos\Core\Domain\Contract\SemanticCompressorInterface;
use PHPUnit\Framework\TestCase;

final class CompactionProviderTest extends TestCase {

	private function messages( int $contentLength = 100 ): array {
		return array(
			array( 'role' => 'user', 'content' => str_repeat( 'a', $contentLength ) ),
			array( 'role' => 'assistant', 'content' => str_repeat( 'b', $contentLength ) ),
		);
	}

	private function semantic(): SemanticCompressorInterface {
		return new class() implements SemanticCompressorInterface {
			public function compress( string $text, int $aggressiveness = 2, int $maxTokens = 0 ): array {
				$compact = array( array( 'role' => 'user', 'content' => 'semantically-compressed' ) );

				return array(
					'compressed'        => json_encode( $compact ),
					'original_bytes'    => strlen( $text ),
					'compressed_bytes'  => 26,
					'compression_ratio' => 0.1,
					'tokens_estimate'   => 7,
				);
			}

			public function estimateTokens( string $text ): int {
				return (int) ceil( strlen( $text ) / 4 );
			}

			public function isValidAggressiveness( int $level ): bool {
				return $level >= 1 && $level <= 3;
			}
		};
	}

	private function context(): ContextCompressionInterface {
		return new class() implements ContextCompressionInterface {
			public function compress( string $content, array $options = array() ): array {
				$compact = array( array( 'role' => 'user', 'content' => 'context-compressed' ) );

				return array(
					'success'    => true,
					'compressed' => json_encode( $compact ),
				);
			}

			public function chunk( string $content, int $chunkSize = 500, float $overlapRatio = 0.15 ): array {
				return array();
			}

			public function estimateTokens( string $text ): int {
				return (int) ceil( strlen( $text ) / 4 );
			}
		};
	}

	public function testNoTriggerBeforeMinIteration(): void {
		$provider = new CompactionProvider( $this->context(), $this->semantic() );

		$this->assertFalse( $provider->shouldCompact( $this->messages( 5000 ), 4096, 1 ) );
	}

	public function testTriggerWhenOverThresholdAtOrAfterMinIteration(): void {
		$provider = new CompactionProvider( $this->context(), $this->semantic() );

		// ~2,500 tokens stays under 85% of a 4,096 window; ~4,000 crosses it.
		$this->assertFalse( $provider->shouldCompact( $this->messages( 100 ), 4096, 2 ) );
		$this->assertTrue( $provider->shouldCompact( $this->messages( 8000 ), 4096, 2 ) );
	}

	public function testUnknownContextLimitNeverTriggers(): void {
		$provider = new CompactionProvider( $this->context(), $this->semantic() );

		$this->assertFalse( $provider->shouldCompact( $this->messages( 50000 ), 0, 3 ) );
	}

	public function testSemanticStrategyWinsOverContextFallback(): void {
		$provider = new CompactionProvider( $this->context(), $this->semantic() );

		$compacted = $provider->compact( $this->messages() );

		$this->assertSame( 'semantically-compressed', $compacted[0]['content'] );
	}

	public function testContextFallbackWhenSemanticAbsent(): void {
		$provider = new CompactionProvider( $this->context(), null );

		$compacted = $provider->compact( $this->messages() );

		$this->assertSame( 'context-compressed', $compacted[0]['content'] );
	}

	public function testPassthroughWhenNoCompressorWired(): void {
		$provider = new CompactionProvider();

		$messages = $this->messages();

		$this->assertSame( $messages, $provider->compact( $messages ) );
	}

	public function testUndecodableResultPassesThrough(): void {
		$broken = new class() implements ContextCompressionInterface {
			public function compress( string $content, array $options = array() ): array {
				// Non-JSON output — undecodable, must pass through.
				return array( 'success' => true, 'compressed' => 'not-a-json-message-list' );
			}

			public function chunk( string $content, int $chunkSize = 500, float $overlapRatio = 0.15 ): array {
				return array();
			}

			public function estimateTokens( string $text ): int {
				return 1;
			}
		};

		$provider = new CompactionProvider( $broken );

		$messages = $this->messages();

		$this->assertSame( $messages, $provider->compact( $messages ) );
	}
}
