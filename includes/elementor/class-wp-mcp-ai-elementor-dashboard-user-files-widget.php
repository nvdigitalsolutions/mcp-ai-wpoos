<?php
/**
 * Elementor widget for listing files owned by a user.
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
 * Elementor widget definition for the user file list.
 */
class WP_MCP_AI_Elementor_Dashboard_User_Files_Widget extends \Elementor\Widget_Base {
	use WP_MCP_AI_Elementor_Text_Formatting;

	/**
	 * Widget slug.
	 */
	public function get_name() {
		return 'wp_mcp_ai_user_files';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 */
	public function get_title() {
		return __( 'WP oOS User File List', 'wp-mcp-ai' );
	}

	/**
	 * Widget icon for Elementor panel.
	 */
	public function get_icon() {
		return 'eicon-library-open';
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
		return array( 'mcp', 'files', 'attachments', 'user', 'dashboard' );
	}

	/**
	 * Register controls for the widget settings.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'File List', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => __( 'Title', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Knowledge files', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'description',
			array(
				'label'   => __( 'Description', 'wp-mcp-ai' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'rows'    => 3,
				'default' => __( 'Browse the documents associated with this operator to confirm the correct files are available.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'user_mode',
			array(
				'label'   => __( 'User Source', 'wp-mcp-ai' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'current',
				'options' => array(
					'current'  => __( 'Current user', 'wp-mcp-ai' ),
					'specific' => __( 'Specific user ID', 'wp-mcp-ai' ),
				),
			)
		);

		$this->add_control(
			'user_id',
			array(
				'label'       => __( 'User ID', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'min'         => 1,
				'label_block' => true,
				'condition'   => array(
					'user_mode' => 'specific',
				),
			)
		);

		$this->add_control(
			'max_items',
			array(
				'label'       => __( 'Maximum files to show', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'min'         => 1,
				'default'     => 20,
				'description' => __( 'Limit the number of attachments displayed. Leave empty to show every file.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'show_file_size',
			array(
				'label'        => __( 'Show file size', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_upload_date',
			array(
				'label'        => __( 'Show upload date', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'no',
			)
		);

		$this->add_control(
			'empty_message',
			array(
				'label'       => __( 'Empty state message', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'No files are linked to this user yet.', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->end_controls_section();

		$this->register_theme_style_controls(
			array(
				'section_id' => 'section_style_user_files',
				'selectors'  => array(
					'container' => '{{WRAPPER}} .wp-mcp-ai-user-files',
					'heading'   => '{{WRAPPER}} .wp-mcp-ai-user-files__title',
					'text'      => array(
						'{{WRAPPER}} .wp-mcp-ai-user-files__description',
						'{{WRAPPER}} .wp-mcp-ai-user-files__notice',
					),
					'meta'      => array(
						'{{WRAPPER}} .wp-mcp-ai-user-files__name',
						'{{WRAPPER}} .wp-mcp-ai-user-files__meta',
					),
					'link'      => '{{WRAPPER}} .wp-mcp-ai-user-files__link',
				),
			)
		);
	}

	/**
	 * Render the widget on the front-end.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$title           = isset( $settings['title'] ) ? $settings['title'] : '';
		$description     = isset( $settings['description'] ) ? $settings['description'] : '';
		$user_mode       = isset( $settings['user_mode'] ) ? $settings['user_mode'] : 'current';
		$user_id_setting = isset( $settings['user_id'] ) ? (int) $settings['user_id'] : 0;
		$max_items       = isset( $settings['max_items'] ) ? (int) $settings['max_items'] : 0;
		$show_file_size  = ! empty( $settings['show_file_size'] ) && 'yes' === $settings['show_file_size'];
		$show_upload     = ! empty( $settings['show_upload_date'] ) && 'yes' === $settings['show_upload_date'];
		$empty_message   = isset( $settings['empty_message'] ) ? $settings['empty_message'] : '';

		$user_id = 0;

		if ( 'specific' === $user_mode ) {
			$user_id = absint( $user_id_setting );
		} else {
			$user_id = get_current_user_id();
		}

		echo '<div class="wp-mcp-ai-user-files">';

		if ( ! empty( $title ) ) {
			echo '<h3 class="wp-mcp-ai-user-files__title">' . esc_html( $title ) . '</h3>';
		}

		if ( ! empty( $description ) ) {
			$description_output = $this->format_text_block( $description );

			if ( '' !== $description_output ) {
				echo '<div class="wp-mcp-ai-user-files__description">' . $description_output . '</div>';
			}
		}

		if ( ! $user_id ) {
			echo '<p class="wp-mcp-ai-user-files__notice">' . esc_html__( 'Select a user to view their files.', 'wp-mcp-ai' ) . '</p>';
			echo '</div>';
			return;
		}

		$files = $this->get_user_files( $user_id, $max_items );

		if ( is_wp_error( $files ) ) {
			echo '<p class="wp-mcp-ai-user-files__notice">' . esc_html( $files->get_error_message() ) . '</p>';
			echo '</div>';
			return;
		}

		if ( empty( $files ) ) {
			if ( '' !== $empty_message ) {
				echo '<p class="wp-mcp-ai-user-files__notice">' . esc_html( $empty_message ) . '</p>';
			}

			echo '</div>';
			return;
		}

		echo '<ul class="wp-mcp-ai-user-files__list">';

		foreach ( $files as $file ) {
			$attachment_id = isset( $file->ID ) ? (int) $file->ID : 0;

			if ( ! $attachment_id ) {
				continue;
			}

			$title_text = get_the_title( $attachment_id );

			if ( '' === $title_text ) {
				/* translators: %d: attachment ID */
				$title_text = sprintf( __( 'Attachment #%d', 'wp-mcp-ai' ), $attachment_id );
			}

