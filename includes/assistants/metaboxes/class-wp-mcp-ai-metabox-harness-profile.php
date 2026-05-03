<?php
/**
 * Harness Profile Metabox for Assistants.
 *
 * Layer A authoring surface for the LLM harness subsystem. Lets admins
 * opt an assistant into the harness, pick which prompt cues prepend to
 * its system prompt, and set a per-request USD cost ceiling — without
 * needing to write PHP or post-meta API calls.
 *
 * Higher-cost layers (best-of-N reasoning, self-refine, retrieval gates)
 * are not surfaced in this minimal metabox; they remain configurable via
 * the `harness_profile` post meta and the documented filters. Surfacing
 * them in the UI is a follow-up.
 *
 * @package WP_MCP_AI
 * @since   1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Harness profile metabox.
 *
 * @since 1.4.0
 */
class WP_MCP_AI_Metabox_Harness_Profile extends WP_MCP_AI_Metabox_Base {

	/**
	 * Nonce action used to authenticate the form submission.
	 */
	const NONCE_ACTION = 'wp_mcp_ai_harness_profile_meta';

	/**
	 * Nonce field name.
	 */
	const NONCE_FIELD = 'wp_mcp_ai_harness_profile_nonce';

	/**
	 * Reference to the Assistant CPT class.
	 *
	 * @var WP_MCP_AI_Assistant_CPT
	 */
	protected $cpt;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_Assistant_CPT $cpt Assistant CPT instance.
	 */
	public function __construct( $cpt ) {
		$this->cpt = $cpt;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_id() {
		return 'wp_mcp_ai_harness_profile';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_title() {
		return __( 'LLM Harness', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_documentation_url() {
		return 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/llm-harness.md';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function can_view() {
		global $post;
		return current_user_can( 'edit_post', $post ? (int) $post->ID : 0 );
	}

	/**
	 * Render the metabox.
	 *
	 * @param WP_Post $post Post object.
	 */
	public function render( $post ) {
		if ( ! $this->can_view() ) {
			$this->render_permission_denied();
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Harness_Profile' ) || ! class_exists( 'WP_MCP_AI_Prompt_Cue_Library' ) ) {
			echo '<p>' . esc_html__( 'Harness subsystem not loaded.', 'mcp-ai-wpoos' ) . '</p>';
			return;
		}

		$assistant_id = (int) $post->ID;
		$profile      = WP_MCP_AI_Harness_Profile::get( $assistant_id );
		$selected     = isset( $profile['cues'] ) && is_array( $profile['cues'] ) ? $profile['cues'] : array();
		$cues         = WP_MCP_AI_Prompt_Cue_Library::get_instance()->all();
		$cost_ceiling = isset( $profile['cost_ceiling_usd'] ) ? (float) $profile['cost_ceiling_usd'] : WP_MCP_AI_Harness_Profile::DEFAULT_COST_CEILING_USD;

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<p class="description" style="margin-top: 0;">
			<?php esc_html_e( 'Opt this assistant into the LLM harness. When enabled, the selected prompt cues are prepended to the assistant\'s system prompt at every chat surface (server-side chat, embedded WebLLM client, and shortcode bootstrap). Cues augment — they never replace — the existing system prompt.', 'mcp-ai-wpoos' ); ?>
		</p>

		<p>
			<label>
				<input type="checkbox" name="wp_mcp_ai_harness_profile[enabled]" value="1" <?php checked( ! empty( $profile['enabled'] ) ); ?> />
				<strong><?php esc_html_e( 'Enable LLM Harness for this assistant', 'mcp-ai-wpoos' ); ?></strong>
			</label>
		</p>

		<fieldset style="border: 1px solid #dcdcde; padding: 10px 15px; margin-top: 10px;">
			<legend style="font-weight: 600; padding: 0 5px;"><?php esc_html_e( 'Prompt Cues', 'mcp-ai-wpoos' ); ?></legend>
			<p class="description" style="margin-top: 0;">
				<?php esc_html_e( 'Cues are prepended to the system prompt in the order checked. Each cue is a short, well-known reasoning pattern from the literature.', 'mcp-ai-wpoos' ); ?>
			</p>
			<?php if ( empty( $cues ) ) : ?>
				<p><em><?php esc_html_e( 'No cues are registered.', 'mcp-ai-wpoos' ); ?></em></p>
			<?php else : ?>
				<ul style="list-style: none; padding: 0; margin: 0;">
					<?php foreach ( $cues as $slug => $cue ) : ?>
						<li style="margin: 6px 0;">
							<label>
								<input
									type="checkbox"
									name="wp_mcp_ai_harness_profile[cues][]"
									value="<?php echo esc_attr( $slug ); ?>"
									<?php checked( in_array( $slug, $selected, true ) ); ?>
								/>
								<strong><?php echo esc_html( isset( $cue['label'] ) ? $cue['label'] : $slug ); ?></strong>
								<?php if ( ! empty( $cue['description'] ) ) : ?>
									<span style="color: #646970;"> — <?php echo esc_html( $cue['description'] ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $cue['citation'] ) ) : ?>
									<br /><small style="color: #8c8f94; margin-left: 22px;"><?php echo esc_html( $cue['citation'] ); ?></small>
								<?php endif; ?>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</fieldset>

		<p style="margin-top: 15px;">
			<label for="wp_mcp_ai_harness_profile_cost_ceiling">
				<?php esc_html_e( 'Per-request cost ceiling (USD)', 'mcp-ai-wpoos' ); ?>
			</label><br />
			<input
				type="number"
				id="wp_mcp_ai_harness_profile_cost_ceiling"
				name="wp_mcp_ai_harness_profile[cost_ceiling_usd]"
				step="0.01"
				min="0"
				max="1000"
				value="<?php echo esc_attr( number_format( $cost_ceiling, 2, '.', '' ) ); ?>"
				class="small-text"
			/>
			<span class="description">
				<?php esc_html_e( 'Hard cap applied to harness operations (e.g. self-refine iterations). 0 disables the cap.', 'mcp-ai-wpoos' ); ?>
			</span>
		</p>

		<?php
		$this->render_documentation_link();
	}

	/**
	 * Save the metabox data.
	 *
	 * Behaviour-preserving: when no nonce is present (e.g. quick-edit, REST
	 * autosave, or the metabox isn't shown to the user), the existing
	 * profile is left untouched.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save( $post_id, $post ) {
		// Bail when the nonce isn't part of the request — this keeps autosave
		// / quick-edit / REST writes from clearing the profile.
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Sanitize the form payload via the profile sanitizer so caps and
		// whitelists are enforced server-side regardless of the form input.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = isset( $_POST['wp_mcp_ai_harness_profile'] ) && is_array( $_POST['wp_mcp_ai_harness_profile'] )
			? wp_unslash( $_POST['wp_mcp_ai_harness_profile'] )
			: array();
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// Coerce checkbox semantics: an unchecked "enabled" box won't appear
		// in $_POST, so default it to false.
		$payload = array(
			'enabled'          => ! empty( $raw['enabled'] ),
			'cues'             => isset( $raw['cues'] ) && is_array( $raw['cues'] ) ? $raw['cues'] : array(),
			'cost_ceiling_usd' => isset( $raw['cost_ceiling_usd'] ) ? (float) $raw['cost_ceiling_usd'] : WP_MCP_AI_Harness_Profile::DEFAULT_COST_CEILING_USD,
		);

		// Preserve any non-UI fields the profile already carries (reasoning,
		// retrieval, refine, memory) so saving from this metabox doesn't
		// silently reset them.
		$existing = WP_MCP_AI_Harness_Profile::get( (int) $post_id );
		foreach ( array( 'reasoning', 'tools', 'retrieval', 'refine', 'memory', 'evals_enabled', 'verifiers' ) as $passthrough ) {
			if ( isset( $existing[ $passthrough ] ) && ! isset( $payload[ $passthrough ] ) ) {
				$payload[ $passthrough ] = $existing[ $passthrough ];
			}
		}

		WP_MCP_AI_Harness_Profile::save( (int) $post_id, $payload );
	}
}
