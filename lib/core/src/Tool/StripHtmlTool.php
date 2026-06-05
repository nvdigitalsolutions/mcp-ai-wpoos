<?php
/**
 * Strip HTML tool — removes HTML tags from text.
 *
 * Pure PHP — zero external dependencies. Uses PHP's built-in strip_tags()
 * and html_entity_decode(). Framework-agnostic.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

class StripHtmlTool extends AbstractTool {

	public function getSlug(): string {
		return 'strip_html';
	}

	public function getName(): string {
		return 'Strip HTML';
	}

	public function getDescription(): string {
		return 'Removes HTML tags from text, optionally preserving allowed tags. Useful for cleaning user input or extracting plain text from rich content.';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'html'            => array(
					'type'        => 'string',
					'description' => 'The HTML content to strip.',
				),
				'allowed_tags'    => array(
					'type'        => 'array',
					'description' => 'Optional list of HTML tags to preserve (e.g., ["a", "b", "i"]).',
					'items'       => array( 'type' => 'string' ),
				),
				'decode_entities' => array(
					'type'        => 'boolean',
					'description' => 'Decode HTML entities to plain characters. Default: true.',
					'default'     => true,
				),
			),
			'required'             => array( 'html' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'read';
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$html = isset( $arguments['html'] ) && is_string( $arguments['html'] ) ? trim( $arguments['html'] ) : '';
		if ( '' === $html ) {
			return $this->errors->validationFailed(
				'html is required.',
				array( 'html' => array( 'HTML content is required.' ) ),
			);
		}

		$allowedTags    = $arguments['allowed_tags'] ?? null;
		$decodeEntities = $this->boolParam( $arguments, 'decode_entities', true );

		if ( is_array( $allowedTags ) && array() !== $allowedTags ) {
			$allowable = '<' . implode( '><', $allowedTags ) . '>';
			$cleaned   = strip_tags( $html, $allowable );
		} else {
			// Strip all tags, including script/style content.
			$cleaned = strip_tags( $html );
			// Remove script/style block contents that strip_tags misses.
			$cleaned = preg_replace(
				array( '@<script[^>]*?>.*?</script>@si', '@<style[^>]*?>.*?</style>@si' ),
				'',
				$cleaned,
			);
		}

		if ( $decodeEntities ) {
			$cleaned = html_entity_decode( $cleaned, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		// Normalize whitespace.
		$cleaned = preg_replace( '/\s+/', ' ', $cleaned );
		$cleaned = trim( $cleaned );

		$originalLen = strlen( $html );
		$cleanedLen  = strlen( $cleaned );
		$reduction = round( ( 1 - $cleanedLen / $originalLen ) * 100, 1 );

		return $this->success(
			sprintf( 'HTML stripped (%d → %d chars, %.1f%% reduction).', $originalLen, $cleanedLen, $reduction ),
			array(
				'original_length' => $originalLen,
				'cleaned_length'  => $cleanedLen,
				'reduction_pct'   => $reduction,
				'text'            => $cleaned,
			),
		);
	}
}
