<?php
/**
 * LaTeX rendering through the real adapter (Proposal 164 §164.167).
 *
 * The whole point of this adapter is the payload it sends: the WordPress tool
 * sent the raw tool arguments, whose rendering options are spelled
 * `display_mode` and `output_format`, while the route reads `options.displayMode`
 * and `options.output`. Every request therefore rendered in display mode as HTML
 * and said nothing about it. The first test here is the one that would have
 * caught that.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.2.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use Nvoos\Laravel\Adapter\MediaWorkerClient;
use Nvoos\Laravel\Adapter\MediaWorkerMathRenderer;

final class MediaWorkerMathRendererTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->app['config']->set( 'oos.media_worker.url', 'http://worker.test' );
		$this->app['config']->set( 'oos.media_worker.token', 'test-token' );
	}

	public function test_it_sends_the_option_names_the_route_reads(): void {
		Http::fake(
			array(
				'worker.test/api/data/render-math' => Http::response(
					array(
						'success' => true,
						'html'    => '<span class="katex">x</span>',
						'latex'   => 'x^2',
					)
				),
			)
		);

		$this->renderer()->render( 'x^2', false, 'html' );

		Http::assertSent(
			static function ( $request ): bool {
				$options = (array) ( $request['options'] ?? array() );

				return \str_ends_with( $request->url(), '/api/data/render-math' )
					&& 'x^2' === ( $request['latex'] ?? null )
					&& false === ( $options['displayMode'] ?? null )
					&& 'html' === ( $options['output'] ?? null );
			}
		);
	}

	public function test_display_mode_and_mathml_cross_as_the_route_expects(): void {
		Http::fake(
			array(
				'worker.test/api/data/render-math' => Http::response(
					array( 'success' => true, 'html' => '<math></math>' )
				),
			)
		);

		$this->renderer()->render( 'x^2', true, 'mathml' );

		Http::assertSent(
			static function ( $request ): bool {
				$options = (array) ( $request['options'] ?? array() );

				return true === ( $options['displayMode'] ?? null )
					&& 'mathml' === ( $options['output'] ?? null );
			}
		);
	}

	public function test_the_combined_shape_uses_katex_spelling(): void {
		Http::fake(
			array(
				'worker.test/api/data/render-math' => Http::response(
					array( 'success' => true, 'html' => '<span></span>' )
				),
			)
		);

		// `html_and_mathml` is the schema's spelling. KaTeX calls it
		// `htmlAndMathml` and throws a settings error on anything else, which is
		// not a parse error, so `throwOnError` would not soften it.
		$this->renderer()->render( 'x^2', false, 'html_and_mathml' );

		Http::assertSent(
			static function ( $request ): bool {
				$options = (array) ( $request['options'] ?? array() );

				return 'htmlAndMathml' === ( $options['output'] ?? null );
			}
		);
	}

	public function test_it_returns_the_markup_and_the_expression(): void {
		Http::fake(
			array(
				'worker.test/api/data/render-math' => Http::response(
					array(
						'success' => true,
						'html'    => '<span class="katex">x^2</span>',
						'latex'   => 'x^2',
					)
				),
			)
		);

		$rendered = $this->renderer()->render( 'x^2', false, 'html' );

		$this->assertSame( '<span class="katex">x^2</span>', $rendered['markup'] );
		$this->assertSame( 'x^2', $rendered['latex'] );
	}

	public function test_a_response_without_markup_is_refused(): void {
		Http::fake(
			array(
				'worker.test/api/data/render-math' => Http::response(
					array( 'success' => true, 'latex' => 'x^2' )
				),
			)
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'without rendered markup' );

		$this->renderer()->render( 'x^2', false, 'html' );
	}

	public function test_an_unknown_output_is_refused_before_the_request(): void {
		Http::fake();

		$this->expectException( \InvalidArgumentException::class );

		try {
			$this->renderer()->render( 'x^2', false, 'latex' );
		} finally {
			Http::assertNothingSent();
		}
	}

	public function test_an_unconfigured_worker_is_unavailable(): void {
		$this->app['config']->set( 'oos.media_worker.url', '' );

		Http::fake();

		$this->assertFalse( $this->renderer()->isAvailable() );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'not configured' );

		try {
			$this->renderer()->render( 'x^2', false, 'html' );
		} finally {
			Http::assertNothingSent();
		}
	}

	private function renderer(): MediaWorkerMathRenderer {
		return new MediaWorkerMathRenderer( new MediaWorkerClient() );
	}
}
