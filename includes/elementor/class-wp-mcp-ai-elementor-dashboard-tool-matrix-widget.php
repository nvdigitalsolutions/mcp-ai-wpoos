<?php
/**
 * Elementor widget for displaying a matrix of available NV oOS tools.
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
 * Elementor widget definition for the assistant tool matrix.
 */
class WP_MCP_AI_Elementor_Dashboard_Tool_Matrix_Widget extends \Elementor\Widget_Base {
	use WP_MCP_AI_Elementor_Text_Formatting;

	/**
	 * Widget slug.
	 */
	public function get_name() {
		return 'wp_mcp_ai_tool_matrix';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 */
	public function get_title() {
		return __( 'NV oOS Tool Matrix', 'mcp-ai-wpoos' );
	}

	/**
	 * Widget icon for Elementor panel.
	 */
	public function get_icon() {
		return 'eicon-table';
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
		return array( 'mcp', 'tool', 'assistant', 'matrix', 'dashboard' );
	}

	/**
	 * Register controls for the widget settings.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Matrix Content', 'mcp-ai-wpoos' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => __( 'Title', 'mcp-ai-wpoos' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Assistant tool matrix', 'mcp-ai-wpoos' ),
				'placeholder' => __( 'Enter heading text…', 'mcp-ai-wpoos' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'description',
			array(
				'label'       => __( 'Description', 'mcp-ai-wpoos' ),
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'placeholder' => __( 'Provide context for the tool list.', 'mcp-ai-wpoos' ),
				'rows'        => 4,
				'default'     => __( 'Review each bundled MCP tool, its slug, and the capability required before enabling it for assistants.', 'mcp-ai-wpoos' ),
			)
		);

		$this->add_control(
			'show_capability_notes',
			array(
				'label'        => __( 'Show capability notes', 'mcp-ai-wpoos' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'mcp-ai-wpoos' ),
				'label_off'    => __( 'No', 'mcp-ai-wpoos' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();

		$this->register_theme_style_controls(
			array(
				'section_id' => 'section_style_tool_matrix',
				'selectors'  => array(
					'container' => '{{WRAPPER}} .wp-mcp-ai-tool-matrix',
					'heading'   => array(
						'{{WRAPPER}} .wp-mcp-ai-tool-matrix__title',
						'{{WRAPPER}} .wp-mcp-ai-tool-matrix__group-title',
					),
					'text'      => array(
						'{{WRAPPER}} .wp-mcp-ai-tool-matrix__description',
						'{{WRAPPER}} .wp-mcp-ai-tool-matrix__notice',
						'{{WRAPPER}} .wp-mcp-ai-tool-matrix__cell--description',
					),
					'meta'      => array(
						'{{WRAPPER}} .wp-mcp-ai-tool-matrix__cell--slug',
						'{{WRAPPER}} .wp-mcp-ai-tool-matrix__cell--capability',
					),
					'link'      => '{{WRAPPER}} .wp-mcp-ai-tool-matrix a',
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
		$show_capabilities = ! empty( $settings['show_capability_notes'] ) && 'yes' === $settings['show_capability_notes'];

		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			echo '<div class="wp-mcp-ai-tool-matrix">';
			if ( ! empty( $title ) ) {
				echo '<h3 class="wp-mcp-ai-tool-matrix__title">' . esc_html( $title ) . '</h3>';
			}
			echo '<p class="wp-mcp-ai-tool-matrix__notice">' . esc_html__( 'The tool registry is unavailable.', 'mcp-ai-wpoos' ) . '</p>';
			echo '</div>';
			return;
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		if ( method_exists( $registry, 'init' ) ) {
			$registry->init();
		}

		$tools = $registry->get_tools();

		$group_map    = $this->get_tool_group_map();
		$group_labels = $this->get_group_labels();
		$capabilities = $this->get_capability_notes();

		$grouped = array();

		foreach ( $tools as $tool ) {
			if ( ! $tool instanceof WP_MCP_AI_Tool_Interface ) {
				continue;
			}

			$slug     = sanitize_key( $tool->get_slug() );
			$group_id = isset( $group_map[ $slug ] ) ? $group_map[ $slug ] : 'other';
			$group    = isset( $group_labels[ $group_id ] ) ? $group_labels[ $group_id ] : $group_labels['other'];

			if ( ! isset( $grouped[ $group ] ) ) {
				$grouped[ $group ] = array();
			}

			$grouped[ $group ][] = array(
				'name'        => $tool->get_name(),
				'slug'        => $slug,
				'capability'  => isset( $capabilities[ $slug ] ) ? $capabilities[ $slug ] : $capabilities['default'],
				'description' => $tool->get_description(),
			);
		}

		if ( empty( $grouped ) ) {
			echo '<div class="wp-mcp-ai-tool-matrix">';
			if ( ! empty( $title ) ) {
				echo '<h3 class="wp-mcp-ai-tool-matrix__title">' . esc_html( $title ) . '</h3>';
			}
			echo '<p class="wp-mcp-ai-tool-matrix__notice">' . esc_html__( 'No tools are currently registered.', 'mcp-ai-wpoos' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<div class="wp-mcp-ai-tool-matrix">';

		if ( ! empty( $title ) ) {
			echo '<h3 class="wp-mcp-ai-tool-matrix__title">' . esc_html( $title ) . '</h3>';
		}

		if ( ! empty( $description ) ) {
			$description_output = $this->format_text_block( $description );

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text.
			if ( '' !== $description_output ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text_block.
				echo '<div class="wp-mcp-ai-tool-matrix__description">' . $description_output . '</div>';
			}
		}

		foreach ( $grouped as $group_label => $entries ) {
			$has_descriptions     = false;
			$formatted_entries    = array();
			$has_capability_notes = false;

			foreach ( $entries as $entry ) {
				$formatted_entry = array(
					'name'        => $entry['name'],
					'slug'        => $entry['slug'],
					'capability'  => '',
					'description' => '',
				);

				if ( $show_capabilities ) {
					$capability_output = $this->format_text_inline( $entry['capability'] );

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text.
					if ( '' !== $capability_output ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text.
						$formatted_entry['capability'] = $capability_output;
						$has_capability_notes          = true;
					}
				}

				if ( ! empty( $entry['description'] ) ) {
					$description_output = $this->format_text_inline( $entry['description'] );

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text.
					if ( '' !== $description_output ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text.
						$formatted_entry['description'] = $description_output;
						$has_descriptions               = true;
					}
				}

				$formatted_entries[] = $formatted_entry;
			}

			echo '<div class="wp-mcp-ai-tool-matrix__group">';
			echo '<h4 class="wp-mcp-ai-tool-matrix__group-title">' . esc_html( $group_label ) . '</h4>';
			echo '<div class="wp-mcp-ai-tool-matrix__table">';
			echo '<table class="wp-mcp-ai-tool-matrix__table-grid">';
			echo '<thead>';
			echo '<tr class="wp-mcp-ai-tool-matrix__table-row wp-mcp-ai-tool-matrix__table-row--head">';
			echo '<th scope="col" class="wp-mcp-ai-tool-matrix__cell wp-mcp-ai-tool-matrix__cell--name">' . esc_html__( 'Tool', 'mcp-ai-wpoos' ) . '</th>';
			echo '<th scope="col" class="wp-mcp-ai-tool-matrix__cell wp-mcp-ai-tool-matrix__cell--slug">' . esc_html__( 'Slug', 'mcp-ai-wpoos' ) . '</th>';

			if ( $has_capability_notes ) {
				echo '<th scope="col" class="wp-mcp-ai-tool-matrix__cell wp-mcp-ai-tool-matrix__cell--capability">' . esc_html__( 'Required capability', 'mcp-ai-wpoos' ) . '</th>';
			}

			if ( $has_descriptions ) {
				echo '<th scope="col" class="wp-mcp-ai-tool-matrix__cell wp-mcp-ai-tool-matrix__cell--description">' . esc_html__( 'Description', 'mcp-ai-wpoos' ) . '</th>';
			}

			echo '</tr>';
			echo '</thead>';
			echo '<tbody>';

			foreach ( $formatted_entries as $formatted_entry ) {
				echo '<tr class="wp-mcp-ai-tool-matrix__table-row">';
				echo '<td class="wp-mcp-ai-tool-matrix__cell wp-mcp-ai-tool-matrix__cell--name">' . esc_html( $formatted_entry['name'] ) . '</td>';
				echo '<td class="wp-mcp-ai-tool-matrix__cell wp-mcp-ai-tool-matrix__cell--slug"><code>' . esc_html( $formatted_entry['slug'] ) . '</code></td>';

				if ( $has_capability_notes ) {
					echo '<td class="wp-mcp-ai-tool-matrix__cell wp-mcp-ai-tool-matrix__cell--capability">' . $formatted_entry['capability'] . '</td>';
				}

				if ( $has_descriptions ) {
					echo '<td class="wp-mcp-ai-tool-matrix__cell wp-mcp-ai-tool-matrix__cell--description">' . esc_html( $formatted_entry['description'] ) . '</td>';
				}

				echo '</tr>';
			}

			echo '</tbody>';
			echo '</table>';
			echo '</div>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Mapping of tool slugs to group identifiers.
	 *
	 * @return array
	 */
	protected function get_tool_group_map() {
		if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$registry = WP_MCP_AI_Tool_Registry::get_instance();

			if ( method_exists( $registry, 'init' ) ) {
				$registry->init();
			}

			if ( method_exists( $registry, 'get_tool_group_map' ) ) {
				$registry_map = $registry->get_tool_group_map();

				if ( is_array( $registry_map ) && ! empty( $registry_map ) ) {
					return $registry_map;
				}
			}
		}

		return array(
			'submit_document_prompt'       => 'content',
			'search_content'               => 'content',
			'search_attachments'           => 'content',
			'get_recent_posts'             => 'content',
			'save_post'                    => 'content',
			'get_user_info'                => 'operations',
			'get_site_summary'             => 'operations',
			'get_system_logs'              => 'operations',
			'open_openai_usage'            => 'operations',
			'open_openai_logs'             => 'operations',
			'create_cron_job'              => 'operations',
			'purge_cloudflare_cache'       => 'operations',
			'run_openai_external_action'   => 'automation',
			'run_crawl4ai_job'             => 'automation',
			'create_google_calendar_event' => 'automation',
			'web_search'                   => 'external-data',
			'get_gdacs_events'             => 'external-data',
			'get_open_meteo_forecast'      => 'external-data',
			'get_nhc_active_storms'        => 'external-data',
			'reliefweb_reports'            => 'external-data',
			'generate_openai_image'        => 'media',
			'generate_openai_speech'       => 'media',
			'transcribe_openai_audio'      => 'media',
			'get_jetengine_items'          => 'jetengine',
			'list_jetengine_rest_routes'   => 'jetengine',
			'invoke_jetengine_route'       => 'jetengine',
			'get_woo_recent_orders'        => 'commerce',
			'get_woo_products'             => 'commerce',
			'quickbooks_report'            => 'commerce',
			'search_gmail'                 => 'communication',
			'send_group_email'             => 'communication',
			'send_mailjet_email'           => 'communication',
			'send_telegram_message'        => 'communication',
		);
	}

