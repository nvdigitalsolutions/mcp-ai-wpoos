<?php
/**
 * Generate UUID tool — generates universally unique identifiers.
 *
 * Pure logic — zero external dependencies. Uses PHP's random_bytes().
 * Framework-agnostic.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Tool;

class GenerateUuidTool extends AbstractTool {

	public function getSlug(): string {
		return 'generate_uuid';
	}

	public function getName(): string {
		return 'Generate UUID';
	}

	public function getDescription(): string {
		return 'Generates a UUID v4 (random) or v7 (time-ordered) identifier. Useful for creating unique keys, database IDs, or request correlation IDs.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'version' => array(
					'type'        => 'integer',
					'description' => 'UUID version: 4 (random, default) or 7 (time-ordered).',
					'enum'        => array( 4, 7 ),
					'default'     => 4,
				),
				'count'   => array(
					'type'        => 'integer',
					'description' => 'Number of UUIDs to generate. Default: 1. Max: 100.',
					'default'     => 1,
					'minimum'     => 1,
					'maximum'     => 100,
				),
			),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'read';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$version = $this->intParam( $arguments, 'version', 4 );
		$count   = $this->intParam( $arguments, 'count', 1 );
		$count   = max( 1, min( 100, $count ) );

		if ( 7 === $version ) {
			return $this->success(
				sprintf( '%d UUID v7 generated.', $count ),
				array(
					'uuids'   => array_map( fn() => $this->uuidv7(), range( 1, $count ) ),
					'version' => 7,
					'count'   => $count,
				),
			);
		}

		return $this->success(
			1 === $count ? 'UUID v4 generated.' : sprintf( '%d UUIDs v4 generated.', $count ),
			array(
				'uuids'   => array_map( fn() => $this->uuidv4(), range( 1, $count ) ),
				'version' => 4,
				'count'   => $count,
			),
		);
	}

	/**
	 * Generate a UUID v4 (random).
	 */
	private function uuidv4(): string {
		$bytes = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 ); // Version 4.
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 ); // Variant 1.

		return sprintf(
			'%s-%s-%s-%s-%s',
			bin2hex( substr( $bytes, 0, 4 ) ),
			bin2hex( substr( $bytes, 4, 2 ) ),
			bin2hex( substr( $bytes, 6, 2 ) ),
			bin2hex( substr( $bytes, 8, 2 ) ),
			bin2hex( substr( $bytes, 10, 6 ) ),
		);
	}

	/**
	 * Generate a UUID v7 (time-ordered).
	 *
	 * 48-bit timestamp in milliseconds + 74 random bits.
	 */
	private function uuidv7(): string {
		$millis = (int) ( microtime( true ) * 1000 );
		$rand   = random_bytes( 10 );

		return sprintf(
			'%08x-%04x-7%03x-%04x-%012x',
			(int) ( $millis >> 16 ),
			(int) ( $millis & 0xffff ),
			(int) ( hexdec( bin2hex( substr( $rand, 0, 2 ) ) ) & 0x0fff ),
			0x8000 | ( hexdec( bin2hex( substr( $rand, 2, 2 ) ) ) & 0x3fff ),
			hexdec( bin2hex( substr( $rand, 4, 6 ) ) ),
		);
	}
}
