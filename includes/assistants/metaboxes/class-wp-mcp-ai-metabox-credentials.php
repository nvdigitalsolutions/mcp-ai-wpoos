<?php
/**
 * Credentials Metabox for Assistants.
 *
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

/**
 * Handles the Credentials metabox for assistant posts.
 *
 * Manages credential issuance, revocation, and deletion for assistants.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Metabox_Credentials extends WP_MCP_AI_Metabox_Base {

	/**
	 * Track if credential action script has been printed.
	 *
	 * @var bool
	 */
	protected static $credential_action_script_printed = false;

	/**
	 * Get the metabox ID.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_credentials';
	}

	/**
	 * Get the metabox title.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_title() {
		return __( 'Credentials', 'wp-mcp-ai' );
	}

	/**
	 * Get the metabox priority.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_priority() {
		return 'high';
	}

	/**
	 * Render the metabox content.
	 *
	 * @since 1.0.0
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	public function render( $post ) {
		if ( ! $this->can_view() ) {
			$this->render_permission_denied( __( 'You do not have permission to manage credentials.', 'wp-mcp-ai' ) );
			return;
		}

		$credentials = WP_MCP_AI_Credentials::get_credentials( $post->ID );

		echo '<p>' . esc_html__( 'Issue tokens for remote integrations. Store the generated token securely; it will not be shown again.', 'wp-mcp-ai' ) . '</p>';

		if ( empty( $credentials ) ) {
			echo '<p>' . esc_html__( 'No credentials have been issued for this assistant.', 'wp-mcp-ai' ) . '</p>';
		} else {
			echo '<table class="widefat striped">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Credential ID', 'wp-mcp-ai' ) . '</th>';
			echo '<th>' . esc_html__( 'Created', 'wp-mcp-ai' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'wp-mcp-ai' ) . '</th>';
			echo '<th>' . esc_html__( 'Actions', 'wp-mcp-ai' ) . '</th>';
			echo '</tr></thead>';
			echo '<tbody>';

			foreach ( $credentials as $credential ) {
				$created_at   = ! empty( $credential['created_at'] ) ? get_date_from_gmt( $credential['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : __( 'Unknown', 'wp-mcp-ai' );
				$status       = __( 'Active', 'wp-mcp-ai' );
				$action_links = array();

				if ( ! empty( $credential['revoked_at'] ) ) {
					$status = sprintf(
					/* translators: %s: revocation timestamp */
						__( 'Revoked %s', 'wp-mcp-ai' ),
						get_date_from_gmt( $credential['revoked_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
					);
				} else {
					$action_links[] = $this->build_credential_action_button(
						$post->ID,
						$credential['id'],
						'wp_mcp_ai_revoke_credential',
						'wp_mcp_ai_revoke_credential_' . $post->ID . '_' . $credential['id'],
						$this->get_credential_nonce_field_name( 'wp_mcp_ai_revoke_credential_nonce', $credential['id'] ),
						__( 'Revoke', 'wp-mcp-ai' ),
						__( 'Revoke this credential? This action cannot be undone.', 'wp-mcp-ai' )
					);
				}

				$action_links[] = $this->build_credential_action_button(
					$post->ID,
					$credential['id'],
					'wp_mcp_ai_delete_credential',
					'wp_mcp_ai_delete_credential_' . $post->ID . '_' . $credential['id'],
					$this->get_credential_nonce_field_name( 'wp_mcp_ai_delete_credential_nonce', $credential['id'] ),
					__( 'Delete', 'wp-mcp-ai' ),
					__( 'Delete this credential? This action cannot be undone.', 'wp-mcp-ai' ),
					'button button-secondary delete'
				);

				$actions = empty( $action_links ) ? '&#8212;' : implode( ' ', $action_links );

				echo '<tr>';
				echo '<td><code>' . esc_html( $credential['id'] ) . '</code></td>';
				echo '<td>' . esc_html( $created_at ) . '</td>';
				echo '<td>' . esc_html( $status ) . '</td>';
				echo '<td>' . wp_kses_post( $actions ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody>';
			echo '</table>';
		}

		$issue_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'wp_mcp_ai_issue_credential',
					'post_id' => $post->ID,
				),
				admin_url( 'admin-post.php' )
			),
			'wp_mcp_ai_issue_credential_' . $post->ID,
			'wp_mcp_ai_issue_credential_nonce'
		);

		printf(
			'<p><a class="button button-secondary" href="%1$s">%2$s</a></p>',
			esc_url( $issue_url ),
			esc_html__( 'Generate Credential', 'wp-mcp-ai' )
		);

		$this->print_credential_action_script();
	}

	/**
	 * Build the markup for a credential action button.
	 *
	 * @since 1.0.0
	 * @param int    $post_id        Assistant post ID.
	 * @param string $credential_id  Credential identifier.
	 * @param string $action         Admin-post action hook name.
	 * @param string $nonce_action   Action name for nonce verification.
	 * @param string $nonce_name     Nonce field name.
	 * @param string $button_label   Button label.
	 * @param string $confirm_prompt Confirmation prompt shown before submit.
	 * @param string $button_class   CSS classes to apply to the button element.
	 *
	 * @return string
	 */
	protected function build_credential_action_button( $post_id, $credential_id, $action, $nonce_action, $nonce_name, $button_label, $confirm_prompt, $button_class = 'button button-secondary' ) {
		$classes    = trim( $button_class . ' wp-mcp-ai-credential-action' );
		$attributes = array(
			'type'               => 'button',
			'class'              => $classes,
			'data-action'        => $action,
			'data-post-id'       => $post_id,
			'data-credential-id' => $credential_id,
			'data-nonce-name'    => $nonce_name,
			'data-nonce-value'   => wp_create_nonce( $nonce_action ),
			'data-endpoint'      => admin_url( 'admin-post.php' ),
		);

		if ( $confirm_prompt ) {
			$attributes['data-confirm'] = $confirm_prompt;
		}

		$attribute_string = '';
		foreach ( $attributes as $name => $value ) {
			if ( '' === $value || null === $value ) {
				continue;
			}

			$escaped_value     = ( 'data-endpoint' === $name ) ? esc_url( $value ) : esc_attr( $value );
			$attribute_string .= sprintf( ' %s="%s"', esc_attr( $name ), $escaped_value );
		}

		return sprintf( '<button%1$s>%2$s</button>', $attribute_string, esc_html( $button_label ) );
	}

	/**
	 * Print the JavaScript required to submit credential action buttons as POST requests.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	protected function print_credential_action_script() {
		if ( self::$credential_action_script_printed ) {
			return;
		}

		self::$credential_action_script_printed = true;
		?>
	<script type="text/javascript">
	( function() {
		function submitCredentialAction( button ) {
			if ( ! button ) {
				return;
			}

			var confirmMessage = button.getAttribute( 'data-confirm' );
			if ( confirmMessage && ! window.confirm( confirmMessage ) ) {
				return;
			}

			var endpoint = button.getAttribute( 'data-endpoint' );
			if ( ! endpoint ) {
				return;
			}

			var form = document.createElement( 'form' );
			form.method = 'post';
			form.action = endpoint;
			form.style.display = 'none';

			var fields = {
				action: button.getAttribute( 'data-action' ),
				post_id: button.getAttribute( 'data-post-id' ),
				credential_id: button.getAttribute( 'data-credential-id' )
			};

			var nonceName = button.getAttribute( 'data-nonce-name' );
			var nonceValue = button.getAttribute( 'data-nonce-value' );

			if ( nonceName && nonceValue ) {
				fields[ nonceName ] = nonceValue;
			}

			for ( var key in fields ) {
				if ( Object.prototype.hasOwnProperty.call( fields, key ) && fields[ key ] ) {
					var input = document.createElement( 'input' );
					input.type = 'hidden';
					input.name = key;
					input.value = fields[ key ];
					form.appendChild( input );
				}
			}

			document.body.appendChild( form );
			form.submit();
		}

		document.addEventListener( 'click', function( event ) {
			var target = event.target;
			if ( target && target.classList && target.classList.contains( 'wp-mcp-ai-credential-action' ) ) {
				event.preventDefault();
				submitCredentialAction( target );
			}
		} );
	} )();
	</script>
		<?php
	}

	/**
	 * Generate a nonce field name unique to a credential.
	 *
	 * @since 1.0.0
	 * @param string $base_name     Base nonce field name.
	 * @param string $credential_id Credential identifier.
	 * @return string
	 */
	protected function get_credential_nonce_field_name( $base_name, $credential_id ) {
		$suffix = sanitize_key( $credential_id );

		if ( '' === $suffix ) {
			return $base_name;
		}

		return $base_name . '_' . $suffix;
	}
}
