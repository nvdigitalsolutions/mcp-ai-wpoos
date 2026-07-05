<?php
/**
 * NV oOS Crocoblock DS — DTCG Exporter
 *
 * Exports design tokens in the W3C Design Tokens Community Group (DTCG)
 * format (2025.10 spec), enabling interoperability with Tokens Studio for
 * Figma, Style Dictionary, Terrazzo, and other DTCG-compliant tools.
 *
 * @package NV_oOS_Crocoblock_DS
 * @since   0.1.0
 *
 * @see https://www.designtokens.org/tr/2025.10/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports tokens from the registry into DTCG-compliant JSON.
 *
 * Maps our flat token model to the DTCG hierarchical structure:
 *   group → token-id → { $type, $value, $description }
 *
 * @since 0.1.0
 */
class NV_oOS_Crocoblock_DS_DTCG_Exporter {

	/**
	 * Token registry instance.
	 *
	 * @var NV_oOS_Crocoblock_DS_Token_Registry
	 */
	private $registry;

	/**
	 * Map of CDS token types to DTCG $type values.
	 *
	 * @var array<string, string>
	 */
	private $type_map = array(
		'color'       => 'color',
		'size'        => 'dimension',
		'font'        => 'fontFamily',
		'shadow'      => 'shadow',
		'transition'  => 'duration',
	);

	/**
	 * Constructor.
	 *
	 * @param NV_oOS_Crocoblock_DS_Token_Registry $registry Token registry.
	 */
	public function __construct( $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Export all tokens as DTCG-compliant JSON.
	 *
	 * @param bool $pretty_print Whether to pretty-print the JSON output.
	 * @return string JSON string.
	 */
	public function export( $pretty_print = true ) {
		$grouped = $this->registry->get_grouped();
		$output  = array();

		foreach ( $grouped as $group_key => $tokens ) {
			$group_obj = array();

			foreach ( $tokens as $token ) {
				$dtcg_type          = $this->map_type( $token->type );
				$group_obj[ $token->id ] = array(
					'$type'  => $dtcg_type,
					'$value' => $token->value,
				);

				if ( $token->description ) {
					$group_obj[ $token->id ]['$description'] = $token->description;
				}
			}

			$output[ $group_key ] = $group_obj;
		}

		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if ( $pretty_print ) {
			$flags |= JSON_PRETTY_PRINT;
		}

		return wp_json_encode( $output, $flags );
	}

	/**
	 * Map a CDS token type to its DTCG $type equivalent.
	 *
	 * @param string $cds_type CDS token type (color, size, font, shadow, transition).
	 * @return string DTCG $type value.
	 */
	private function map_type( $cds_type ) {
		return isset( $this->type_map[ $cds_type ] ) ? $this->type_map[ $cds_type ] : 'string';
	}
}
