<?php
/**
 * Elementor widget for listing an assistant's base knowledge files and vector store.
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
 * Elementor widget definition for assistant base knowledge.
 */
class WP_MCP_AI_Elementor_Assistant_Base_Knowledge_Widget extends \Elementor\Widget_Base {
	use WP_MCP_AI_Elementor_Text_Formatting;

	/**
	 * Widget slug.
	 */
	public function get_name() {
		return 'wp_mcp_ai_assistant_base_knowledge';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 */
	public function get_title() {
		return __( 'NV oOS Assistant Base Knowledge', 'wp-mcp-ai' );
	}

	/**
	 * Widget icon for Elementor panel.
	 */
	public function get_icon() {
		return 'eicon-library-download';
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
		return array( 'assistant', 'knowledge', 'memory', 'files', 'mcp', 'ai' );
	}

	/**
	 * Register controls for the widget settings.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Content', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => __( 'Title', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Assistant knowledge base', 'wp-mcp-ai' ),
				'placeholder' => __( 'Enter heading text…', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'assistant_id',
			array(
				'label'       => __( 'Assistant', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => $this->get_assistant_options(),
				'default'     => '',
				'label_block' => true,
				'description' => __( 'Choose which assistant to display base knowledge for. Only published assistants appear in this list.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'show_file_sizes',
			array(
				'label'        => __( 'Show file sizes', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'empty_message',
			array(
				'label'       => __( 'Empty state message', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Select an assistant in the widget settings to view its base knowledge.', 'wp-mcp-ai' ),
				'placeholder' => __( 'Add guidance for when no assistant is selected…', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'no_files_message',
			array(
				'label'       => __( 'No files message', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'No base knowledge files have been attached to this assistant yet.', 'wp-mcp-ai' ),
				'placeholder' => __( 'Add guidance for when no knowledge files are present…', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->end_controls_section();

		$this->register_theme_style_controls(
			array(
				'section_id' => 'section_style_assistant_base_knowledge',
				'selectors'  => array(
					'container' => '{{WRAPPER}} .wp-mcp-ai-assistant-base-knowledge',
					'heading'   => '{{WRAPPER}} .wp-mcp-ai-assistant-base-knowledge__title',
					'text'      => array(
						'{{WRAPPER}} .wp-mcp-ai-assistant-base-knowledge__notice',
						'{{WRAPPER}} .wp-mcp-ai-assistant-base-knowledge__file-label',
					),
					'meta'      => '{{WRAPPER}} .wp-mcp-ai-assistant-base-knowledge__file-size',
					'link'      => '{{WRAPPER}} .wp-mcp-ai-assistant-base-knowledge__file-link',
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
		$assistant_setting = isset( $settings['assistant_id'] ) ? $settings['assistant_id'] : '';
		$assistant_id      = '' !== $assistant_setting ? absint( $assistant_setting ) : 0;
		$show_sizes        = ! empty( $settings['show_file_sizes'] ) && 'yes' === $settings['show_file_sizes'];
		$empty_message     = isset( $settings['empty_message'] ) ? $settings['empty_message'] : '';
		$no_files_message  = isset( $settings['no_files_message'] ) ? $settings['no_files_message'] : '';

		echo '<div class="wp-mcp-ai-assistant-base-knowledge">';

		if ( '' !== $title ) {
			$title_output = $this->format_text_inline( $title );

			if ( '' !== $title_output ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text_inline.
				echo '<h3 class="wp-mcp-ai-assistant-base-knowledge__title">' . $title_output . '</h3>';
			}
		}

		if ( ! $assistant_id || ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			$empty_output = $this->format_text_inline( $empty_message );

			if ( '' !== $empty_output ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text_inline.
				echo '<p class="wp-mcp-ai-assistant-base-knowledge__notice">' . $empty_output . '</p>';
			}

			echo '</div>';
			return;
		}

		$config       = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
		$memory_files = isset( $config['memory_files'] ) && is_array( $config['memory_files'] ) ? $config['memory_files'] : array();
		$vector_store = isset( $config['vector_store_id'] ) ? $config['vector_store_id'] : '';

		$entries = $this->prepare_memory_entries( $memory_files, $show_sizes );

		if ( empty( $entries ) ) {
			$no_files_output = $this->format_text_inline( $no_files_message );

			if ( '' !== $no_files_output ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in format_text_inline.
				echo '<p class="wp-mcp-ai-assistant-base-knowledge__notice">' . $no_files_output . '</p>';
			}
		} else {
			echo '<ul class="wp-mcp-ai-assistant-base-knowledge__files">';

			foreach ( $entries as $entry ) {
				$title_text = $entry['title'];
				$url        = $entry['url'];
				$size       = $entry['size'];

				echo '<li class="wp-mcp-ai-assistant-base-knowledge__file">';

				if ( '' !== $url ) {
					echo '<a class="wp-mcp-ai-assistant-base-knowledge__file-link" href="' . esc_url( $url ) . '">' . esc_html( $title_text ) . '</a>';
				} else {
					echo '<span class="wp-mcp-ai-assistant-base-knowledge__file-label">' . esc_html( $title_text ) . '</span>';
				}

				if ( $show_sizes && '' !== $size ) {
					echo '<span class="wp-mcp-ai-assistant-base-knowledge__file-size">' . esc_html( $size ) . '</span>';
				}

				echo '</li>';
			}

			echo '</ul>';
		}

		if ( '' !== $vector_store ) {
			echo '<div class="wp-mcp-ai-assistant-base-knowledge__vector-store">';
			echo '<span class="wp-mcp-ai-assistant-base-knowledge__vector-store-label">' . esc_html__( 'Vector Store ID:', 'wp-mcp-ai' ) . '</span>';
			echo '<code class="wp-mcp-ai-assistant-base-knowledge__vector-store-value">' . esc_html( $vector_store ) . '</code>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Retrieve the available assistants as select options.
	 *
	 * @return array
	 */
	protected function get_assistant_options() {
		$options = array( '' => __( 'Select an assistant', 'wp-mcp-ai' ) );

		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			return $options;
		}

		// Check if the post type is registered before querying.
		// During Elementor AJAX requests, the post type may not be registered yet.
		if ( ! post_type_exists( WP_MCP_AI_Assistant_CPT::POST_TYPE ) ) {
			return $options;
		}

		$assistants = get_posts(
			array(
				'post_type'              => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status'            => 'publish',
				'numberposts'            => -1,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'suppress_filters'       => true,
				'fields'                 => 'ids',
				'no_found_rows'          => true,  // Performance: Skip counting total rows.
				'update_post_term_cache' => false, // Performance: Skip term cache.
			)
		);

		if ( ! is_array( $assistants ) || empty( $assistants ) ) {
			return $options;
		}

		foreach ( $assistants as $assistant_id ) {
			$title = get_the_title( $assistant_id );
			if ( $title && ! is_wp_error( $title ) ) {
				$options[ (string) $assistant_id ] = $title;
			}
		}

		return $options;
	}

