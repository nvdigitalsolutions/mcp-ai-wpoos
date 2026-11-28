<?php
/**
 * NV oOS Design System — DTCG Exporter.
 *
 * Exports the flat token registry as a W3C Design Tokens Community Group
 * (2025.10) document. Value shaping is delegated to the DTCG codec so colour,
 * dimension, shadow and font tokens are emitted in spec form rather than as
 * raw CSS strings.
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 * @since   1.0.1 Codec-backed, spec-shaped output.
 *
 * @see https://www.designtokens.org/tr/2025.10/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports tokens from the registry into DTCG-compliant JSON.
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_DTCG_Exporter {

	/**
	 * Token registry instance.
	 *
	 * @var NV_oOS_Design_System_Token_Registry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @param NV_oOS_Design_System_Token_Registry $registry Token registry.
	 */
	public function __construct( $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Export all tokens as a DTCG document.
	 *
	 * @param bool                 $pretty_print Whether to pretty-print.
	 * @param array<string, mixed> $args         Optional: `groups` (array of group ids), `include_descriptions` (bool).
	 * @return string JSON string.
	 */
	public function export( $pretty_print = true, $args = array() ) {
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if ( $pretty_print ) {
			$flags |= JSON_PRETTY_PRINT;
		}

		return wp_json_encode( $this->export_array( $args ), $flags );
	}

	/**
	 * Export all tokens as a DTCG array.
	 *
	 * @param array<string, mixed> $args Optional: `groups`, `include_descriptions`.
	 * @return array<string, array<string, array>>
	 */
	public function export_array( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'groups'               => array(),
				'include_descriptions' => true,
			)
		);

		$grouped = $this->registry->get_grouped();
		$output  = array();

		foreach ( $grouped as $group_key => $tokens ) {
			if ( ! empty( $args['groups'] ) && ! in_array( $group_key, (array) $args['groups'], true ) ) {
				continue;
			}

			$group_obj = array();

			foreach ( $tokens as $token ) {
				$entry = NV_oOS_Design_System_DTCG_Codec::to_dtcg( $token );

				if ( empty( $args['include_descriptions'] ) ) {
					unset( $entry['$description'] );
				}

				$group_obj[ $token->id ] = $entry;
			}

			if ( ! empty( $group_obj ) ) {
				$output[ $group_key ] = $group_obj;
			}
		}

		return $output;
	}

	/**
	 * Suggested download filename.
	 *
	 * @return string
	 */
	public function get_filename() {
		return 'nvoos-design-system.tokens.json';
	}
}
