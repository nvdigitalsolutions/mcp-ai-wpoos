<?php
/**
 * Base64 Encode/Decode tool — converts between binary data and base64 text.
 *
 * Pure logic — zero external dependencies.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Tool;

class Base64Tool extends AbstractTool {

	public function getSlug(): string {
		return 'base64';
	}

	public function getName(): string {
		return 'Base64 Encode / Decode';
	}

	public function getDescription(): string {
		return 'Encodes text or binary data to base64, or decodes base64 back to plain text. Supports standard, URL-safe, and data-URI formats.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'text'    => array(
					'type'        => 'string',
					'description' => 'Text to encode. Mutually exclusive with base64.',
				),
				'base64'  => array(
					'type'        => 'string',
					'description' => 'Base64 string to decode. Mutually exclusive with text.',
				),
				'variant' => array(
					'type'        => 'string',
					'description' => 'Base64 variant: standard (default), urlsafe, or data_uri.',
					'enum'        => array( 'standard', 'urlsafe', 'data_uri' ),
					'default'     => 'standard',
				),
			),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'read';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$text    = $this->stringParam( $arguments, 'text' );
		$base64  = $this->stringParam( $arguments, 'base64' );
		$variant = $this->stringParam( $arguments, 'variant', 'standard' );

		// Decode mode.
		if ( '' !== $base64 ) {
			$result = base64_decode( $base64, true );
			if ( false === $result ) {
				return $this->success(
					'Invalid base64 input.',
					array(
						'valid'  => false,
						'output' => null,
					),
				);
			}

			return $this->success(
				'Base64 decoded successfully.',
				array(
					'valid'     => true,
					'operation' => 'decode',
					'output'    => $result,
					'length'    => strlen( $result ),
				),
			);
		}

		// Encode mode.
		if ( '' === $text ) {
			return $this->errors->validationFailed(
				'Either text or base64 parameter is required.',
				array( 'input' => array( 'Provide text to encode or base64 to decode.' ) ),
			);
		}

		if ( 'urlsafe' === $variant ) {
			$encoded = rtrim( strtr( base64_encode( $text ), '+/', '-_' ), '=' );
		} elseif ( 'data_uri' === $variant ) {
			$encoded = 'data:text/plain;base64,' . base64_encode( $text );
		} else {
			$encoded = base64_encode( $text );
		}

		return $this->success(
			'Base64 encoded successfully.',
			array(
				'valid'     => true,
				'operation' => 'encode',
				'variant'   => $variant,
				'output'    => $encoded,
				'length'    => strlen( $encoded ),
			),
		);
	}
}
