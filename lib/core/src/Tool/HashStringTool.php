<?php
/**
 * Hash String tool — computes cryptographic hashes.
 *
 * Pure logic — zero external dependencies. Uses PHP's built-in hash()
 * with md5, sha1, sha256, sha512, and xxh64 support.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Tool;

class HashStringTool extends AbstractTool {

	/**
	 * Supported hash algorithms.
	 *
	 * @var string[]
	 */
	private const ALGORITHMS = array( 'md5', 'sha1', 'sha256', 'sha512' );

	public function getSlug(): string {
		return 'hash_string';
	}

	public function getName(): string {
		return 'Hash String';
	}

	public function getDescription(): string {
		return 'Computes a cryptographic hash of a string. Supports MD5, SHA-1, SHA-256, and SHA-512. Use SHA-256 or SHA-512 for security-sensitive operations.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'text'       => array(
					'type'        => 'string',
					'description' => 'The text to hash.',
				),
				'algorithm'  => array(
					'type'        => 'string',
					'description' => 'Hash algorithm: md5, sha1, sha256, sha512. Default: sha256.',
					'enum'        => self::ALGORITHMS,
					'default'     => 'sha256',
				),
				'salt'       => array(
					'type'        => 'string',
					'description' => 'Optional salt to prepend before hashing.',
				),
			),
			'required'             => array( 'text' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'read';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$text = $this->stringParam( $arguments, 'text' );
		if ( '' === $text ) {
			return $this->errors->validationFailed(
				'text is required.',
				array( 'text' => array( 'Text to hash is required.' ) ),
			);
		}

		$algorithm = $this->stringParam( $arguments, 'algorithm', 'sha256' );
		if ( ! in_array( $algorithm, self::ALGORITHMS, true ) ) {
			return $this->errors->validationFailed(
				sprintf( 'Unsupported algorithm: "%s". Use: %s.', $algorithm, implode( ', ', self::ALGORITHMS ) ),
				array( 'algorithm' => array( 'Must be one of: ' . implode( ', ', self::ALGORITHMS ) ) ),
			);
		}

		$salt = $this->stringParam( $arguments, 'salt' );
		$input = '' !== $salt ? $salt . $text : $text;
		$hash  = hash( $algorithm, $input );

		return $this->success(
			sprintf( '%s hash computed.', strtoupper( $algorithm ) ),
			array(
				'algorithm' => $algorithm,
				'hash'      => $hash,
				'salted'    => '' !== $salt,
				'length'    => strlen( $hash ),
			),
		);
	}
}
