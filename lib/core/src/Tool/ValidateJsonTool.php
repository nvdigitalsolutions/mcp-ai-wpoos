<?php
/**
 * Validate JSON tool — validates and optionally formats JSON strings.
 *
 * Pure logic — zero external dependencies.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

class ValidateJsonTool extends AbstractTool {

	public function getSlug(): string {
		return 'validate_json';
	}

	public function getName(): string {
		return 'Validate JSON';
	}

	public function getDescription(): string {
		return 'Validates a JSON string, returning parsed data or detailed error information. Can optionally pretty-print valid JSON.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'json'      => array(
					'type'        => 'string',
					'description' => 'The JSON string to validate.',
				),
				'pretty'    => array(
					'type'        => 'boolean',
					'description' => 'Pretty-print valid JSON. Default: false.',
					'default'     => false,
				),
			),
			'required'             => array( 'json' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'read';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$json = $this->stringParam( $arguments, 'json' );
		if ( '' === $json ) {
			return $this->errors->validationFailed(
				'json is required.',
				array( 'json' => array( 'A JSON string is required.' ) ),
			);
		}

		$pretty = $this->boolParam( $arguments, 'pretty', false );

		$data = json_decode( $json, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return $this->success(
				'JSON is invalid.',
				array(
					'valid'        => false,
					'error'        => json_last_error_msg(),
					'error_code'   => json_last_error(),
					'error_line'   => $this->findErrorLine( $json ),
				),
			);
		}

		$output = $data;
		if ( $pretty ) {
			$output = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		return $this->success(
			'JSON is valid.',
			array(
				'valid'   => true,
				'type'    => $this->describeType( $data ),
				'keys'    => is_array( $data ) ? count( $data ) : 0,
				'data'    => $output,
			),
		);
	}

	/**
	 * Attempt to find the line with the JSON error.
	 */
	private function findErrorLine( string $json ): int {
		$lines = explode( "\n", $json );
		for ( $i = 0; $i < count( $lines ); $i++ ) {
			$test = implode( "\n", array_slice( $lines, 0, $i + 1 ) );
			json_decode( $test );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return $i + 1;
			}
		}
		return count( $lines );
	}

	/**
	 * Describe the top-level type of decoded JSON data.
	 */
	private function describeType( mixed $data ): string {
		if ( is_array( $data ) ) {
			return array_keys( $data ) === range( 0, count( $data ) - 1 ) ? 'array' : 'object';
		}
		if ( is_string( $data ) ) {
			return 'string';
		}
		if ( is_int( $data ) ) {
			return 'integer';
		}
		if ( is_float( $data ) ) {
			return 'float';
		}
		if ( is_bool( $data ) ) {
			return 'boolean';
		}
		if ( null === $data ) {
			return 'null';
		}
		return gettype( $data );
	}
}