	/**
	 * Human readable labels for tool groups.
	 *
	 * @return array
	 */
	protected function get_group_labels() {
		if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$registry = WP_MCP_AI_Tool_Registry::get_instance();

			if ( method_exists( $registry, 'init' ) ) {
				$registry->init();
			}

			if ( method_exists( $registry, 'get_tool_group_labels' ) ) {
				$registry_labels = $registry->get_tool_group_labels();

				if ( is_array( $registry_labels ) && ! empty( $registry_labels ) ) {
					return $registry_labels;
				}
			}
		}

		return array(
			'content'       => __( 'Content ingestion & search', 'mcp-ai-wpoos' ),
			'media'         => __( 'Media generation & transcription', 'mcp-ai-wpoos' ),
			'automation'    => __( 'Automations & workflows', 'mcp-ai-wpoos' ),
			'jetengine'     => __( 'JetEngine REST utilities', 'mcp-ai-wpoos' ),
			'commerce'      => __( 'Commerce & finance', 'mcp-ai-wpoos' ),
			'communication' => __( 'Communications & outreach', 'mcp-ai-wpoos' ),
			'external-data' => __( 'External data sources', 'mcp-ai-wpoos' ),
			'operations'    => __( 'Site operations & maintenance', 'mcp-ai-wpoos' ),
			'other'         => __( 'Other tools', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Capability notes for each tool slug.
	 *
	 * @return array
	 */
	protected function get_capability_notes() {
		$group_email = $this->get_group_email_configuration();

		if ( '' === $group_email['label'] && '' !== $group_email['capability'] ) {
			$group_email['label'] = $this->format_capability_label( $group_email['capability'] );
		}

		if ( '' === $group_email['capability'] ) {
			$group_capability_note = __( 'Allows any logged-in user to send group emails.', 'mcp-ai-wpoos' );
		} else {
			$group_capability_note = sprintf(
				/* translators: %s: capability label. */
				__( 'Requires the %s capability configured in the settings.', 'mcp-ai-wpoos' ),
				$group_email['label']
			);
		}

		if ( $group_email['limit'] > 0 ) {
			$group_limit_note = sprintf(
				/* translators: %d: maximum recipients per request. */
				__( 'Limited to %d recipients per request.', 'mcp-ai-wpoos' ),
				$group_email['limit']
			);
		} else {
			$group_limit_note = __( 'No recipient limit is enforced.', 'mcp-ai-wpoos' );
		}

		return array(
			'default'                    => __( 'Requires authenticated access.', 'mcp-ai-wpoos' ),
			'submit_document_prompt'     => __( 'Requires upload permissions matching attachment handling.', 'mcp-ai-wpoos' ),
			'get_recent_posts'           => __( 'Requires the "read" capability.', 'mcp-ai-wpoos' ),
			'get_user_info'              => __( 'Requires login; "list_users" or "manage_options" to inspect other profiles.', 'mcp-ai-wpoos' ),
			'get_site_summary'           => __( 'Requires the "manage_options" capability.', 'mcp-ai-wpoos' ),
			'get_jetengine_items'        => __( 'Requires access to the JetEngine post type (typically "edit_posts").', 'mcp-ai-wpoos' ),
			'get_woo_recent_orders'      => __( 'Requires "manage_woocommerce" or "view_woocommerce_reports".', 'mcp-ai-wpoos' ),
			'get_woo_products'           => __( 'Requires "manage_woocommerce" or "view_woocommerce_reports".', 'mcp-ai-wpoos' ),
			'generate_openai_image'      => __( 'Requires the "upload_files" capability for media storage.', 'mcp-ai-wpoos' ),
			'generate_openai_speech'     => __( 'Requires the "upload_files" capability for media storage.', 'mcp-ai-wpoos' ),
			'transcribe_openai_audio'    => __( 'Requires the "upload_files" capability for media storage.', 'mcp-ai-wpoos' ),
			'run_openai_external_action' => __( 'Requires the "manage_options" capability.', 'mcp-ai-wpoos' ),
			'run_crawl4ai_job'           => __( 'Requires the "manage_options" capability.', 'mcp-ai-wpoos' ),
			'web_search'                 => __( 'Requires the "read" capability.', 'mcp-ai-wpoos' ),
			'list_jetengine_rest_routes' => __( 'Requires the "manage_options" capability and JetEngine.', 'mcp-ai-wpoos' ),
			'invoke_jetengine_route'     => __( 'Requires JetEngine access for the requested operation.', 'mcp-ai-wpoos' ),
			'create_cron_job'            => __( 'Requires the "manage_options" capability.', 'mcp-ai-wpoos' ),
			'send_group_email'           => trim( $group_capability_note . ' ' . $group_limit_note ),
			'open_openai_usage'          => __( 'Requires the "manage_options" capability.', 'mcp-ai-wpoos' ),
			'open_openai_logs'           => __( 'Requires the "manage_options" capability.', 'mcp-ai-wpoos' ),
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
			$label = __( 'Any logged-in user', 'mcp-ai-wpoos' );
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
}
