<?php
/**
 * Tool for sending group emails (Validated version).
 *
 * This is the Symfony Validator version of the send_group_email tool.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../validators/class-wp-mcp-ai-validated-tool.php';
require_once __DIR__ . '/../validators/arguments/class-send-group-email-arguments.php';
require_once __DIR__ . '/class-wp-mcp-ai-tool-send-group-email.php';

/**
 * Sends group emails using Symfony Validator.
 *
 * This class extends the original send_group_email tool to use
 * Symfony Validator for argument validation.
 */
class WP_MCP_AI_Tool_Send_Group_Email_Validated extends WP_MCP_AI_Validated_Tool implements WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * The original send_group_email tool instance for delegation.
	 *
	 * @var WP_MCP_AI_Tool_Send_Group_Email
	 */
	protected $original_tool;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->original_tool = new WP_MCP_AI_Tool_Send_Group_Email();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'send_group_email_validated';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send Group Email (Validated)', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends an email to recipients using the WordPress mailer with Symfony Validator for argument validation. Email content can be provided directly or loaded from attachment files.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return $this->original_tool->get_parameters_schema();
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_validation_class() {
		return \WP_MCP_AI\Tools\Arguments\SendGroupEmailArguments::class;
	}

	/**
	 * Execute the tool with validated arguments.
	 *
	 * @param \WP_MCP_AI\Tools\Arguments\SendGroupEmailArguments $validated_args Validated arguments object.
	 * @param array                                              $context        Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	protected function execute_validated( $validated_args, $context ) {
		// Convert validated args object to array format expected by original tool.
		$arguments = array();

		if ( null !== $validated_args->subject ) {
			$arguments['subject'] = $validated_args->subject;
		}

		if ( null !== $validated_args->message ) {
			$arguments['message'] = $validated_args->message;
		}

		if ( null !== $validated_args->recipients ) {
			$arguments['recipients'] = $validated_args->recipients;
		}

		if ( null !== $validated_args->attachment_id ) {
			$arguments['attachment_id'] = $validated_args->attachment_id;
		}

		if ( null !== $validated_args->file_id ) {
			$arguments['file_id'] = $validated_args->file_id;
		}

		if ( null !== $validated_args->url ) {
			$arguments['url'] = $validated_args->url;
		}

		if ( null !== $validated_args->attachment_ids ) {
			$arguments['attachment_ids'] = $validated_args->attachment_ids;
		}

		if ( null !== $validated_args->from_email ) {
			$arguments['from_email'] = $validated_args->from_email;
		}

		if ( null !== $validated_args->from_name ) {
			$arguments['from_name'] = $validated_args->from_name;
		}

		if ( null !== $validated_args->headers ) {
			$arguments['headers'] = $validated_args->headers;
		}

		// Delegate to the original tool.
		return $this->original_tool->execute( $arguments, $context );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return $this->original_tool->get_capability_flags();
	}
}
