<?php
/**
 * Elementor widget for displaying the acting user's capability snapshot.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
	return;
}

/**
 * Elementor widget definition for the user capability snapshot.
 */
class WP_MCP_AI_Elementor_Dashboard_User_Capability_Widget extends \Elementor\Widget_Base {
	use WP_MCP_AI_Elementor_Text_Formatting;

	/**
	 * Widget slug.
	 */
	public function get_name() {
		return 'wp_mcp_ai_user_capabilities';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 */
	public function get_title() {
		return __( 'WP oOS User Capability Snapshot', 'wp-mcp-ai' );
	}

	/**
	 * Widget icon for Elementor panel.
	 */
	public function get_icon() {
		return 'eicon-user-circle-o';
	}

	/**
	 * Widget categories.
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Keywords to help search for the widget.
	 */
	public function get_keywords() {
		return array( 'mcp', 'user', 'roles', 'capabilities', 'dashboard' );
	}

	/**
	 * Register controls for the widget settings.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Snapshot Content', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => __( 'Title', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Current operator snapshot', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'description',
			array(
				'label'   => __( 'Description', 'wp-mcp-ai' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'rows'    => 3,
				'default' => __( 'Review the signed-in user’s roles, elevated capabilities, JetEngine access, and multisite membership.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'show_capability_grid',
			array(
				'label'        => __( 'Show common capabilities', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();

		$this->register_theme_style_controls(
			array(
				'section_id' => 'section_style_user_capabilities',
				'selectors'  => array(
					'container' => '{{WRAPPER}} .wp-mcp-ai-user-capabilities',
					'heading'   => array(
						'{{WRAPPER}} .wp-mcp-ai-user-capabilities__title',
						'{{WRAPPER}} .wp-mcp-ai-user-capabilities__section-title',
					),
					'text'      => array(
						'{{WRAPPER}} .wp-mcp-ai-user-capabilities__description',
						'{{WRAPPER}} .wp-mcp-ai-user-capabilities__notice',
						'{{WRAPPER}} .wp-mcp-ai-user-capabilities__section-body',
						'{{WRAPPER}} .wp-mcp-ai-user-capabilities__section-note',
						'{{WRAPPER}} .wp-mcp-ai-user-capabilities__capability-status',
						'{{WRAPPER}} .wp-mcp-ai-user-capabilities__membership-name',
						'{{WRAPPER}} .wp-mcp-ai-user-capabilities__membership-url',
					),
					'meta'      => array(
						'{{WRAPPER}} .wp-mcp-ai-user-capabilities__summary-label',
						'{{WRAPPER}} .wp-mcp-ai-user-capabilities__summary-value',
						'{{WRAPPER}} .wp-mcp-ai-user-capabilities__capability-name',
					),
					'link'      => '{{WRAPPER}} .wp-mcp-ai-user-capabilities a',
				),
			)
		);
	}

	/**
	 * Render the widget on the front-end.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$title             = isset( $settings['title'] ) ? $settings['title'] : '';
		$description       = isset( $settings['description'] ) ? $settings['description'] : '';
		$show_capabilities = ! empty( $settings['show_capability_grid'] ) && 'yes' === $settings['show_capability_grid'];

		$user_id = get_current_user_id();

		echo '<div class="wp-mcp-ai-user-capabilities">';

		if ( ! empty( $title ) ) {
			echo '<h3 class="wp-mcp-ai-user-capabilities__title">' . esc_html( $title ) . '</h3>';
		}

		if ( ! empty( $description ) ) {
			$description_output = $this->format_text_block( $description );

			if ( '' !== $description_output ) {
				echo '<div class="wp-mcp-ai-user-capabilities__description">' . $description_output . '</div>';
			}
		}

		if ( ! $user_id ) {
			echo '<p class="wp-mcp-ai-user-capabilities__notice">' . esc_html__( 'Log in to view capability details.', 'wp-mcp-ai' ) . '</p>';
			echo '</div>';
			return;
		}

		$user_info = $this->fetch_user_info( $user_id );

		if ( is_wp_error( $user_info ) ) {
			echo '<p class="wp-mcp-ai-user-capabilities__notice">' . esc_html( $user_info->get_error_message() ) . '</p>';
			echo '</div>';
			return;
		}

		$roles = array();
		if ( isset( $user_info['roles'] ) && is_array( $user_info['roles'] ) ) {
			foreach ( $user_info['roles'] as $role ) {
				$roles[] = sanitize_text_field( $role );
			}
		}

		$summary_items = array();

		if ( ! empty( $user_info['display_name'] ) ) {
			$summary_items[] = array(
				'label' => __( 'Name', 'wp-mcp-ai' ),
				'value' => $user_info['display_name'],
			);
		}

		if ( ! empty( $user_info['user_email'] ) ) {
			$summary_items[] = array(
				'label' => __( 'Email', 'wp-mcp-ai' ),
				'value' => $user_info['user_email'],
			);
		}

		if ( ! empty( $roles ) ) {
			$summary_items[] = array(
				'label' => __( 'Roles', 'wp-mcp-ai' ),
				'value' => implode( ', ', $roles ),
			);
		}

		if ( ! empty( $user_info['user_login'] ) ) {
			$summary_items[] = array(
				'label' => __( 'Username', 'wp-mcp-ai' ),
				'value' => $user_info['user_login'],
			);
		}

		if ( ! empty( $user_info['registered'] ) ) {
			$summary_items[] = array(
				'label' => __( 'Registered', 'wp-mcp-ai' ),
				'value' => $this->format_timestamp( $user_info['registered'] ),
			);
		}

		if ( ! empty( $summary_items ) ) {
			echo '<div class="wp-mcp-ai-user-capabilities__summary">';

			foreach ( $summary_items as $item ) {
				$label = isset( $item['label'] ) ? $item['label'] : '';
				$value = isset( $item['value'] ) ? $item['value'] : '';

				if ( '' === $label || '' === $value ) {
					continue;
				}

				echo '<div class="wp-mcp-ai-user-capabilities__summary-item">';
				echo '<span class="wp-mcp-ai-user-capabilities__summary-label">' . esc_html( $label ) . ':</span> ';
				echo '<span class="wp-mcp-ai-user-capabilities__summary-value">' . esc_html( $value ) . '</span>';
				echo '</div>';
			}

			echo '</div>';
		}

		$group_email_settings = $this->get_group_email_configuration();

		echo '<div class="wp-mcp-ai-user-capabilities__section">';
		echo '<h4 class="wp-mcp-ai-user-capabilities__section-title">' . esc_html__( 'Send Group Email requirements', 'wp-mcp-ai' ) . '</h4>';

		if ( '' === $group_email_settings['capability'] ) {
			echo '<p class="wp-mcp-ai-user-capabilities__section-body">' . esc_html__( 'Any logged-in user can run the Send Group Email tool when attachments pass validation.', 'wp-mcp-ai' ) . '</p>';
		} elseif ( '' !== $group_email_settings['label'] ) {
			printf(
				'<p class="wp-mcp-ai-user-capabilities__section-body">%s</p>',
				sprintf(
					/* translators: %s: capability label. */
					esc_html__( 'Required capability: %s', 'wp-mcp-ai' ),
					esc_html( $group_email_settings['label'] )
				)
			);
		} else {
			printf(
				'<p class="wp-mcp-ai-user-capabilities__section-body">%s</p>',
				sprintf(
					/* translators: %s: capability slug. */
					esc_html__( 'Required capability: %s', 'wp-mcp-ai' ),
					esc_html( $group_email_settings['capability'] )
				)
			);
		}

		if ( $group_email_settings['limit'] > 0 ) {
			printf(
				'<p class="wp-mcp-ai-user-capabilities__section-body">%s</p>',
				sprintf(
					/* translators: %d: maximum recipients allowed. */
					esc_html__( 'Recipient limit: %d per request.', 'wp-mcp-ai' ),
					$group_email_settings['limit']
				)
			);
		} else {
			echo '<p class="wp-mcp-ai-user-capabilities__section-body">' . esc_html__( 'Recipient limit: No limit enforced.', 'wp-mcp-ai' ) . '</p>';
		}

		echo '</div>';

		$jetengine_details = $this->get_jetengine_details( $user_id );

		echo '<div class="wp-mcp-ai-user-capabilities__section">';
		echo '<h4 class="wp-mcp-ai-user-capabilities__section-title">' . esc_html__( 'JetEngine access', 'wp-mcp-ai' ) . '</h4>';
		$jetengine_summary = '';

		if ( isset( $jetengine_details['summary'] ) ) {
			$jetengine_summary = $this->format_text_block( $jetengine_details['summary'] );
		}

		if ( '' !== $jetengine_summary ) {
			echo '<div class="wp-mcp-ai-user-capabilities__section-body">' . $jetengine_summary . '</div>';
		}
		if ( ! empty( $jetengine_details['capability'] ) ) {
			printf(
				'<p class="wp-mcp-ai-user-capabilities__section-note">%1$s <code>%2$s</code></p>',
				esc_html__( 'Capability checked:', 'wp-mcp-ai' ),
				esc_html( $jetengine_details['capability'] )
			);
		}
		echo '</div>';

		if ( $show_capabilities ) {
			$capability_checks = $this->get_capability_checks( $user_id );
			echo '<div class="wp-mcp-ai-user-capabilities__section">';
			echo '<h4 class="wp-mcp-ai-user-capabilities__section-title">' . esc_html__( 'Key capabilities', 'wp-mcp-ai' ) . '</h4>';
			echo '<ul class="wp-mcp-ai-user-capabilities__capability-list">';
			foreach ( $capability_checks as $capability => $granted ) {
				$status_class = $granted ? 'granted' : 'denied';
				$label        = $granted ? __( 'Granted', 'wp-mcp-ai' ) : __( 'Not granted', 'wp-mcp-ai' );
				echo '<li class="wp-mcp-ai-user-capabilities__capability-item wp-mcp-ai-user-capabilities__capability-item--' . esc_attr( $status_class ) . '">';
				echo '<span class="wp-mcp-ai-user-capabilities__capability-name"><code>' . esc_html( $capability ) . '</code></span> ';
				echo '<span class="wp-mcp-ai-user-capabilities__capability-status">' . esc_html( $label ) . '</span>';
				echo '</li>';
			}
			echo '</ul>';
			echo '</div>';
		}

		$memberships = $this->get_site_memberships( $user_id );
		echo '<div class="wp-mcp-ai-user-capabilities__section">';
		echo '<h4 class="wp-mcp-ai-user-capabilities__section-title">' . esc_html__( 'Site memberships', 'wp-mcp-ai' ) . '</h4>';
		if ( empty( $memberships ) ) {
			echo '<p class="wp-mcp-ai-user-capabilities__section-body">' . esc_html__( 'The user belongs to the current site only.', 'wp-mcp-ai' ) . '</p>';
		} else {
			echo '<ul class="wp-mcp-ai-user-capabilities__membership-list">';
			foreach ( $memberships as $site ) {
				echo '<li class="wp-mcp-ai-user-capabilities__membership-item">';
				echo '<span class="wp-mcp-ai-user-capabilities__membership-name">' . esc_html( $site['name'] ) . '</span>';
				if ( ! empty( $site['url'] ) ) {
					echo '<span class="wp-mcp-ai-user-capabilities__membership-url">' . esc_html( $site['url'] ) . '</span>';
				}
				echo '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Fetch user details via the existing tool implementation.
	 *
	 * @param int $user_id Target user ID.
	 * @return array|WP_Error
	 */
	protected function fetch_user_info( $user_id ) {
		if ( class_exists( 'WP_MCP_AI_Tool_Get_User_Info' ) ) {
			$tool = new WP_MCP_AI_Tool_Get_User_Info();
			return $tool->execute( array(), array( 'user_id' => absint( $user_id ) ) );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'wp_mcp_ai_user_missing', __( 'Unable to load user information.', 'wp-mcp-ai' ) );
		}

		return array(
			'ID'           => $user->ID,
			'display_name' => $user->display_name,
			'user_login'   => $user->user_login,
			'user_email'   => $user->user_email,
			'roles'        => $user->roles,
			'registered'   => $user->user_registered,
		);
	}

	/**
	 * Provide JetEngine access details.
	 *
	 * @param int $user_id Target user ID.
	 * @return array
	 */
	protected function get_jetengine_details( $user_id ) {
		$available  = function_exists( 'jet_engine' ) || class_exists( 'Jet_Engine' );
		$capability = apply_filters( 'jet-engine/settings/capability', 'manage_options' );

		if ( ! is_string( $capability ) || '' === $capability ) {
			$capability = 'manage_options';
		}

		if ( ! $available ) {
			return array(
				'summary'    => __( 'JetEngine is not active on this site.', 'wp-mcp-ai' ),
				'capability' => $capability,
			);
		}

		$has_access = user_can( $user_id, $capability );

		$summary = $has_access
			? __( 'The user can reach JetEngine dashboards and REST integrations.', 'wp-mcp-ai' )
			: __( 'The user cannot reach JetEngine dashboards with the current permissions.', 'wp-mcp-ai' );

		return array(
			'summary'    => $summary,
			'capability' => $capability,
		);
	}

	/**
	 * Retrieve the Send Group Email capability and limit from settings.
	 *
	 * @return array{
	 *     capability: string,
	 *     label: string,
	 *     limit: int
	 * }
	 */
	protected function get_group_email_configuration() {
		$capability = 'publish_posts';
		$limit      = 100;

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( isset( $settings['group_email_capability'] ) ) {
				$capability = sanitize_key( $settings['group_email_capability'] );
			}

			if ( isset( $settings['group_email_max_recipients'] ) ) {
				$limit = absint( $settings['group_email_max_recipients'] );
			}
		}

		$context    = array( 'user_id' => get_current_user_id() );
		$capability = apply_filters( 'wp_mcp_ai_send_group_email_capability', $capability, $context, array(), null );
		$limit      = apply_filters( 'wp_mcp_ai_send_group_email_max_recipients', $limit, $context, array(), null );

		if ( ! is_string( $capability ) ) {
			$capability = '';
		}

		$capability = sanitize_key( $capability );

		if ( ! is_numeric( $limit ) ) {
			$limit = 0;
		}

		$limit = max( 0, absint( $limit ) );

		$label = '';

		if ( '' === $capability ) {
			$label = __( 'Any logged-in user', 'wp-mcp-ai' );
		} else {
			$label = $this->format_capability_label( $capability );
		}

		return array(
			'capability' => $capability,
			'label'      => $label,
			'limit'      => $limit,
		);
	}

	/**
	 * Convert a capability slug into a readable label.
	 *
	 * @param string $capability Capability slug.
	 * @return string
	 */
	protected function format_capability_label( $capability ) {
		$capability = sanitize_key( $capability );

		if ( '' === $capability ) {
			return '';
		}

		$readable = trim( preg_replace( '/[\-_]+/', ' ', (string) $capability ) );
		$readable = preg_replace( '/\s+/', ' ', $readable );

		if ( '' === $readable ) {
			return $capability;
		}

		$readable = ucwords( $readable );

		if ( strtolower( $readable ) === strtolower( $capability ) ) {
			return $readable;
		}

		return sprintf( '%1$s (%2$s)', $readable, $capability );
	}

	/**
	 * Determine a set of core capabilities to highlight.
	 *
	 * @param int $user_id Target user ID.
	 * @return array<string,bool>
	 */
	protected function get_capability_checks( $user_id ) {
		$checks = array(
			'manage_options',
			'edit_posts',
			'publish_posts',
			'upload_files',
			'list_users',
			'manage_woocommerce',
		);

		$group_email_settings = $this->get_group_email_configuration();

		if ( '' !== $group_email_settings['capability'] && ! in_array( $group_email_settings['capability'], $checks, true ) ) {
			$checks[] = $group_email_settings['capability'];
		}

		$results = array();

		foreach ( $checks as $capability ) {
			$results[ $capability ] = user_can( $user_id, $capability );
		}

		if ( is_multisite() ) {
			$results['manage_network'] = is_super_admin( $user_id );
		}

		return $results;
	}

	/**
	 * Retrieve additional site memberships for multisite environments.
	 *
	 * @param int $user_id Target user ID.
	 * @return array
	 */
	protected function get_site_memberships( $user_id ) {
		if ( ! is_multisite() || ! function_exists( 'get_blogs_of_user' ) ) {
			return array();
		}

		$blogs = get_blogs_of_user( $user_id );
		if ( empty( $blogs ) || ! is_array( $blogs ) ) {
			return array();
		}

		$memberships = array();

		foreach ( $blogs as $blog ) {
			$name = isset( $blog->blogname ) ? $blog->blogname : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase
			$url  = isset( $blog->siteurl ) ? $blog->siteurl : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

			if ( '' === $name && '' === $url ) {
				continue;
			}

			$memberships[] = array(
				'name' => $name,
				'url'  => $url,
			);
		}

		return $memberships;
	}

	/**
	 * Format a timestamp for display.
	 *
	 * @param string $timestamp MySQL formatted timestamp.
	 * @return string
	 */
	protected function format_timestamp( $timestamp ) {
		$time = strtotime( $timestamp );

		if ( false === $time ) {
			return $timestamp;
		}

		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}
}
