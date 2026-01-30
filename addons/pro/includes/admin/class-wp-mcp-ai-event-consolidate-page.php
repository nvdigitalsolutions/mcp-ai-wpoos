<?php
/**
 * Event Consolidate & Add Page
 *
 * Enhanced event import with iCalendar (ICS) support and RFC 5545 validation.
 * Implements industry best practices for event data management.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-consolidate-add-base.php';

/**
 * Event Consolidation Admin Page
 */
class WP_MCP_AI_Event_Consolidate_Page extends WP_MCP_AI_Consolidate_Add_Base {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'event-consolidate';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		$instance = new self( 'events' );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 25 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Add submenu page under Events menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_event',
			__( 'Consolidate & Add Events', 'mcp-ai-wpoos-pro' ),
			__( 'Consolidate & Add', 'mcp-ai-wpoos-pro' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the page.
	 */
	public static function render_page() {
		$instance = new self( 'events' );
		$instance->render();
	}

	/**
	 * Enqueue assets for the consolidation page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		// Only load on our consolidation page.
		if ( 'mcp_ai_event_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		// Enqueue chat assets if available.
		if ( class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			$shortcode_instance = new WP_MCP_AI_Shortcode();
			$shortcode_instance->register_assets();
			wp_enqueue_style( WP_MCP_AI_Shortcode::STYLE_HANDLE );
			wp_enqueue_script( WP_MCP_AI_Shortcode::SCRIPT_HANDLE );
		}

		// Enqueue consolidation page specific script.
		wp_enqueue_script(
			'wp-mcp-ai-event-consolidate',
			WP_MCP_AI_PRO_URL . 'assets/js/event-consolidate.js',
			array( 'jquery' ),
			WP_MCP_AI_PRO_VERSION,
			true
		);

		wp_localize_script(
			'wp-mcp-ai-event-consolidate',
			'wpMcpAiEventConsolidate',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonces'  => array(
					'bulk_import'        => wp_create_nonce( 'wp_mcp_ai_bulk_import' ),
					'upload_document'    => wp_create_nonce( 'wp_mcp_ai_upload_document' ),
					'validate_data'      => wp_create_nonce( 'wp_mcp_ai_validate_data' ),
					'check_completeness' => wp_create_nonce( 'wp_mcp_ai_check_completeness' ),
				),
			)
		);
	}

	/**
	 * Get entity types for event toolkit.
	 *
	 * @return array Entity types.
	 */
	protected function get_entity_types() {
		return array(
			'events' => __( 'Events', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Get import formats supported for events.
	 *
	 * @return array Import formats.
	 */
	protected function get_import_formats() {
		return array(
			'ics'  => 'iCalendar (ICS)',
			'csv'  => 'CSV',
			'json' => 'JSON',
		);
	}

	/**
	 * Get validation schema for events based on iCalendar RFC 5545 standards.
	 *
	 * @return array Validation rules.
	 */
	protected function get_validation_schema() {
		return array(
			'required_fields'    => array(
				'uid'     => __( 'UID (Unique Identifier)', 'mcp-ai-wpoos-pro' ),
				'title'   => __( 'Event Summary/Title', 'mcp-ai-wpoos-pro' ),
				'dtstart' => __( 'Start Date/Time', 'mcp-ai-wpoos-pro' ),
				'dtstamp' => __( 'Timestamp', 'mcp-ai-wpoos-pro' ),
			),
			'recommended_fields' => array(
				'dtend'       => __( 'End Date/Time', 'mcp-ai-wpoos-pro' ),
				'location'    => __( 'Location', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Description', 'mcp-ai-wpoos-pro' ),
				'organizer'   => __( 'Organizer', 'mcp-ai-wpoos-pro' ),
				'url'         => __( 'Event URL', 'mcp-ai-wpoos-pro' ),
			),
			'validation_rules'   => array(
				'uid'     => array(
					'type'   => 'string',
					'unique' => true,
					'format' => 'UID format: alphanumeric@domain',
				),
				'dtstart' => array(
					'type'   => 'datetime',
					'format' => 'ISO 8601: YYYY-MM-DDTHH:MM:SS or YYYYMMDDTHHMMSS',
				),
				'dtend'   => array(
					'type' => 'datetime',
					'rule' => 'Must be after dtstart',
				),
			),
			'quality_dimensions' => array(
				'completeness' => __( 'All required RFC 5545 fields present', 'mcp-ai-wpoos-pro' ),
				'accuracy'     => __( 'Valid date/time formats', 'mcp-ai-wpoos-pro' ),
				'consistency'  => __( 'Timezone information included for non-UTC times', 'mcp-ai-wpoos-pro' ),
				'uniqueness'   => __( 'Unique UIDs for each event', 'mcp-ai-wpoos-pro' ),
				'recurrence'   => __( 'Proper RRULE formatting for recurring events', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Parse imported event data.
	 *
	 * @param string $data   Raw import data.
	 * @param string $format Import format.
	 * @return array|WP_Error Parsed data or error.
	 */
	protected function parse_import_data( $data, $format ) {
		switch ( $format ) {
			case 'ics':
				return $this->parse_ics_data( $data );
			case 'csv':
				return $this->parse_csv_data( $data );
			case 'json':
				return $this->parse_json_data( $data );
			default:
				return new WP_Error( 'unsupported_format', __( 'Unsupported import format', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Parse iCalendar (ICS) event data.
	 *
	 * @param string $data ICS data.
	 * @return array|WP_Error Parsed events or error.
	 */
	protected function parse_ics_data( $data ) {
		// Validate ICS structure.
		if ( false === strpos( $data, 'BEGIN:VCALENDAR' ) || false === strpos( $data, 'END:VCALENDAR' ) ) {
			return new WP_Error( 'invalid_ics', __( 'Invalid iCalendar format: missing VCALENDAR component', 'mcp-ai-wpoos-pro' ) );
		}

		$events        = array();
		$lines         = explode( "\n", $data );
		$in_event      = false;
		$current_event = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( 'BEGIN:VEVENT' === $line ) {
				$in_event      = true;
				$current_event = array();
				continue;
			}

			if ( 'END:VEVENT' === $line ) {
				$in_event = false;
				if ( ! empty( $current_event ) ) {
					$events[] = $this->normalize_event_data( $current_event );
				}
				continue;
			}

			if ( $in_event && false !== strpos( $line, ':' ) ) {
				list( $key, $value ) = explode( ':', $line, 2 );

				// Handle parameters (e.g., DTSTART;TZID=America/New_York:20240101T120000).
				if ( false !== strpos( $key, ';' ) ) {
					list( $key, $params )                            = explode( ';', $key, 2 );
					$current_event[ strtolower( $key ) . '_params' ] = $params;
				}

				$current_event[ strtolower( $key ) ] = $value;
			}
		}

		if ( empty( $events ) ) {
			return new WP_Error( 'no_events_found', __( 'No VEVENT components found in iCalendar file', 'mcp-ai-wpoos-pro' ) );
		}

		return $events;
	}

	/**
	 * Parse CSV event data.
	 *
	 * @param string $data CSV data.
	 * @return array|WP_Error Parsed events or error.
	 */
	protected function parse_csv_data( $data ) {
		$lines = str_getcsv( $data, "\n" );
		if ( empty( $lines ) ) {
			return new WP_Error( 'empty_csv', __( 'CSV file is empty', 'mcp-ai-wpoos-pro' ) );
		}

		$headers = str_getcsv( array_shift( $lines ) );
		$events  = array();

		foreach ( $lines as $line ) {
			if ( empty( trim( $line ) ) ) {
				continue;
			}

			$values = str_getcsv( $line );
			if ( count( $values ) !== count( $headers ) ) {
				continue;
			}

			$event    = array_combine( $headers, $values );
			$events[] = $this->normalize_event_data( $event );
		}

		return $events;
	}

	/**
	 * Parse JSON event data.
	 *
	 * @param string $data JSON data.
	 * @return array|WP_Error Parsed events or error.
	 */
	protected function parse_json_data( $data ) {
		$events = json_decode( $data, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'invalid_json', __( 'Invalid JSON format', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! is_array( $events ) ) {
			return new WP_Error( 'invalid_json_structure', __( 'JSON must be an array of events', 'mcp-ai-wpoos-pro' ) );
		}

		return array_map( array( $this, 'normalize_event_data' ), $events );
	}

	/**
	 * Normalize event data to standard format.
	 *
	 * @param array $event Raw event data.
	 * @return array Normalized event data.
	 */
	protected function normalize_event_data( $event ) {
		// Map iCalendar fields to standard fields.
		$field_map = array(
			'summary'     => 'title',
			'event_name'  => 'title',
			'event_title' => 'title',
			'start'       => 'dtstart',
			'start_date'  => 'dtstart',
			'start_time'  => 'dtstart',
			'end'         => 'dtend',
			'end_date'    => 'dtend',
			'end_time'    => 'dtend',
			'desc'        => 'description',
			'place'       => 'location',
			'venue'       => 'location',
		);

		$normalized = array();
		foreach ( $event as $key => $value ) {
			$key_lower                 = strtolower( trim( $key ) );
			$mapped_key                = isset( $field_map[ $key_lower ] ) ? $field_map[ $key_lower ] : $key_lower;
			$normalized[ $mapped_key ] = trim( $value );
		}

		// Generate UID if not present.
		if ( empty( $normalized['uid'] ) ) {
			$normalized['uid'] = uniqid( 'event-', true ) . '@' . wp_parse_url( home_url(), PHP_URL_HOST );
		}

		// Set DTSTAMP if not present (RFC 5545 requirement).
		if ( empty( $normalized['dtstamp'] ) ) {
			$normalized['dtstamp'] = gmdate( 'Ymd\THis\Z' );
		}

		// Convert dates to ISO 8601 if needed.
		if ( ! empty( $normalized['dtstart'] ) ) {
			$normalized['dtstart'] = $this->normalize_datetime( $normalized['dtstart'] );
		}
		if ( ! empty( $normalized['dtend'] ) ) {
			$normalized['dtend'] = $this->normalize_datetime( $normalized['dtend'] );
		}

		return $normalized;
	}

	/**
	 * Normalize datetime to ISO 8601 format.
	 *
	 * @param string $datetime Input datetime string.
	 * @return string Normalized datetime.
	 */
	protected function normalize_datetime( $datetime ) {
		// Already in iCalendar format (YYYYMMDDTHHMMSS).
		if ( preg_match( '/^\d{8}T\d{6}/', $datetime ) ) {
			return $datetime;
		}

		// Try to parse and convert.
		$timestamp = strtotime( $datetime );
		if ( false !== $timestamp ) {
			return gmdate( 'Ymd\THis\Z', $timestamp );
		}

		return $datetime; // Return as-is if we can't parse.
	}

	/**
	 * Validate item data before saving.
	 *
	 * @param array $item_data Item data to validate.
	 * @return true|WP_Error True if valid, WP_Error if validation fails.
	 */
	protected function validate_item_data( $item_data ) {
		$schema = $this->get_validation_schema();

		// Check required fields.
		foreach ( $schema['required_fields'] as $field => $label ) {
			if ( empty( $item_data[ $field ] ) ) {
				return new WP_Error(
					'missing_required_field',
					sprintf(
						/* translators: %s: Field label */
						__( 'Required field missing: %s', 'mcp-ai-wpoos-pro' ),
						$label
					)
				);
			}
		}

		// Validate UID uniqueness.
		if ( ! empty( $item_data['uid'] ) ) {
			$existing = get_posts(
				array(
					'post_type'   => 'mcp_ai_event',
					'meta_key'    => 'event_uid',
					'meta_value'  => $item_data['uid'],
					'post_status' => 'any',
					'fields'      => 'ids',
				)
			);

			if ( ! empty( $existing ) ) {
				return new WP_Error(
					'duplicate_uid',
					sprintf(
						/* translators: %s: UID value */
						__( 'UID already exists: %s', 'mcp-ai-wpoos-pro' ),
						$item_data['uid']
					)
				);
			}
		}

		// Validate date formats.
		if ( ! empty( $item_data['dtstart'] ) ) {
			if ( false === strtotime( $item_data['dtstart'] ) && ! preg_match( '/^\d{8}T\d{6}/', $item_data['dtstart'] ) ) {
				return new WP_Error( 'invalid_dtstart', __( 'Invalid start date/time format', 'mcp-ai-wpoos-pro' ) );
			}
		}

		// Validate end date is after start date.
		if ( ! empty( $item_data['dtstart'] ) && ! empty( $item_data['dtend'] ) ) {
			$start = strtotime( $item_data['dtstart'] );
			$end   = strtotime( $item_data['dtend'] );

			if ( false !== $start && false !== $end && $end < $start ) {
				return new WP_Error( 'invalid_date_range', __( 'End date/time must be after start date/time', 'mcp-ai-wpoos-pro' ) );
			}
		}

		return true;
	}

	/**
	 * Calculate event data completeness.
	 *
	 * @return array Completeness data.
	 */
	protected function calculate_completeness() {
		$args = array(
			'post_type'      => 'mcp_ai_event',
			'posts_per_page' => 10,
			'post_status'    => 'any',
		);

		$events       = get_posts( $args );
		$total_events = count( $events );

		if ( 0 === $total_events ) {
			return array(
				'percentage' => 0,
				'missing'    => array( __( 'No events found. Start by importing or adding events.', 'mcp-ai-wpoos-pro' ) ),
			);
		}

		$schema             = $this->get_validation_schema();
		$required_fields    = array_keys( $schema['required_fields'] );
		$recommended_fields = array_keys( $schema['recommended_fields'] );
		$total_fields       = count( $required_fields ) + count( $recommended_fields );

		$filled_count  = 0;
		$missing_items = array();

		foreach ( $events as $event ) {
			$event_filled = 0;

			// Check required fields.
			if ( get_post_meta( $event->ID, 'event_uid', true ) ) {
				++$event_filled;
			}
			if ( ! empty( $event->post_title ) ) {
				++$event_filled;
			}
			if ( get_post_meta( $event->ID, 'event_start_date', true ) ) {
				++$event_filled;
			}
			++$event_filled; // DTSTAMP is auto-generated.

			// Check recommended fields.
			if ( get_post_meta( $event->ID, 'event_end_date', true ) ) {
				++$event_filled;
			}
			if ( get_post_meta( $event->ID, 'event_location', true ) ) {
				++$event_filled;
			}
			if ( ! empty( $event->post_content ) ) {
				++$event_filled;
			}
			if ( get_post_meta( $event->ID, 'event_organizer', true ) ) {
				++$event_filled;
			}
			if ( get_post_meta( $event->ID, 'event_url', true ) ) {
				++$event_filled;
			}

			$filled_count += $event_filled;
		}

		$average_filled = $total_events > 0 ? $filled_count / $total_events : 0;
		$percentage     = round( ( $average_filled / $total_fields ) * 100 );

		if ( $percentage < 80 ) {
			$missing_items[] = sprintf(
				/* translators: %d: Current percentage */
				__( 'Event data completeness is %d%%. Consider adding more details to improve quality.', 'mcp-ai-wpoos-pro' ),
				$percentage
			);
		}

		return array(
			'percentage' => $percentage,
			'missing'    => $missing_items,
		);
	}

	/**
	 * Render event-specific form fields.
	 */
	protected function render_entity_form_fields() {
		?>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="event_title"><?php esc_html_e( 'Event Title', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
				</th>
				<td>
					<input type="text" id="event_title" name="item_data[title]" class="regular-text" required>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="event_start"><?php esc_html_e( 'Start Date/Time', 'mcp-ai-wpoos-pro' ); ?> <span class="required">*</span></label>
				</th>
				<td>
					<input type="datetime-local" id="event_start" name="item_data[dtstart]" class="regular-text" required>
					<p class="description"><?php esc_html_e( 'RFC 5545 compliant format', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="event_end"><?php esc_html_e( 'End Date/Time', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<input type="datetime-local" id="event_end" name="item_data[dtend]" class="regular-text">
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="event_location"><?php esc_html_e( 'Location', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<input type="text" id="event_location" name="item_data[location]" class="regular-text">
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="event_description"><?php esc_html_e( 'Description', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<textarea id="event_description" name="item_data[description]" rows="5" class="large-text"></textarea>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="event_organizer"><?php esc_html_e( 'Organizer', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<input type="text" id="event_organizer" name="item_data[organizer]" class="regular-text">
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="event_url"><?php esc_html_e( 'Event URL', 'mcp-ai-wpoos-pro' ); ?></label>
				</th>
				<td>
					<input type="url" id="event_url" name="item_data[url]" class="regular-text">
				</td>
			</tr>
		</table>
		<?php
	}
}
