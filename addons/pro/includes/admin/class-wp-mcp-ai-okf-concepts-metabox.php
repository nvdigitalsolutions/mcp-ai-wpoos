<?php
/**
 * OKF Concepts Metabox for Assistants (Pro).
 *
 * Lets administrators grant specific OKF concepts (`bundle:concept_id`) to
 * an assistant so the OKF → Skill bridge may load them via `load_skill`.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.1.62
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OKF concept grants metabox on the assistant editor.
 *
 * @since 1.1.62
 */
class WP_MCP_AI_OKF_Concepts_Metabox {

	const NONCE_ACTION = 'wp_mcp_ai_okf_concepts_meta';

	/**
	 * Register hooks.
	 *
	 * @since 1.1.62
	 * @return void
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_metabox' ) );
		add_action( 'save_post_mcp_ai_assistant', array( __CLASS__, 'save' ), 10, 2 );
	}

	/**
	 * Register the metabox on the assistant CPT.
	 *
	 * @since 1.1.62
	 * @return void
	 */
	public static function register_metabox() {
		add_meta_box(
			'wp-mcp-ai-okf-concepts',
			__( 'OKF Knowledge Concepts', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render' ),
			'mcp_ai_assistant',
			'normal',
			'default'
		);
	}

	/**
	 * Render the metabox.
	 *
	 * @param WP_Post $post The assistant post.
	 * @return void
	 */
	public static function render( $post ) {
		if ( ! class_exists( 'WP_MCP_AI_OKF_Bundle_Manager' ) ) {
			echo '<p class="description">' . esc_html__( 'The OKF engine is not available.', 'mcp-ai-wpoos-pro' ) . '</p>';
			return;
		}

		wp_nonce_field( self::NONCE_ACTION, 'wp_mcp_ai_okf_concepts_nonce' );

		$grants = get_post_meta( $post->ID, WP_MCP_AI_OKF_Skill_Bridge::META_GRANTS, true );
		if ( ! is_array( $grants ) ) {
			$grants = array();
		}

		$manager = new WP_MCP_AI_OKF_Bundle_Manager();
		$bundles = $manager->list_bundles();
		if ( is_wp_error( $bundles ) ) {
			echo '<p class="description">' . esc_html( $bundles->get_error_message() ) . '</p>';
			return;
		}

		if ( empty( $bundles ) ) {
			echo '<p class="description">' . esc_html__( 'No OKF bundles exist yet. Create one in the OKF Bundle Manager first.', 'mcp-ai-wpoos-pro' ) . '</p>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'Grant specific OKF concepts so the assistant can load them with load_skill (bundle:concept_id). Draft concepts are never loadable; grants are enforced per assistant.', 'mcp-ai-wpoos-pro' ) . '</p>';

		foreach ( $bundles as $bundle ) {
			$bundle_name = $bundle['name'];
			$reader      = new WP_MCP_AI_OKF_Reader( $bundle['path'] );
			$concepts    = $reader->search( array() );

			echo '<details style="margin:8px 0;"><summary><strong>' . esc_html( $bundle_name ) . '</strong> (' . esc_html( (string) count( $concepts ) ) . ')</summary><ul style="margin-left:16px;">';

			foreach ( $concepts as $concept ) {
				$reference = $bundle_name . ':' . $concept['concept_id'];
				$checked   = in_array( $reference, $grants, true );
				$label     = $concept['title'] ? $concept['title'] : $concept['concept_id'];

				echo '<li><label>';
				echo '<input type="checkbox" name="wp_mcp_ai_okf_grants[]" value="' . esc_attr( $reference ) . '"' . checked( $checked, true, false ) . ' /> ';
				echo esc_html( $label );
				echo ' <span class="okf-badge">' . esc_html( $concept['trust_tier'] ) . '</span>';
				if ( ! empty( $concept['stale'] ) ) {
					echo ' <span class="okf-badge stale">' . esc_html__( 'stale', 'mcp-ai-wpoos-pro' ) . '</span>';
				}
				echo '</label></li>';
			}

			echo '</ul></details>';
		}
	}

	/**
	 * Save the metabox grants.
	 *
	 * @param int     $post_id Assistant post ID.
	 * @param WP_Post $post    Assistant post object.
	 * @return void
	 */
	public static function save( $post_id, $post ) {
		unset( $post ); // Unused — the post ID suffices for meta updates.

		if ( ! isset( $_POST['wp_mcp_ai_okf_concepts_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_okf_concepts_nonce'] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$grants = array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each grant is sanitized and validated against the live bundle layout below.
		$submitted = isset( $_POST['wp_mcp_ai_okf_grants'] ) && is_array( $_POST['wp_mcp_ai_okf_grants'] ) ? wp_unslash( $_POST['wp_mcp_ai_okf_grants'] ) : array();

		$manager = new WP_MCP_AI_OKF_Bundle_Manager();
		$bundles = $manager->list_bundles();

		if ( is_array( $bundles ) ) {
			$bundle_names = array_map(
				static function ( $bundle ) {
					return $bundle['name'];
				},
				$bundles
			);

			foreach ( $submitted as $raw ) {
				$reference = sanitize_text_field( (string) $raw );
				$colon     = strpos( $reference, ':' );

				if ( false === $colon || 0 === $colon || strlen( $reference ) - 1 === $colon ) {
					continue; // Not bundle:concept shaped.
				}

				$bundle = substr( $reference, 0, $colon );

				// Only accept grants for bundles that exist on disk.
				if ( ! in_array( $bundle, $bundle_names, true ) ) {
					continue;
				}

				$grants[] = $reference;
			}
		}

		$grants = array_values( array_unique( $grants ) );

		if ( empty( $grants ) ) {
			delete_post_meta( $post_id, WP_MCP_AI_OKF_Skill_Bridge::META_GRANTS );
		} else {
			update_post_meta( $post_id, WP_MCP_AI_OKF_Skill_Bridge::META_GRANTS, $grants );
		}
	}
}
