<?php
/**
 * CRM Web Form Listener
 *
 * Hooks into common WordPress form plugins and routes submissions to the
 * CRM inbound pipeline (extract_lead_from_message).
 *
 * @package WP_MCP_AI_Pro
 * @since  2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Web form submission listener for the CRM toolkit.
 *
 * Listens for form submissions from popular plugins (Gravity Forms,
 * Contact Form 7, WPForms) and native WordPress comment/registration
 * forms, then feeds extracted contact data into the CRM lead pipeline.
 *
 * @todo Wire Gravity Forms via gform_after_submission.
 * @todo Wire Contact Form 7 via wpcf7_before_send_mail.
 * @todo Wire WPForms via wpforms_process_complete.
 * @todo Wire native WordPress registration via user_register.
 * @todo Map form fields to lead schema (email, first_name, last_name, phone, source).
 */
class WP_MCP_AI_CRM_Web_Form_Listener {

	/**
	 * Register form submission hooks.
	 */
	public static function init() {
		// Gravity Forms.
		if ( class_exists( 'GFAPI' ) ) {
			add_action( 'gform_after_submission', array( __CLASS__, 'handle_gravity_forms' ), 10, 2 );
		}

		// Contact Form 7.
		if ( defined( 'WPCF7_VERSION' ) ) {
			add_action( 'wpcf7_before_send_mail', array( __CLASS__, 'handle_cf7' ) );
		}

		// WPForms.
		if ( function_exists( 'wpforms' ) ) {
			add_action( 'wpforms_process_complete', array( __CLASS__, 'handle_wpforms' ), 10, 4 );
		}
	}

	/**
	 * Handle a Gravity Forms submission.
	 *
	 * @param array $entry GF entry.
	 * @param array $form  GF form config.
	 */
	public static function handle_gravity_forms( $entry, $form ) {
		$lead_data = self::extract_fields_from_entry( $entry );
		if ( empty( $lead_data['email'] ) ) {
			return;
		}
		self::route_to_crm( $lead_data, 'web_form', 'gravity_forms' );
	}

	/**
	 * Handle a Contact Form 7 submission.
	 *
	 * @param WPCF7_ContactForm $contact_form CF7 form object.
	 */
	public static function handle_cf7( $contact_form ) {
		$submission = WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return;
		}
		$posted    = $submission->get_posted_data();
		$lead_data = self::extract_fields_from_posted( $posted );
		if ( empty( $lead_data['email'] ) ) {
			return;
		}
		self::route_to_crm( $lead_data, 'web_form', 'cf7' );
	}

	/**
	 * Handle a WPForms submission.
	 *
	 * @param array $fields    Form fields.
	 * @param array $entry     Submitted data.
	 * @param array $form_data Form config.
	 * @param int   $entry_id  Entry ID.
	 */
	public static function handle_wpforms( $fields, $entry, $form_data, $entry_id ) {
		$lead_data = self::extract_fields_from_entry( $entry );
		if ( empty( $lead_data['email'] ) ) {
			return;
		}
		self::route_to_crm( $lead_data, 'web_form', 'wpforms' );
	}

	/**
	 * Extract lead fields from a submitted entry.
	 *
	 * @param array $entry Form submission data.
	 * @return array{email?:string, first_name?:string, last_name?:string, phone?:string}
	 */
	private static function extract_fields_from_entry( $entry ) {
		$lead = array(
			'email'      => '',
			'first_name' => '',
			'last_name'  => '',
			'phone'      => '',
		);

		foreach ( $entry as $key => $value ) {
			$lower = strtolower( $key );
			if ( false !== strpos( $lower, 'email' ) || is_email( $value ) ) {
				$lead['email'] = sanitize_email( $value );
			} elseif ( false !== strpos( $lower, 'first' ) || 'name' === $lower ) {
				$lead['first_name'] = sanitize_text_field( $value );
			} elseif ( false !== strpos( $lower, 'last' ) || 'surname' === $lower ) {
				$lead['last_name'] = sanitize_text_field( $value );
			} elseif ( false !== strpos( $lower, 'phone' ) || false !== strpos( $lower, 'tel' ) ) {
				$lead['phone'] = sanitize_text_field( $value );
			}
		}

		return $lead;
	}

	/**
	 * Extract lead fields from CF7 posted data.
	 *
	 * @param array $posted CF7 posted data.
	 * @return array
	 */
	private static function extract_fields_from_posted( $posted ) {
		return self::extract_fields_from_entry( $posted );
	}

	/**
	 * Route extracted lead data to the CRM pipeline.
	 *
	 * Calls extract_lead_from_message to match-or-create a lead record.
	 *
	 * @param array  $lead_data Extracted lead fields.
	 * @param string $channel   Source channel ('web_form').
	 * @param string $source    Form plugin identifier.
	 */
	private static function route_to_crm( $lead_data, $channel, $source ) {
		$_tool_file = WP_MCP_AI_PRO_PATH . 'includes/tools/crm/inbound/class-wp-mcp-ai-tool-extract-lead-from-message.php';
		if ( ! file_exists( $_tool_file ) ) {
			return;
		}
		require_once $_tool_file;

		if ( ! class_exists( 'WP_MCP_AI_Tool_Extract_Lead_From_Message' ) ) {
			return;
		}

		$arguments = array(
			'channel'      => $channel,
			'message_body' => wp_json_encode( $lead_data ),
			'sender_name'  => trim( $lead_data['first_name'] . ' ' . $lead_data['last_name'] ),
			'sender_email' => $lead_data['email'],
			'sender_phone' => $lead_data['phone'],
			'source'       => $source,
		);

		$tool    = new WP_MCP_AI_Tool_Extract_Lead_From_Message();
		$context = array( 'user_id' => 0 );
		$tool->execute( $arguments, $context );
	}
}
