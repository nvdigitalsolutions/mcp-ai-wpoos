<?php
/**
 * Text-to-image through the real adapter (Proposal 164 §164.130).
 *
 * Two provider shapes arrive at this method: Gemini answers inline base64, and
 * OpenAI answers a short-lived URL that the adapter downloads. Both must end as
 * one stored file with dimensions read from the bytes, because the route does
 * not report geometry and `0` would be a size rather than "unknown".
 *
 * The fixture is a real 1×1 PNG: `getimagesize()` is what proves the bytes were
 * decoded rather than the worker's word trusted.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use Nvoos\Laravel\Adapter\MediaWorkerCapabilityProbe;
use Nvoos\Laravel\Adapter\MediaWorkerClient;
use Nvoos\Laravel\Adapter\MediaWorkerImageProduction;

final class MediaWorkerImageGenerateTest extends TestCase {

	/**
	 * A 1×1 transparent PNG — valid enough for `getimagesize()`.
	 */
	private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

	/**
	 * Scratch directory standing in for the worker output directory.
	 */
	private string $workDir;

	protected function setUp(): void {
		parent::setUp();

		$this->workDir = \sys_get_temp_dir() . '/oos-generate-' . \bin2hex( \random_bytes( 5 ) );
		\mkdir( $this->workDir, 0775, true );

		$this->app['config']->set( 'oos.media_worker.url', 'http://worker.test' );
		$this->app['config']->set( 'oos.media_worker.token', 'test-token' );
		$this->app['config']->set( 'oos.media_worker.output_dir', $this->workDir );
	}

	protected function tearDown(): void {
		foreach ( \glob( $this->workDir . '/*' ) ?: array() as $file ) {
			if ( \is_file( $file ) ) {
				\unlink( $file );
			}
		}

		if ( \is_dir( $this->workDir ) ) {
			\rmdir( $this->workDir );
		}

		parent::tearDown();
	}

	public function test_generate_stores_inline_base64_and_reads_its_dimensions(): void {
		Http::fake(
			array(
				'worker.test/api/image/generate' => Http::response(
					array(
						'success'  => true,
						'provider' => 'gemini',
						'model'    => 'gemini-2.5-flash-image',
						'prompt'   => 'A marble table in morning light',
						'images'   => array(
							array(
								'b64_json' => self::PNG_1X1,
								'model'    => 'gemini-2.5-flash-image',
								'mime'     => 'image/png',
							),
						),
					)
				),
				'*' => Http::response( array(), 500 ),
			)
		);

		$result = $this->production()->generate(
			'A marble table in morning light',
			array(
				'provider'     => 'gemini',
				'aspect_ratio' => '3:2',
			)
		);

		$this->assertSame( 'generate', $result['operation'] );
		$this->assertSame( 'gemini', $result['provider'] );
		$this->assertSame( 'gemini-2.5-flash-image', $result['model'] );
		$this->assertSame( 'A marble table in morning light', $result['prompt'] );
		$this->assertSame( 1, $result['width'], 'Dimensions come from the stored bytes.' );
		$this->assertSame( 1, $result['height'] );
		$this->assertSame( 'png', $result['format'] );
		$this->assertSame( 'image/png', $result['mime_type'] );
		$this->assertSame( \strlen( (string) \base64_decode( self::PNG_1X1, true ) ), $result['bytes'] );

		$this->assertTrue( \is_file( $result['path'] ) );
		$this->assertSame( self::PNG_1X1, \base64_encode( (string) \file_get_contents( $result['path'] ) ) );

		Http::assertSent(
			static function ( $request ): bool {
				return \str_ends_with( $request->url(), '/api/image/generate' )
					&& 'A marble table in morning light' === ( $request['prompt'] ?? null )
					&& 'gemini' === ( $request['provider'] ?? null )
					&& 'gemini-2.5-flash-image' === ( $request['model'] ?? null )
					&& '1792x1024' === ( $request['size'] ?? null );
			}
		);
	}

	public function test_generate_downloads_a_provider_hosted_url(): void {
		Http::fake(
			array(
				'worker.test/api/image/generate' => Http::response(
					array(
						'success'  => true,
						'provider' => 'openai',
						'model'    => 'dall-e-3',
						'prompt'   => 'A marble table',
						'images'   => array(
							array(
								'url'   => 'https://cdn.example.test/generated.png',
								'model' => 'dall-e-3',
							),
						),
					)
				),
				'cdn.example.test/*' => Http::response(
					(string) \base64_decode( self::PNG_1X1, true ),
					200,
					array( 'Content-Type' => 'image/png' )
				),
			)
		);

		$result = $this->production()->generate( 'A marble table', array( 'provider' => 'openai' ) );

		$this->assertSame( 'openai', $result['provider'] );
		$this->assertSame( 'dall-e-3', $result['model'] );
		$this->assertSame( 'png', $result['format'], 'The format is sniffed from the downloaded bytes.' );
		$this->assertSame( 1, $result['width'] );
		$this->assertSame( 1, $result['height'] );
		$this->assertSame( self::PNG_1X1, \base64_encode( (string) \file_get_contents( $result['path'] ) ) );

		Http::assertSentCount( 2 );
	}

	public function test_generate_leaves_model_unset_for_auto(): void {
		Http::fake(
			array(
				'worker.test/api/image/generate' => Http::response(
					array(
						'success'  => true,
						'provider' => 'gemini',
						'model'    => 'dall-e-3',
						'prompt'   => 'A marble table',
						'images'   => array(
							array(
								'b64_json' => self::PNG_1X1,
								'model'    => 'gemini-2.5-flash-image',
								'mime'     => 'image/png',
							),
						),
					)
				),
			)
		);

		$result = $this->production()->generate( 'A marble table' );

		// The worker resolved `auto`; naming a model here would name the wrong
		// provider's model whenever the resolution went the other way.
		Http::assertSent(
			static fn ( $request ): bool => \str_ends_with( $request->url(), '/api/image/generate' )
				&& ! isset( $request['model'] )
				&& 'auto' === ( $request['provider'] ?? null )
		);

		$this->assertSame( 'gemini-2.5-flash-image', $result['model'], 'The report uses the model that actually ran.' );
	}

	public function test_generate_refuses_a_provider_outside_the_closed_set(): void {
		Http::fake();

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unsupported generate provider: replicate' );

		try {
			$this->production()->generate( 'A marble table', array( 'provider' => 'replicate' ) );
		} finally {
			Http::assertNothingSent();
		}
	}

	private function production(): MediaWorkerImageProduction {
		$client = new MediaWorkerClient();

		return new MediaWorkerImageProduction( $client, new MediaWorkerCapabilityProbe( $client ) );
	}
}
