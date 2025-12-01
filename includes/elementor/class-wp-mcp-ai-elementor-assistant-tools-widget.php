<?php
/**
 * Elementor widget that lists tools assigned to a specific assistant.
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
 * Elementor widget definition for displaying an assistant's tools.
 */
class WP_MCP_AI_Elementor_Assistant_Tools_Widget extends \Elementor\Widget_Base {
	use WP_MCP_AI_Elementor_Text_Formatting;

	/**
	 * Widget slug.
	 */
	public function get_name() {
		return 'wp_mcp_ai_assistant_tools';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 */
	public function get_title() {
		return __( 'WP oOS Assistant Tools', 'wp-mcp-ai' );
	}

	/**
	 * Widget icon for Elementor panel.
	 */
	public function get_icon() {
		return 'eicon-preview-medium';
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
		return array( 'assistant', 'tools', 'mcp', 'ai' );
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
				'default'     => __( 'Available assistant tools', 'wp-mcp-ai' ),
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
				'description' => __( 'Choose which assistant to display tools for. Only published assistants appear in this list.', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'show_descriptions',
			array(
				'label'        => __( 'Show descriptions', 'wp-mcp-ai' ),
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
				'default'     => __( 'No tools have been assigned to this assistant yet.', 'wp-mcp-ai' ),
				'placeholder' => __( 'Add guidance for when no tools are available…', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->end_controls_section();

		$this->register_theme_style_controls(
			array(
				'section_id' => 'section_style_assistant_tools',
				'selectors'  => array(
					'container' => '{{WRAPPER}} .wp-mcp-ai-assistant-tools',
					'heading'   => '{{WRAPPER}} .wp-mcp-ai-assistant-tools__title',
					'text'      => array(
						'{{WRAPPER}} .wp-mcp-ai-assistant-tools__notice',
						'{{WRAPPER}} .wp-mcp-ai-assistant-tools__description',
					),
					'meta'      => array(
						'{{WRAPPER}} .wp-mcp-ai-assistant-tools__slug-label',
						'{{WRAPPER}} .wp-mcp-ai-assistant-tools__slug-code',
						'{{WRAPPER}} .wp-mcp-ai-assistant-tools__copy-feedback',
					),
					'link'      => '{{WRAPPER}} .wp-mcp-ai-assistant-tools a',
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
		$show_descriptions = ! empty( $settings['show_descriptions'] ) && 'yes' === $settings['show_descriptions'];
		$empty_message     = isset( $settings['empty_message'] ) ? $settings['empty_message'] : '';

		echo '<div class="wp-mcp-ai-assistant-tools">';

		if ( '' !== $title ) {
			$title_output = $this->format_text_inline( $title );

			if ( '' !== $title_output ) {
				echo '<h3 class="wp-mcp-ai-assistant-tools__title">' . $title_output . '</h3>';
			}
		}

		if ( ! $assistant_id ) {
			echo '<p class="wp-mcp-ai-assistant-tools__notice">' . esc_html__( 'Select an assistant in the widget settings to view its tools.', 'wp-mcp-ai' ) . '</p>';
			echo '</div>';
			return;
		}

		$tools      = $this->get_assistant_tools( $assistant_id );
		$tool_items = isset( $tools['registered'] ) ? $tools['registered'] : array();
		$missing    = isset( $tools['missing'] ) ? $tools['missing'] : array();
		$registry   = isset( $tools['registry_available'] ) ? (bool) $tools['registry_available'] : true;

		if ( empty( $tool_items ) ) {
			if ( ! $registry && ! empty( $tools['requested'] ) ) {
				echo '<p class="wp-mcp-ai-assistant-tools__notice">' . esc_html__( 'The tool registry is currently unavailable.', 'wp-mcp-ai' ) . '</p>';
			} else {
				$empty_output = $this->format_text_inline( $empty_message );

				if ( '' !== $empty_output ) {
					echo '<p class="wp-mcp-ai-assistant-tools__notice">' . $empty_output . '</p>';
				}
			}
		} else {
			echo '<ul class="wp-mcp-ai-assistant-tools__list">';

			foreach ( $tool_items as $tool ) {
				$name        = isset( $tool['name'] ) ? $tool['name'] : '';
				$slug        = isset( $tool['slug'] ) ? $tool['slug'] : '';
				$description = isset( $tool['description'] ) ? $tool['description'] : '';

				echo '<li class="wp-mcp-ai-assistant-tools__item">';
				echo '<div class="wp-mcp-ai-assistant-tools__item-header">';

				if ( '' !== $name ) {
					echo '<span class="wp-mcp-ai-assistant-tools__name">' . esc_html( $name ) . '</span>';
				}

				if ( '' !== $slug ) {
					/* translators: %s: tool slug. */
					$copy_label   = sprintf( esc_html__( 'Copy the %s tool slug', 'wp-mcp-ai' ), $slug );
					$success_text = esc_html__( 'Copied!', 'wp-mcp-ai' );
					$failure_text = esc_html__( 'Copy failed', 'wp-mcp-ai' );

					echo '<span class="wp-mcp-ai-assistant-tools__slug">';
					echo '<span class="wp-mcp-ai-assistant-tools__slug-label">' . esc_html__( 'Slug:', 'wp-mcp-ai' ) . '</span> ';
					echo '<button type="button" class="wp-mcp-ai-assistant-tools__copy-button" data-slug="' . esc_attr( $slug ) . '" data-success-message="' . esc_attr( $success_text ) . '" data-failure-message="' . esc_attr( $failure_text ) . '" aria-label="' . esc_attr( $copy_label ) . '">';
					echo '<code class="wp-mcp-ai-assistant-tools__slug-code">' . esc_html( $slug ) . '</code>';
					echo '<span class="wp-mcp-ai-assistant-tools__copy-feedback" aria-hidden="true" style="display:none;">' . esc_html( $success_text ) . '</span>';
					echo '</button>';
					echo '</span>';
				}

				echo '</div>';

				if ( $show_descriptions && '' !== $description ) {
					$description_text   = wp_kses_post( $description );
					$description_output = wpautop( $description_text );
					$description_output = wp_kses_post( $description_output );

					if ( '' !== $description_output ) {
						echo '<div class="wp-mcp-ai-assistant-tools__description">' . $description_output . '</div>';
					}
				}

				echo '</li>';
			}

			echo '</ul>';
		}

		if ( ! empty( $missing ) ) {
			$missing_list = implode( ', ', array_map( 'esc_html', $missing ) );

			if ( '' !== $missing_list ) {
				echo '<p class="wp-mcp-ai-assistant-tools__notice wp-mcp-ai-assistant-tools__notice--warning">';
				echo esc_html__( 'Some tools assigned to this assistant are no longer registered:', 'wp-mcp-ai' ) . ' ' . $missing_list;
				echo '</p>';
			}
		}

		echo '</div>';

		self::maybe_print_copy_script();
	}

	/**
	 * Ensure the inline copy-to-clipboard script is only printed once.
	 */
	protected static function maybe_print_copy_script() {
		static $printed = false;

		if ( $printed ) {
			return;
		}

		$printed = true;

		$style = <<<'CSS'
.wp-mcp-ai-assistant-tools__copy-button {
    background: none;
    border: 0;
    padding: 0;
    font: inherit;
    color: inherit;
    display: inline-flex;
    align-items: center;
    cursor: pointer;
}

.wp-mcp-ai-assistant-tools__copy-button:focus-visible {
    outline: 2px solid currentColor;
    outline-offset: 2px;
}

.wp-mcp-ai-assistant-tools__copy-feedback {
    margin-left: 0.5rem;
    font-size: 0.85em;
}
CSS;

		echo '<style>' . $style . '</style>';

		$script = <<<'JS'
( function() {
    var selector = '.wp-mcp-ai-assistant-tools__copy-button';

    function setFeedback( button, isSuccess ) {
        var feedback = button.querySelector( '.wp-mcp-ai-assistant-tools__copy-feedback' );

        if ( ! feedback ) {
            return;
        }

        var message = isSuccess ? button.getAttribute( 'data-success-message' ) : button.getAttribute( 'data-failure-message' );

        if ( ! message ) {
            return;
        }

        feedback.textContent = message;
        feedback.style.display = '';
        feedback.setAttribute( 'aria-hidden', 'false' );

        clearTimeout( button._wpMcpAiCopyTimeout );
        button._wpMcpAiCopyTimeout = setTimeout( function() {
            feedback.style.display = 'none';
            feedback.setAttribute( 'aria-hidden', 'true' );
        }, 2000 );
    }

    function copyWithFallback( text ) {
        return new Promise( function( resolve, reject ) {
            try {
                var textarea = document.createElement( 'textarea' );
                textarea.value = text;
                textarea.setAttribute( 'readonly', '' );
                textarea.style.position = 'absolute';
                textarea.style.left = '-9999px';
                document.body.appendChild( textarea );
                textarea.select();

                var successful = document.execCommand( 'copy' );
                document.body.removeChild( textarea );

                if ( successful ) {
                    resolve();
                    return;
                }

                reject();
            } catch ( error ) {
                reject( error );
            }
        } );
    }

    function copySlug( slug ) {
        if ( navigator.clipboard && navigator.clipboard.writeText ) {
            return navigator.clipboard.writeText( slug ).catch( function() {
                return copyWithFallback( slug );
            } );
        }

        return copyWithFallback( slug );
    }

    function bindCopy( button ) {
        if ( button.dataset.wpMcpAiCopyBound ) {
            return;
        }

        button.dataset.wpMcpAiCopyBound = '1';

        button.addEventListener( 'click', function() {
            var slug = button.getAttribute( 'data-slug' );

            if ( ! slug ) {
                return;
            }

            copySlug( slug )
                .then( function() {
                    setFeedback( button, true );
                } )
                .catch( function() {
                    setFeedback( button, false );
                } );
        } );
    }

    function init( context ) {
        var scope = context || document;
        var buttons = scope.querySelectorAll( selector );

        if ( ! buttons.length ) {
            return;
        }

        buttons.forEach( bindCopy );
    }

    if ( document.readyState !== 'loading' ) {
        init();
    } else {
        document.addEventListener( 'DOMContentLoaded', function() {
            init();
        } );
    }

    document.addEventListener( 'elementor/popup/show', function( event ) {
        init( event && event.target ? event.target : document );
    } );
} )();
JS;

		if ( function_exists( 'wp_print_inline_script_tag' ) ) {
			wp_print_inline_script_tag( $script );
			return;
		}

		echo '<script>' . $script . '</script>';
	}

	/**
	 * Retrieve the available assistants as select options.
	 *
	 * @return array
	 */
	protected function get_assistant_options() {
		$options = array( '' => __( 'Select an assistant…', 'wp-mcp-ai' ) );

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
	 * Prepare an assistant's tool assignments for output.
	 *
	 * @param int $assistant_id Assistant post ID.
	 *
	 * @return array{
	 *     registered: array<array{slug:string,name:string,description:string}>,
	 *     missing: string[],
	 *     requested: string[],
	 *     registry_available: bool
	 * }
	 */
	protected function get_assistant_tools( $assistant_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			return array(
				'registered'         => array(),
				'missing'            => array(),
				'requested'          => array(),
				'registry_available' => false,
			);
		}

		$stored = get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, true );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$requested = array();

		foreach ( $stored as $slug ) {
			if ( ! is_string( $slug ) ) {
				continue;
			}

			$slug = sanitize_key( $slug );

			if ( '' === $slug ) {
				continue;
			}

			$requested[] = $slug;
		}

		$requested = array_values( array_unique( $requested ) );

		if ( empty( $requested ) ) {
			return array(
				'registered'         => array(),
				'missing'            => array(),
				'requested'          => array(),
				'registry_available' => true,
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return array(
				'registered'         => array(),
				'missing'            => $requested,
				'requested'          => $requested,
				'registry_available' => false,
			);
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		if ( method_exists( $registry, 'init' ) ) {
			$registry->init();
		}

		$registered = array();
		$missing    = array();

		foreach ( $requested as $slug ) {
			$tool = $registry->get_tool( $slug );

			if ( $tool instanceof WP_MCP_AI_Tool_Interface ) {
				$registered[] = array(
					'slug'        => $slug,
					'name'        => $tool->get_name(),
					'description' => $tool->get_description(),
				);
				continue;
			}

			$missing[] = $slug;
		}

		return array(
			'registered'         => $registered,
			'missing'            => $missing,
			'requested'          => $requested,
			'registry_available' => true,
		);
	}
}
