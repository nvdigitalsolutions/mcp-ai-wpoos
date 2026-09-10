<?php
/**
 * document-automation/class-wp-mcp-ai-tool-lf-pleading-generator.php (ecosystem port — Wave F4, law-firm document-automation batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/law-firm/document-automation/class-wp-mcp-ai-tool-lf-pleading-generator.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates pleading outlines with standard sections for litigation documents.
 */
class WP_MCP_AI_Tool_LF_Pleading_Generator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_law_firm_toolkit'] );
	}

	/**
	 * Get the reason the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason(): string {
		return __( 'Law Firm toolkit is not enabled.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'lf_pleading_generator';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Pleading Generator', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Generates structured pleading outlines for complaints, answers, motions to dismiss, motions for summary judgment, motions to compel, and motions in limine.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'pleading_type' => array(
					'type'        => 'string',
					'description' => __( 'Type of pleading to generate.', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'complaint', 'answer', 'motion_to_dismiss', 'motion_for_summary_judgment', 'motion_to_compel', 'motion_in_limine' ),
				),
				'matter_id'     => array(
					'type'        => 'integer',
					'description' => __( 'Associated matter ID.', 'nvoos-content-graph-pro' ),
				),
				'court'         => array(
					'type'        => 'string',
					'description' => __( 'Name of the court (e.g., "U.S. District Court, Northern District of California").', 'nvoos-content-graph-pro' ),
				),
				'jurisdiction'  => array(
					'type'        => 'string',
					'description' => __( 'Jurisdiction (e.g., "CA", "federal").', 'nvoos-content-graph-pro' ),
				),
				'parties'       => array(
					'type'        => 'array',
					'description' => __( 'Parties involved (plaintiff, defendant, etc.).', 'nvoos-content-graph-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'grounds'       => array(
					'type'        => 'string',
					'description' => __( 'Legal grounds or basis for the pleading.', 'nvoos-content-graph-pro' ),
				),
			),
			'required'   => array( 'pleading_type' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'nvoos-content-graph-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$pleading_type = isset( $arguments['pleading_type'] ) ? sanitize_text_field( $arguments['pleading_type'] ) : '';
		$matter_id     = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;
		$court         = isset( $arguments['court'] ) ? sanitize_text_field( $arguments['court'] ) : '';
		$jurisdiction  = isset( $arguments['jurisdiction'] ) ? sanitize_text_field( $arguments['jurisdiction'] ) : '';
		$grounds       = isset( $arguments['grounds'] ) ? sanitize_textarea_field( $arguments['grounds'] ) : '';
		$parties       = array();

		if ( ! empty( $arguments['parties'] ) && is_array( $arguments['parties'] ) ) {
			foreach ( $arguments['parties'] as $party ) {
				$parties[] = sanitize_text_field( $party );
			}
		}

		$valid_types = array( 'complaint', 'answer', 'motion_to_dismiss', 'motion_for_summary_judgment', 'motion_to_compel', 'motion_in_limine' );
		if ( ! in_array( $pleading_type, $valid_types, true ) ) {
			return new WP_Error( 'invalid_param', __( 'Invalid pleading type.', 'nvoos-content-graph-pro' ) );
		}

		$outline = $this->build_outline( $pleading_type, $court, $jurisdiction, $parties, $grounds );

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %s: pleading type */
				__( '%s outline generated successfully. ', 'nvoos-content-graph-pro' ),
				ucwords( str_replace( '_', ' ', $pleading_type ) )
			) . self::DISCLAIMER,
			'data'       => array(
				'pleading_type' => $pleading_type,
				'matter_id'     => $matter_id,
				'court'         => $court,
				'jurisdiction'  => $jurisdiction,
				'parties'       => $parties,
				'outline'       => $outline,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * Build a pleading outline for the given type.
	 *
	 * @param string $type         Pleading type.
	 * @param string $court        Court name.
	 * @param string $jurisdiction Jurisdiction.
	 * @param array  $parties      Parties list.
	 * @param string $grounds      Legal grounds.
	 * @return array Outline sections.
	 */
	private function build_outline( string $type, string $court, string $jurisdiction, array $parties, string $grounds ): array {
		$caption = array(
			'section' => 'caption',
			'content' => array(
				'court'     => $court ? $court : __( '[COURT NAME]', 'nvoos-content-graph-pro' ),
				'plaintiff' => $parties[0] ?? __( '[PLAINTIFF]', 'nvoos-content-graph-pro' ),
				'defendant' => $parties[1] ?? __( '[DEFENDANT]', 'nvoos-content-graph-pro' ),
				'case_no'   => __( '[CASE NUMBER]', 'nvoos-content-graph-pro' ),
			),
		);

		$certificate = array(
			'section' => 'certificate_of_service',
			'content' => __( 'I hereby certify that a true and correct copy of the foregoing was served upon all parties of record.', 'nvoos-content-graph-pro' ),
		);

		$structures = array(
			'complaint'                   => array(
				$caption,
				array(
					'section' => 'introduction',
					'content' => __( 'Nature of the action and relief sought.', 'nvoos-content-graph-pro' ),
				),
				array(
					'section' => 'jurisdiction_and_venue',
					'content' => __( 'Basis for subject matter and personal jurisdiction.', 'nvoos-content-graph-pro' ),
				),
				array(
					'section' => 'parties',
					'content' => $parties ? $parties : array( __( '[List all parties]', 'nvoos-content-graph-pro' ) ),
				),
				array(
					'section' => 'factual_allegations',
					'content' => __( '[Numbered paragraphs of factual allegations]', 'nvoos-content-graph-pro' ),
				),
				array(
					'section' => 'causes_of_action',
					'content' => $grounds ? $grounds : __( '[Enumerate each cause of action]', 'nvoos-content-graph-pro' ),
				),
				array(
					'section' => 'prayer_for_relief',
					'content' => __( 'WHEREFORE, Plaintiff respectfully requests judgment against Defendant.', 'nvoos-content-graph-pro' ),
				),
				$certificate,
			),
			'answer'                      => array(
				$caption,
				array(
					'section' => 'introduction',
					'content' => __( 'Defendant responds to the Complaint as follows.', 'nvoos-content-graph-pro' ),
				),
				array(
					'section' => 'admissions_and_denials',
					'content' => __( '[Respond to each numbered paragraph]', 'nvoos-content-graph-pro' ),
				),
				array(
					'section' => 'affirmative_defenses',
					'content' => $grounds ? $grounds : __( '[List affirmative defenses]', 'nvoos-content-graph-pro' ),
				),
				array(
					'section' => 'prayer_for_relief',
					'content' => __( 'WHEREFORE, Defendant requests dismissal with prejudice.', 'nvoos-content-graph-pro' ),
				),
				$certificate,
			),
			'motion_to_dismiss'           => array(
				$caption,
				array(
					'section' => 'introduction',
					'content' => __( 'Defendant moves to dismiss for failure to state a claim (FRCP 12(b)(6)).', 'nvoos-content-graph-pro' ),
				),
				array(
					'section' => 'statement_of_facts',
					'content' => __( '[Relevant procedural and factual background]', 'nvoos-content-graph-pro' ),
				),
				array(
					'section' => 'legal_standard',
					'content' => __( 'A motion to dismiss under Rule 12(b)(6) tests the sufficiency of the complaint.', 'nvoos-content-graph-pro' ),
				),
				array(
					'section' => 'argument',
					'content' => $grounds ? $grounds : __( '[Legal arguments for dismissal]', 'nvoos-content-graph-pro' ),
				),
				array(
					'section' => 'conclusion',
					'content' => __( 'For the foregoing reasons, the Court should grant this Motion to Dismiss.', 'nvoos-content-graph-pro' ),
				),
				$certificate,
			),
			'motion_for_summary_judgment' => array(
				$caption,
				array(
					'section' => 'introduction',
					'content' => __( 'Movant respectfully moves for summary judgment pursuant to FRCP 56.', 'nvoos-content-graph-pro' ),
				),
				array(
					'section' => 'statement_of_undisputed_facts',
					'content' => __( '[Numbered undisputed material facts with citations]', 'nvoos-content-graph-pro' ),
				),
				array(
					'section' => 'legal_standard',
					'content' => __( 'Summary judgment is appropriate where there is no genuine dispute of material fact.', 'nvoos-content-graph-pro' ),
				),
				array(
					'section' => 'argument',
					'content' => $grounds ? $grounds : __( '[Arguments why no genuine dispute exists]', 'nvoos-content-graph-pro' ),
				),
				array(
					'section' => 'conclusion',
					'content' => __( 'The Court should grant summary judgment in favor of Movant.', 'nvoos-content-graph-pro' ),
				),
				$certificate,
			),
			'motion_to_compel'            => array(
				$caption,
				array(
					'section' => 'introduction',
					'content' => __( 'Movant moves to compel discovery responses pursuant to FRCP 37.', 'nvoos-content-graph-pro' ),
				),
				array(
					'section' => 'discovery_at_issue',
					'content' => __( '[Describe discovery requests and deficient responses]', 'nvoos-content-graph-pro' ),
				),
				array(
					'section' => 'meet_and_confer',
					'content' => __( '[Describe good faith efforts to resolve the dispute]', 'nvoos-content-graph-pro' ),
				),
				array(
					'section' => 'argument',
					'content' => $grounds ? $grounds : __( '[Arguments why responses are deficient]', 'nvoos-content-graph-pro' ),
				),
				array(
					'section' => 'conclusion',
					'content' => __( 'The Court should compel complete responses and award reasonable expenses.', 'nvoos-content-graph-pro' ),
				),
				$certificate,
			),
			'motion_in_limine'            => array(
				$caption,
				array(
					'section' => 'introduction',
					'content' => __( 'Movant moves to exclude certain evidence at trial pursuant to FRE 402/403.', 'nvoos-content-graph-pro' ),
				),
				array(
					'section' => 'evidence_at_issue',
					'content' => __( '[Describe evidence sought to be excluded]', 'nvoos-content-graph-pro' ),
				),
				array(
					'section' => 'legal_standard',
					'content' => __( 'Evidence that is irrelevant or whose probative value is substantially outweighed by prejudice should be excluded.', 'nvoos-content-graph-pro' ),
				),
				array(
					'section' => 'argument',
					'content' => $grounds ? $grounds : __( '[Arguments for exclusion]', 'nvoos-content-graph-pro' ),
				),
				array(
					'section' => 'conclusion',
					'content' => __( 'The Court should exclude the identified evidence.', 'nvoos-content-graph-pro' ),
				),
				$certificate,
			),
		);

		return $structures[ $type ] ?? array();
	}
}