	/**
	 * Prepare the memory file entries for display.
	 *
	 * @param array $file_ids  Attachment IDs.
	 * @param bool  $include_size Whether to calculate file sizes.
	 * @return array
	 */
	protected function prepare_memory_entries( $file_ids, $include_size ) {
		if ( ! is_array( $file_ids ) || empty( $file_ids ) ) {
			return array();
		}

		$entries = array();

		foreach ( $file_ids as $file_id ) {
			$file_id    = absint( $file_id );
			$attachment = get_post( $file_id );

			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				continue;
			}

			$title = get_the_title( $attachment );
			if ( '' === $title ) {
				/* translators: %d: Attachment ID. */
				$title = sprintf( __( 'Attachment #%d', 'wp-mcp-ai' ), $file_id );
			}

			$url  = wp_get_attachment_url( $file_id );
			$size = '';

			if ( $include_size ) {
				$file_path = get_attached_file( $file_id );

				if ( $file_path && file_exists( $file_path ) ) {
					$file_size = filesize( $file_path );

					if ( false !== $file_size ) {
						$size = size_format( (int) $file_size );
					}
				}
			}

			$entries[] = array(
				'title' => $title,
				'url'   => is_string( $url ) ? $url : '',
				'size'  => $size,
			);
		}

		return $entries;
	}
}
