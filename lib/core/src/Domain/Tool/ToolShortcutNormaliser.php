<?php
/**
 * Tool-shortcut normalisation.
 *
 * Turns a raw list of assistant-scoped shortcuts into the shape the console
 * renders. The rules look cosmetic but three of them are load-bearing:
 *
 *  - A shortcut with no label borrows its tool name, and one with no payload
 *    borrows its tool name or label. A shortcut with neither is dropped — the
 *    console would otherwise render a blank tile that does nothing.
 *  - Ids are derived from the tool (or the label) and suffixed `-2`, `-3` on
 *    collision, so React keys stay unique across duplicate entries.
 *  - The search matches label, description and tool together, case-insensitively,
 *    so typing "weather" finds a shortcut whose only mention is the tool slug.
 *
 * Framework-neutral: arrays and strings in, arrays out. The fallback category
 * and icon are passed in because WordPress translates them.
 *
 * Extracted from `WP_MCP_AI_REST_Tool_Shortcuts::handle_list()` under Proposal
 * 090-I and relicensed MIT, so both runtimes can be held to one implementation
 * instead of two copies that agree until they do not.
 *
 * @package Nvoos\Core
 * @since   1.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Tool;

final class ToolShortcutNormaliser {

	/**
	 * Normalise a raw shortcut list.
	 *
	 * @param array<int, mixed> $raw               Raw entries; non-arrays are skipped.
	 * @param string            $search            Optional case-insensitive filter.
	 * @param string            $fallbackCategory  Category for entries that declare none.
	 * @param string            $fallbackIcon      Icon for entries that declare none.
	 * @return array{shortcuts: array<int, array<string, string>>, categories: string[]}
	 */
	public static function normalise(
		array $raw,
		string $search = '',
		string $fallbackCategory = 'General',
		string $fallbackIcon = "\u{26A1}"
	): array {
		$shortcuts  = array();
		$categories = array();
		$needle     = self::lower( trim( $search ) );

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$label       = self::text( $entry['label'] ?? null );
			$payload     = self::text( $entry['payload'] ?? null );
			$tool        = self::text( $entry['tool'] ?? null );
			$description = self::text( $entry['description'] ?? null );
			$category    = self::text( $entry['category'] ?? null );
			$icon        = self::text( $entry['icon'] ?? null );

			if ( '' === $label && '' !== $tool ) {
				$label = $tool;
			}

			if ( '' === $payload ) {
				$payload = '' !== $tool ? $tool : $label;
			}

			if ( '' === $label && '' === $payload ) {
				continue;
			}

			if ( '' === $category ) {
				$category = $fallbackCategory;
			}

			if ( '' === $icon ) {
				$icon = $fallbackIcon;
			}

			if ( '' !== $needle && ! str_contains( self::lower( $label . ' ' . $description . ' ' . $tool ), $needle ) ) {
				continue;
			}

			$id     = '' !== $tool ? self::slug( $tool ) : self::slug( $label );
			$suffix = 1;
			$base   = $id;

			while ( isset( $shortcuts[ $id ] ) ) {
				$id = $base . '-' . ( ++$suffix );
			}

			$shortcuts[ $id ] = array(
				'id'          => $id,
				'label'       => $label,
				'payload'     => $payload,
				'tool'        => $tool,
				'description' => $description,
				'category'    => $category,
				'icon'        => $icon,
			);

			if ( ! in_array( $category, $categories, true ) ) {
				$categories[] = $category;
			}
		}

		sort( $categories );

		return array(
			'shortcuts'  => array_values( $shortcuts ),
			'categories' => $categories,
		);
	}

	/**
	 * Trimmed string, or '' for anything that is not a scalar.
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Lowercase, multibyte when the extension is present.
	 */
	private static function lower( string $value ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
	}

	/**
	 * The `sanitize_key()` alphabet: lowercase a-z, digits, underscore, hyphen.
	 */
	private static function slug( string $value ): string {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) );
	}
}
