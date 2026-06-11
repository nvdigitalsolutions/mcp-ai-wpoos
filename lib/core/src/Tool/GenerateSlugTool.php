<?php
/**
 * Generate Slug tool — converts text to a URL-friendly slug.
 *
 * Pure string manipulation — zero external dependencies.
 * Framework-agnostic.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

class GenerateSlugTool extends AbstractTool {

	public function getSlug(): string {
		return 'generate_slug';
	}

	public function getName(): string {
		return 'Generate Slug';
	}

	public function getDescription(): string {
		return 'Converts any text into a URL-friendly slug (lowercase, hyphens, no special characters). Useful for creating post slugs, filenames, or identifiers.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'text'       => array(
					'type'        => 'string',
					'description' => 'The text to convert to a slug.',
				),
				'max_length' => array(
					'type'        => 'integer',
					'description' => 'Maximum slug length. Default: 200.',
					'default'     => 200,
					'minimum'     => 1,
					'maximum'     => 500,
				),
				'separator'  => array(
					'type'        => 'string',
					'description' => 'Word separator character. Default: "-".',
					'default'     => '-',
					'maxLength'   => 1,
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
				array( 'text' => array( 'Text to convert is required.' ) ),
			);
		}

		$maxLength = $this->intParam( $arguments, 'max_length', 200 );
		$separator = $this->stringParam( $arguments, 'separator', '-' );
		if ( '' === $separator ) {
			$separator = '-';
		}

		$slug = $this->slugify( $text, $separator, $maxLength );

		return $this->success(
			sprintf( 'Slug generated: "%s".', $slug ),
			array(
				'original' => $text,
				'slug'     => $slug,
				'length'   => strlen( $slug ),
			),
		);
	}

	/**
	 * Convert text to a URL-safe slug.
	 */
	private function slugify( string $text, string $separator, int $maxLength ): string {
		// Transliterate to ASCII (basic support).
		$text = $this->transliterate( $text );

		// Lowercase.
		$text = mb_strtolower( $text, 'UTF-8' );

		// Replace non-alphanumeric characters with separator.
		$text = preg_replace( '/[^a-z0-9]+/', $separator, $text );

		// Trim separators from edges.
		$text = trim( $text, $separator );

		// Truncate to max length without breaking a word boundary if possible.
		if ( strlen( $text ) > $maxLength ) {
			$text = substr( $text, 0, $maxLength );
			$text = rtrim( $text, $separator );
		}

		return '' === $text ? 'untitled' : $text;
	}

	/**
	 * Basic ASCII transliteration for common accented characters.
	 */
	private function transliterate( string $text ): string {
		$map = array(
			'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
			'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
			'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
			'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
			'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
			'ñ' => 'n', 'ç' => 'c',
			'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A',
			'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
			'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
			'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
			'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
			'Ñ' => 'N', 'Ç' => 'C',
		);

		return strtr( $text, $map );
	}
}
