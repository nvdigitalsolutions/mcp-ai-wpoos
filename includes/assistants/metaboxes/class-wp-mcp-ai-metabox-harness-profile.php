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
		$reasoning    = isset( $profile['reasoning'] ) && is_array( $profile['reasoning'] ) ? $profile['reasoning'] : array();
		$tools        = isset( $profile['tools'] ) && is_array( $profile['tools'] ) ? $profile['tools'] : array();
		$retrieval    = isset( $profile['retrieval'] ) && is_array( $profile['retrieval'] ) ? $profile['retrieval'] : array();
		$refine       = isset( $profile['refine'] ) && is_array( $profile['refine'] ) ? $profile['refine'] : array();
		$memory       = isset( $profile['memory'] ) && is_array( $profile['memory'] ) ? $profile['memory'] : array();
		$router_value = isset( $tools['router'] ) ? (string) $tools['router'] : 'fixed';

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

		<fieldset style="border: 1px solid #dcdcde; padding: 10px 15px; margin-top: 15px;">
			<legend style="font-weight: 600; padding: 0 5px;"><?php esc_html_e( 'Reasoning (Layer B)', 'mcp-ai-wpoos' ); ?></legend>
			<p class="description" style="margin-top: 0;">
				<?php esc_html_e( 'Best-of-N self-consistency. The model produces N independent reasoning traces and the controller votes on the answer. Higher N improves quality on complex tasks but multiplies token cost.', 'mcp-ai-wpoos' ); ?>
			</p>
			<p>
				<label>
					<input type="checkbox" name="wp_mcp_ai_harness_profile[reasoning][enabled]" value="1" <?php checked( ! empty( $reasoning['enabled'] ) ); ?> />
					<?php esc_html_e( 'Enable best-of-N reasoning', 'mcp-ai-wpoos' ); ?>
				</label>
			</p>
			<p>
				<label for="wp_mcp_ai_harness_profile_reasoning_n">
					<?php esc_html_e( 'Samples (N)', 'mcp-ai-wpoos' ); ?>
				</label>
				<input
					type="number"
					id="wp_mcp_ai_harness_profile_reasoning_n"
					name="wp_mcp_ai_harness_profile[reasoning][n_samples]"
					min="1"
					max="<?php echo esc_attr( (string) WP_MCP_AI_Harness_Profile::MAX_REASONING_SAMPLES ); ?>"
					step="1"
					value="<?php echo esc_attr( (string) ( isset( $reasoning['n_samples'] ) ? (int) $reasoning['n_samples'] : 1 ) ); ?>"
					class="small-text"
				/>
				<span class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: hard upper bound on samples */
							__( 'Hard upper bound: %d.', 'mcp-ai-wpoos' ),
							WP_MCP_AI_Harness_Profile::MAX_REASONING_SAMPLES
						)
					);
					?>
				</span>
			</p>
		</fieldset>

		<fieldset style="border: 1px solid #dcdcde; padding: 10px 15px; margin-top: 15px;">
			<legend style="font-weight: 600; padding: 0 5px;"><?php esc_html_e( 'Tool Router (Layer C)', 'mcp-ai-wpoos' ); ?></legend>
			<p class="description" style="margin-top: 0;">
				<?php esc_html_e( 'Choose how the agent picks tools at each step. Fixed = the LLM picks freely from all available tools (current behaviour). Scored = the harness tool router scores candidate tools per step using the wp_mcp_ai_harness_tool_score filter.', 'mcp-ai-wpoos' ); ?>
			</p>
			<p>
				<label>
					<input type="radio" name="wp_mcp_ai_harness_profile[tools][router]" value="fixed" <?php checked( 'fixed', $router_value ); ?> />
					<?php esc_html_e( 'Fixed (default)', 'mcp-ai-wpoos' ); ?>
				</label>
			</p>
			<p>
				<label>
					<input type="radio" name="wp_mcp_ai_harness_profile[tools][router]" value="scored" <?php checked( 'scored', $router_value ); ?> />
					<?php esc_html_e( 'Scored', 'mcp-ai-wpoos' ); ?>
				</label>
			</p>
		</fieldset>

		<fieldset style="border: 1px solid #dcdcde; padding: 10px 15px; margin-top: 15px;">
			<legend style="font-weight: 600; padding: 0 5px;"><?php esc_html_e( 'Retrieval (Layer D)', 'mcp-ai-wpoos' ); ?></legend>
			<p class="description" style="margin-top: 0;">
				<?php esc_html_e( 'Retrieval-augmented answering with provenance. When citations are required, answers without supporting evidence are flagged or refused by the citation verifier.', 'mcp-ai-wpoos' ); ?>
			</p>
			<p>
				<label>
					<input type="checkbox" name="wp_mcp_ai_harness_profile[retrieval][enabled]" value="1" <?php checked( ! empty( $retrieval['enabled'] ) ); ?> />
					<?php esc_html_e( 'Enable retrieval harness', 'mcp-ai-wpoos' ); ?>
				</label>
			</p>
			<p>
				<label for="wp_mcp_ai_harness_profile_retrieval_k">
					<?php esc_html_e( 'Top-k passages', 'mcp-ai-wpoos' ); ?>
				</label>
				<input
					type="number"
					id="wp_mcp_ai_harness_profile_retrieval_k"
					name="wp_mcp_ai_harness_profile[retrieval][k]"
					min="1"
					max="50"
					step="1"
					value="<?php echo esc_attr( (string) ( isset( $retrieval['k'] ) ? (int) $retrieval['k'] : 5 ) ); ?>"
					class="small-text"
				/>
				<span class="description"><?php esc_html_e( 'Range: 1–50.', 'mcp-ai-wpoos' ); ?></span>
			</p>
			<p>
				<label>
					<input type="checkbox" name="wp_mcp_ai_harness_profile[retrieval][require_citations]" value="1" <?php checked( ! empty( $retrieval['require_citations'] ) ); ?> />
					<?php esc_html_e( 'Require citations (refuse to answer without evidence)', 'mcp-ai-wpoos' ); ?>
				</label>
			</p>
		</fieldset>

		<fieldset style="border: 1px solid #dcdcde; padding: 10px 15px; margin-top: 15px;">
			<legend style="font-weight: 600; padding: 0 5px;"><?php esc_html_e( 'Self-Refine (Layer E)', 'mcp-ai-wpoos' ); ?></legend>
			<p class="description" style="margin-top: 0;">
				<?php esc_html_e( 'Synchronous, bounded reflection loop. The model critiques its own draft and rewrites it up to N times. Costs scale linearly; capped by the per-request cost ceiling above.', 'mcp-ai-wpoos' ); ?>
			</p>
			<p>
				<label>
					<input type="checkbox" name="wp_mcp_ai_harness_profile[refine][enabled]" value="1" <?php checked( ! empty( $refine['enabled'] ) ); ?> />
					<?php esc_html_e( 'Enable self-refine loop', 'mcp-ai-wpoos' ); ?>
				</label>
			</p>
			<p>
				<label for="wp_mcp_ai_harness_profile_refine_iters">
					<?php esc_html_e( 'Max iterations', 'mcp-ai-wpoos' ); ?>
				</label>
				<input
					type="number"
					id="wp_mcp_ai_harness_profile_refine_iters"
					name="wp_mcp_ai_harness_profile[refine][max_iters]"
					min="1"
					max="<?php echo esc_attr( (string) WP_MCP_AI_Harness_Profile::MAX_REFINE_ITERATIONS ); ?>"
					step="1"
					value="<?php echo esc_attr( (string) ( isset( $refine['max_iters'] ) ? (int) $refine['max_iters'] : 1 ) ); ?>"
					class="small-text"
				/>
				<span class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: hard upper bound on iterations */
							__( 'Hard upper bound: %d.', 'mcp-ai-wpoos' ),
							WP_MCP_AI_Harness_Profile::MAX_REFINE_ITERATIONS
						)
					);
					?>
				</span>
			</p>
		</fieldset>

		<fieldset style="border: 1px solid #dcdcde; padding: 10px 15px; margin-top: 15px;">
			<legend style="font-weight: 600; padding: 0 5px;"><?php esc_html_e( 'Memory Scoping (Layer F)', 'mcp-ai-wpoos' ); ?></legend>
			<p class="description" style="margin-top: 0;">
				<?php esc_html_e( 'Scope agent memory recall to a task class and run all writes through the PII / secret safety filter before they reach long-term memory.', 'mcp-ai-wpoos' ); ?>
			</p>
			<p>
				<label>
					<input type="checkbox" name="wp_mcp_ai_harness_profile[memory][scoped]" value="1" <?php checked( ! empty( $memory['scoped'] ) ); ?> />
					<?php esc_html_e( 'Scope memory recall by task class', 'mcp-ai-wpoos' ); ?>
				</label>
			</p>
			<p>
				<label for="wp_mcp_ai_harness_profile_memory_task_class">
					<?php esc_html_e( 'Task class', 'mcp-ai-wpoos' ); ?>
				</label>
				<input
					type="text"
					id="wp_mcp_ai_harness_profile_memory_task_class"
					name="wp_mcp_ai_harness_profile[memory][task_class]"
					value="<?php echo esc_attr( isset( $memory['task_class'] ) ? (string) $memory['task_class'] : 'general' ); ?>"
					class="regular-text"
				/>
				<span class="description"><?php esc_html_e( 'Lowercase slug (e.g. support, sales, research). Defaults to "general".', 'mcp-ai-wpoos' ); ?></span>
			</p>
			<p>
				<label>
					<input type="checkbox" name="wp_mcp_ai_harness_profile[memory][pii_filter]" value="1" <?php checked( ! isset( $memory['pii_filter'] ) || ! empty( $memory['pii_filter'] ) ); ?> />
					<?php esc_html_e( 'Run PII / secret safety filter on memory writes (recommended)', 'mcp-ai-wpoos' ); ?>
				</label>
			</p>
		</fieldset>

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
		// in $_POST, so default it to false. The same applies to nested
		// checkboxes inside the per-layer fieldsets.
		$payload = array(
			'enabled'          => ! empty( $raw['enabled'] ),
			'cues'             => isset( $raw['cues'] ) && is_array( $raw['cues'] ) ? $raw['cues'] : array(),
			'cost_ceiling_usd' => isset( $raw['cost_ceiling_usd'] ) ? (float) $raw['cost_ceiling_usd'] : WP_MCP_AI_Harness_Profile::DEFAULT_COST_CEILING_USD,
		);

		// Reasoning (Layer B).
		$reasoning_raw        = isset( $raw['reasoning'] ) && is_array( $raw['reasoning'] ) ? $raw['reasoning'] : array();
		$payload['reasoning'] = array(
			'enabled'   => ! empty( $reasoning_raw['enabled'] ),
			'n_samples' => isset( $reasoning_raw['n_samples'] ) ? (int) $reasoning_raw['n_samples'] : 1,
			'max_iters' => isset( $reasoning_raw['max_iters'] ) ? (int) $reasoning_raw['max_iters'] : 1,
		);

		// Tool router (Layer C).
		$tools_raw        = isset( $raw['tools'] ) && is_array( $raw['tools'] ) ? $raw['tools'] : array();
		$payload['tools'] = array(
			'router' => isset( $tools_raw['router'] ) ? (string) $tools_raw['router'] : 'fixed',
		);

		// Retrieval (Layer D).
		$retrieval_raw        = isset( $raw['retrieval'] ) && is_array( $raw['retrieval'] ) ? $raw['retrieval'] : array();
		$payload['retrieval'] = array(
			'enabled'           => ! empty( $retrieval_raw['enabled'] ),
			'k'                 => isset( $retrieval_raw['k'] ) ? (int) $retrieval_raw['k'] : 5,
			'require_citations' => ! empty( $retrieval_raw['require_citations'] ),
		);

		// Self-Refine (Layer E).
		$refine_raw        = isset( $raw['refine'] ) && is_array( $raw['refine'] ) ? $raw['refine'] : array();
		$payload['refine'] = array(
			'enabled'   => ! empty( $refine_raw['enabled'] ),
			'max_iters' => isset( $refine_raw['max_iters'] ) ? (int) $refine_raw['max_iters'] : 1,
		);

		// Memory scoping (Layer F). The PII filter checkbox defaults to ON
		// when the memory section is missing entirely (e.g. older form),
		// matching the profile's secure-by-default posture.
		$memory_raw        = isset( $raw['memory'] ) && is_array( $raw['memory'] ) ? $raw['memory'] : null;
		$payload['memory'] = array(
			'scoped'     => null === $memory_raw ? false : ! empty( $memory_raw['scoped'] ),
			'task_class' => null === $memory_raw || ! isset( $memory_raw['task_class'] ) ? 'general' : (string) $memory_raw['task_class'],
			'pii_filter' => null === $memory_raw ? true : ! empty( $memory_raw['pii_filter'] ),
		);

		// Preserve fields that aren't (yet) surfaced in the UI so saving
		// from this metabox doesn't silently reset them.
		$existing = WP_MCP_AI_Harness_Profile::get( (int) $post_id );
		foreach ( array( 'evals_enabled', 'verifiers' ) as $passthrough ) {
			if ( isset( $existing[ $passthrough ] ) && ! isset( $payload[ $passthrough ] ) ) {
				$payload[ $passthrough ] = $existing[ $passthrough ];
			}
		}

		WP_MCP_AI_Harness_Profile::save( (int) $post_id, $payload );
	}
}