			$file_url = wp_get_attachment_url( $attachment_id );
			$meta     = array();

			if ( $show_file_size ) {
				$size_label = $this->get_attachment_size_label( $attachment_id );

				if ( '' !== $size_label ) {
					$meta[] = $size_label;
				}
			}

			if ( $show_upload ) {
				$upload_date = get_the_date( '', $attachment_id );

				if ( '' !== $upload_date ) {
					$meta[] = $upload_date;
				}
			}

			echo '<li class="wp-mcp-ai-user-files__item">';

			if ( $file_url ) {
				echo '<a class="wp-mcp-ai-user-files__link" href="' . esc_url( $file_url ) . '">' . esc_html( $title_text ) . '</a>';
			} else {
				echo '<span class="wp-mcp-ai-user-files__name">' . esc_html( $title_text ) . '</span>';
			}

			if ( ! empty( $meta ) ) {
				echo '<div class="wp-mcp-ai-user-files__meta">' . esc_html( implode( ' • ', $meta ) ) . '</div>';
			}

			echo '</li>';
		}

		echo '</ul>';
		echo '</div>';
	}

	/**
	 * Retrieve the attachments owned by the provided user.
	 *
	 * @param int $user_id User identifier.
	 * @param int $max     Maximum number of results to return.
	 *
	 * @return array|WP_Error
	 */
	protected function get_user_files( $user_id, $max = 0 ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return new WP_Error( 'wp_mcp_ai_user_missing', __( 'A valid user could not be detected.', 'wp-mcp-ai' ) );
		}

		$user = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			return new WP_Error( 'wp_mcp_ai_user_missing', __( 'The requested user does not exist.', 'wp-mcp-ai' ) );
		}

		$query_args = array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'author'                 => $user_id,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,  // Performance: Skip counting total rows.
			'update_post_term_cache' => false, // Performance: Skip term cache.
			'update_post_meta_cache' => true,  // Keep meta cache for attachment data.
		);

		if ( $max > 0 ) {
			$query_args['posts_per_page'] = $max;
		}

		$attachments = get_posts( $query_args );

		if ( ! is_array( $attachments ) ) {
			return array();
		}

		wp_reset_postdata();

		return $attachments;
	}

	/**
	 * Build a formatted file size string for an attachment when possible.
	 *
	 * @param int $attachment_id Attachment identifier.
	 *
	 * @return string
	 */
	protected function get_attachment_size_label( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id ) {
			return '';
		}

		$file_path = get_attached_file( $attachment_id );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return '';
		}

		$file_size = filesize( $file_path );

		if ( false === $file_size ) {
			return '';
		}

		return size_format( (int) $file_size );
	}
}
