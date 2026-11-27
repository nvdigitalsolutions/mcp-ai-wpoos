<?php
/**
 * Video generation through the real adapter (Proposal 164 §164.131).
 *
 * The route blocks by default, so the one thing this adapter must never do is
 * wait: `submit()` sends `wait: false` and returns a prediction id. The
 * reference frame crosses as a data URI, which is why no public URL is needed
 * for a worker that may not be reachable from the provider.
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
use Nvoos\Laravel\Adapter\MediaWorkerVideoGeneration;

final class MediaWorkerVideoGenerationTest extends TestCase {

	/**
	 * A 1×1 PNG — enough for the magic-byte sniff.
	 */
	private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

	private string $dir;

	protected function setUp(): void {
		parent::setUp();

		$this->dir = \sys_get_temp_dir() . '/oos-video-gen-' . \bin2hex( \random_bytes( 5 ) );
		\mkdir( $this->dir, 0775, true );

		$this->app['config']->set( 'oos.media_worker.url', 'http://worker.test' );
		$this->app['config']->set( 'oos.media_worker.token', 'test-token' );
	}

	protected function tearDown(): void {
		foreach ( \glob( $this->dir . '/*' ) ?: array() as $file ) {
			if ( \is_file( $file ) ) {
				\unlink( $file );
			}
		}

		if ( \is_dir( $this->dir ) ) {
			\rmdir( $this->dir );
		}

		parent::tearDown();
	}

	public function test_submit_starts_a_render_and_inlines_the_reference_frame(): void {
		$frame = $this->frame();

		Http::fake(
			array(
				'worker.test/api/video/generate' => Http::response(
					array(
						'success'       => true,
						'status'        => 'starting',
						'model'         => 'stable-video-diffusion',
						'prompt'        => 'smooth motion',
						'prediction_id' => 'pred-9',
						'duration'      => 2,
						'fps'           => 6,
						'video_url'     => null,
					)
				),
			)
		);

		$result = $this->generation()->submit(
			'smooth motion',
			array(
				'reference_path'   => $frame,
				'model'            => 'stable-video-diffusion',
				'duration_seconds' => 2,
			)
		);

		$this->assertSame( 'pred-9', $result['prediction_id'] );
		$this->assertSame( 'replicate', $result['provider'] );
		$this->assertSame( 'stable-video-diffusion', $result['model'] );
		$this->assertSame( 'starting', $result['status'] );
		$this->assertSame( 2, $result['duration_seconds'] );

		Http::assertSent(
			static function ( $request ): bool {
				$reference = (string) ( $request['reference_image'] ?? '' );

				return \str_ends_with( $request->url(), '/api/video/generate' )
					&& false === ( $request['wait'] ?? null )
					&& \str_starts_with( $reference, 'data:image/png;base64,' )
					&& 'stable-video-diffusion' === ( $request['model'] ?? null )
					&& 2 === ( $request['duration'] ?? null );
			}
		);
	}

	public function test_a_prompt_only_call_must_name_a_text_model(): void {
		Http::fake();

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'must name a model' );

		try {
			$this->generation()->submit( 'waves at dusk' );
		} finally {
			Http::assertNothingSent();
		}
	}

	public function test_a_missing_frame_is_refused_before_the_request(): void {
		Http::fake();

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'does not exist' );

		try {
			$this->generation()->submit( 'waves', array( 'reference_path' => $this->dir . '/nope.png' ) );
		} finally {
			Http::assertNothingSent();
		}
	}

	public function test_a_file_that_is_not_an_image_is_refused(): void {
		$path = $this->dir . '/notes.txt';
		\file_put_contents( $path, 'not an image' );

		Http::fake();

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'PNG, JPEG or WebP' );

		try {
			$this->generation()->submit( 'waves', array( 'reference_path' => $path ) );
		} finally {
			Http::assertNothingSent();
		}
	}

	public function test_status_maps_a_finished_render(): void {
		Http::fake(
			array(
				'worker.test/api/video/prediction/pred-9' => Http::response(
					array(
						'id'          => 'pred-9',
						'status'      => 'succeeded',
						'model'       => 'stable-video-diffusion',
						'video_url'   => 'https://cdn.example.test/clip.mp4',
						'error'       => null,
						'metrics'     => array( 'predict_time' => 42.5 ),
						'created_at'  => '2026-11-28T00:00:00Z',
						'completed_at' => '2026-11-28T00:01:00Z',
					)
				),
			)
		);

		$status = $this->generation()->status( 'pred-9' );

		$this->assertSame( 'succeeded', $status['status'] );
		$this->assertTrue( $status['terminal'] );
		$this->assertSame( 'https://cdn.example.test/clip.mp4', $status['video_url'] );
		$this->assertNull( $status['error'] );
		$this->assertSame( 42.5, $status['metrics']['predict_time'] );
	}

	public function test_a_running_render_is_not_terminal_and_an_array_url_is_null(): void {
		Http::fake(
			array(
				'worker.test/api/video/prediction/pred-9' => Http::response(
					array(
						'id'        => 'pred-9',
						'status'    => 'processing',
						'video_url' => array( 'https://cdn.example.test/clip.mp4' ),
					)
				),
			)
		);

		$status = $this->generation()->status( 'pred-9' );

		$this->assertSame( 'processing', $status['status'] );
		$this->assertFalse( $status['terminal'] );
		$this->assertNull( $status['video_url'], 'a non-string is not a URL' );
	}

	public function test_supports_follows_the_worker_matrix(): void {
		Http::fake(
			array(
				'worker.test/api/health/full' => Http::response(
					array( 'capabilities' => array( 'video_generation' => false ) )
				),
			)
		);

		$this->assertFalse( $this->generation()->supports( 'generate' ) );
	}

	public function test_supports_is_true_when_the_worker_declares_video_generation(): void {
		Http::fake(
			array(
				'worker.test/api/health/full' => Http::response(
					array( 'capabilities' => array( 'video_generation' => true ) )
				),
			)
		);

		$this->assertTrue( $this->generation()->supports( 'generate' ) );
	}

	private function generation(): MediaWorkerVideoGeneration {
		$client = new MediaWorkerClient();

		return new MediaWorkerVideoGeneration( $client, new MediaWorkerCapabilityProbe( $client ) );
	}

	private function frame(): string {
		$path = $this->dir . '/frame.png';
		\file_put_contents( $path, (string) \base64_decode( self::PNG_1X1, true ) );

		return $path;
	}
}
