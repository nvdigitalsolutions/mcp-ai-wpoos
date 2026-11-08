<?php
/**
 * Format Bytes tool — converts byte counts to human-readable strings.
 *
 * Pure formatting — zero external dependencies.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\ValueObject\FormatBytesRequest;
use Nvoos\Core\Domain\ValueObject\ToolResult;

class FormatBytesTool extends AbstractResultTool {

	/**
	 * Human-readable size units.
	 */
	private const UNITS = array( 'B', 'KB', 'MB', 'GB', 'TB', 'PB' );

	public function getSlug(): string {
		return 'format_bytes';
	}

	public function getName(): string {
		return 'Format Bytes';
	}

	public function getDescription(): string {
		return 'Converts a byte count to a human-readable string (e.g., 1536 → "1.5 KB"). Useful for displaying file sizes, memory limits, or disk usage.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'bytes'     => array(
					'type'        => 'integer',
					'description' => 'Number of bytes to format.',
					'minimum'     => 0,
				),
				'decimals'  => array(
					'type'        => 'integer',
					'description' => 'Number of decimal places. Default: 1.',
					'default'     => 1,
					'minimum'     => 0,
					'maximum'     => 6,
				),
				'binary'    => array(
					'type'        => 'boolean',
					'description' => 'Use binary units (1024-based: KiB, MiB) instead of decimal (1000-based: KB, MB). Default: false.',
					'default'     => false,
				),
			),
			'required'             => array( 'bytes' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'read';
	}

	/**
	 * Execute the tool and return a typed result.
	 *
	 * Parse once, then hand the request to a pure handler — the functional
	 * core / imperative shell split (Red Book ch. 13).
	 *
	 * @param ToolArgs    $arguments Parsed tool arguments.
	 * @param ToolContext $context   Execution context.
	 * @return ToolResult<array{bytes: int, formatted: string, binary: bool, base: int}>
	 */
	public function executeResult( array $arguments = array(), array $context = array() ): ToolResult {
		return $this->handle( FormatBytesRequest::fromArguments( $arguments ) );
	}

	/**
	 * Pure handler — no input arrays, no output arrays, no side effects.
	 *
	 * @param FormatBytesRequest $request Parsed request.
	 * @return ToolResult<array{bytes: int, formatted: string, binary: bool, base: int}>
	 */
	private function handle( FormatBytesRequest $request ): ToolResult {
		if ( $request->bytes < 0 ) {
			return $this->validationFailure(
				'bytes must be a non-negative integer.',
				array( 'bytes' => array( 'Value must be >= 0.' ) ),
			);
		}

		$base   = $request->binary ? 1024 : 1000;
		$suffix = $request->binary ? 'iB' : 'B';
		$result = $this->format( $request->bytes, $base, $request->decimals, $suffix );

		return ToolResult::success(
			sprintf( '%d bytes = %s.', $request->bytes, $result ),
			array(
				'bytes'     => $request->bytes,
				'formatted' => $result,
				'binary'    => $request->binary,
				'base'      => $base,
			),
		);
	}

	/**
	 * Format bytes to human-readable string.
	 */
	private function format( int $bytes, int $base, int $decimals, string $suffix ): string {
		if ( 0 === $bytes ) {
			return '0 ' . ( 1024 === $base ? 'KiB' : 'KB' );
		}

		$unitIndex = (int) floor( log( $bytes, $base ) );
		$unitIndex = min( $unitIndex, count( self::UNITS ) - 1 );

		$value = $bytes / pow( $base, $unitIndex );
		$unit  = self::UNITS[ $unitIndex ];

		if ( 1024 === $base ) {
			$unit = str_replace( 'B', 'iB', $unit );
			// Special case: no 'i' prefix for bytes themselves.
			if ( 0 === $unitIndex ) {
				$unit = 'B';
			}
		}

		return sprintf( "%.{$decimals}f %s", $value, $unit );
	}
}
