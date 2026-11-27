<?php
/**
 * Composition through the real adapter (Proposal 164 §164.130).
 *
 * `compose()` is the first production path that sends **two** images in one
 * request, and the order is semantic: index 0 is the scene, index 1 is the
 * product. A swap still produces a plausible image, which is exactly why it
 * would go unnoticed, so the test asserts the parts by name *and* filename
 * rather than by count.
 *
 * The worker is faked and the inputs are plain bytes: the adapter never
 * decodes them, so what is under test is the request shape and the response
 * mapping, not a provider or an image codec.
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

final class MediaWorkerImageComposeTest extends TestCase {

	/**
	 * Scratch directory standing in for the worker output directory.
	 */
	private string $workDir;

	protected function setUp(): void {
		parent::setUp();

		$this->workDir = \sys_get_temp_dir() . '/oos-compose-' . \bin2hex( \random_bytes( 5 ) );
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

	public function test_compose_sends_the_scene_first_and_maps_the_output(): void {
		$scene   = $this->file( 'scene.bin', 'scene-bytes' );
		$product = $this->file( 'product.bin', 'product-bytes' );
		$output  = 'composed-bytes';

		Http::fake(
			array(
				'worker.test/api/image/compose' => Http::response(
					array(
						'success'   => true,
						'provider'  => 'gemini',
						'model'     => 'gemini-3.1-flash-image',
						'operation' => 'compose',
						'prompt'    => 'Set it on the marble table.',
						'format'    => 'png',
						'width'     => 96,
						'height'    => 72,
						'bytes'     => \strlen( $output ),
						'b64'       => \base64_encode( $output ),
					)
				),
				// Anything else is a bug in the endpoint, not a network call.
				'*' => Http::response( array(), 500 ),
			)
		);

		$result = $this->production()->compose(
			$scene,
			$product,
			array(
				'provider' => 'gemini',
				'prompt'   => 'Set it on the marble table.',
			)
		);

		$this->assertSame( 'compose', $result['operation'] );
		$this->assertSame( 'gemini', $result['provider'] );
		$this->assertSame( 'gemini-3.1-flash-image', $result['model'] );
		$this->assertSame( 'Set it on the marble table.', $result['prompt'] );
		$this->assertSame( 96, $result['width'], 'The geometry is the provider image, not the scene.' );
		$this->assertSame( 72, $result['height'] );
		$this->assertSame( 'png', $result['format'] );
		$this->assertSame( 'image/png', $result['mime_type'] );
		$this->assertSame( \strlen( $output ), $result['bytes'] );
		$this->assertSame( \filesize( $scene ), $result['scene_bytes'] );
		$this->assertSame( \filesize( $product ), $result['product_bytes'] );
		$this->assertArrayNotHasKey( 'original_bytes', $result, 'Two sources have no single original size.' );

		$this->assertTrue( \is_file( $result['path'] ), 'The composed bytes were not written.' );
		$this->assertSame( $output, \file_get_contents( $result['path'] ) );
		$this->assertSame( $this->workDir, \dirname( $result['path'] ) );

		Http::assertSent(
			static function ( $request ): bool {
				return \str_ends_with( $request->url(), '/api/image/compose' )
					&& $request->hasFile( 'files[0]', null, 'scene.bin' )
					&& $request->hasFile( 'files[1]', null, 'product.bin' )
					&& 'gemini' === self::field( $request, 'provider' )
					&& 'Set it on the marble table.' === self::field( $request, 'prompt' );
			}
		);
	}

	public function test_compose_refuses_a_provider_that_cannot_take_two_images(): void {
		$scene   = $this->file( 'scene.bin', 'scene-bytes' );
		$product = $this->file( 'product.bin', 'product-bytes' );

		Http::fake();

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unsupported compose provider: replicate' );

		try {
			$this->production()->compose( $scene, $product, array( 'provider' => 'replicate' ) );
		} finally {
			Http::assertNothingSent();
		}
	}

	public function test_a_missing_provider_key_surfaces_as_a_named_failure(): void {
		$scene   = $this->file( 'scene.bin', 'scene-bytes' );
		$product = $this->file( 'product.bin', 'product-bytes' );

		Http::fake(
			array(
				'worker.test/api/image/compose' => Http::response(
					array(
						'error'      => 'No AI provider key is configured for image composition',
						'capability' => 'image_composition',
					),
					503
				),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'No AI provider key is configured for image composition' );

		$this->production()->compose( $scene, $product );
	}

	public function test_supports_compose_is_false_when_the_worker_cannot_compose(): void {
		$this->fake_matrix(
			array(
				'image_editing'     => true,
				'image_composition' => false,
			)
		);

		$this->assertFalse(
			$this->production()->supports( 'compose' ),
			'A worker that can edit but not compose must not advertise composition.'
		);
	}

	public function test_supports_compose_is_true_when_the_worker_declares_it(): void {
		$this->fake_matrix(
			array(
				'image_editing'     => true,
				'image_composition' => true,
			)
		);

		$this->assertTrue( $this->production()->supports( 'compose' ) );
	}

	/**
	 * @param array<string, bool> $capabilities
	 */
	private function fake_matrix( array $capabilities ): void {
		Http::fake(
			array(
				'worker.test/api/health/full' => Http::response(
					array( 'capabilities' => $capabilities )
				),
			)
		);
	}

	private function production(): MediaWorkerImageProduction {
		$client = new MediaWorkerClient();

		return new MediaWorkerImageProduction( $client, new MediaWorkerCapabilityProbe( $client ) );
	}

	private function file( string $name, string $contents ): string {
		$path = $this->workDir . '/' . $name;
		\file_put_contents( $path, $contents );

		return $path;
	}

	/**
	 * Read one scalar part out of a recorded multipart request.
	 *
	 * `Request::data()` returns the parts array for multipart bodies — the same
	 * array `hasFile()` searches — so a field is the part with that name and a
	 * string `contents`.
	 */
	private static function field( $request, string $name ): ?string {
		foreach ( (array) $request->data() as $part ) {
			if ( \is_array( $part ) && ( $part['name'] ?? null ) === $name && \is_string( $part['contents'] ?? null ) ) {
				return $part['contents'];
			}
		}

		return null;
	}
}
