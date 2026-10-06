<?php
/**
 * WP-CLI `mcp-ai rabbitmq` command for NV oOS.
 *
 * Extracted from includes/class-wp-mcp-ai-cli-command.php
 * (Proposal 050, WP-CLI parity & hardening).
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/class-wp-mcp-ai-cli-base-command.php';

if ( ! class_exists( 'WP_MCP_AI_CLI_RabbitMQ_Command' ) ) {
	class WP_MCP_AI_CLI_RabbitMQ_Command extends WP_MCP_AI_CLI_Base_Command {

		/**
		 * Display RabbitMQ connection and queue status.
		 *
		 * ## OPTIONS
		 *
		 * [--format=<format>]
		 * : Output format.
		 * ---
		 * default: table
		 * options:
		 *   - table
		 *   - json
		 *   - yaml
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *     wp mcp-ai rabbitmq status
		 *
		 * @subcommand status
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 * @when after_wp_load
		 */
		public function status( $args, $assoc_args ) {
			$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

			if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
				WP_CLI::error( 'RabbitMQ client not available. Ensure the class file is loaded.' );
				return;
			}

			$client = WP_MCP_AI_RabbitMQ_Client::get_instance();
			$health = $client->health_check();

			$items = array(
				array(
					'Property' => 'Status',
					'Value'    => $health['status'],
				),
				array(
					'Property' => 'AMQP Extension',
					'Value'    => $health['extension'] ? 'Loaded' : 'Not Loaded',
				),
				array(
					'Property' => 'Enabled',
					'Value'    => $health['enabled'] ? 'Yes' : 'No',
				),
				array(
					'Property' => 'Host',
					'Value'    => $health['connection']['host'],
				),
				array(
					'Property' => 'Port',
					'Value'    => $health['connection']['port'],
				),
				array(
					'Property' => 'Virtual Host',
					'Value'    => $health['connection']['vhost'],
				),
				array(
					'Property' => 'Connected',
					'Value'    => $health['connection']['connected'] ? 'Yes' : 'No',
				),
			);

			if ( isset( $health['error'] ) ) {
				$items[] = array(
					'Property' => 'Error',
					'Value'    => $health['error'],
				);
			}

			\WP_CLI\Utils\format_items( $format, $items, array( 'Property', 'Value' ) );

			if ( 'healthy' === $health['status'] ) {
				WP_CLI::success( 'RabbitMQ connection is healthy.' );
			} elseif ( 'disabled' === $health['status'] ) {
				WP_CLI::warning( 'RabbitMQ integration is disabled. Enable it in Settings → NV oOS → RabbitMQ.' );
			} else {
				WP_CLI::error( 'RabbitMQ connection failed. Check your configuration.' );
			}
		}

		/**
		 * Test RabbitMQ connection.
		 *
		 * ## EXAMPLES
		 *
		 *     wp mcp-ai rabbitmq test-connection
		 *
		 * @subcommand test-connection
		 * @when after_wp_load
		 */
		public function test_connection() {
			if ( ! extension_loaded( 'amqp' ) ) {
				WP_CLI::error( 'PHP AMQP extension is not loaded. Enable RabbitMQ on your Cloudways server.' );
				return;
			}

			if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
				WP_CLI::error( 'RabbitMQ client not available.' );
				return;
			}

			WP_CLI::log( 'Testing RabbitMQ connection...' );

			try {
				$client = WP_MCP_AI_RabbitMQ_Client::get_instance();

				if ( ! $client->is_available() ) {
					WP_CLI::error( 'RabbitMQ is not available. Check your settings.' );
					return;
				}

				$client->connect();
				WP_CLI::success( 'Successfully connected to RabbitMQ!' );

			} catch ( Exception $e ) {
				WP_CLI::error( 'Connection failed: ' . $e->getMessage() );
			}
		}

		/**
		 * Set up RabbitMQ exchanges and queues.
		 *
		 * Creates the required exchanges and queues for NV oOS tool execution.
		 *
		 * ## EXAMPLES
		 *
		 *     wp mcp-ai rabbitmq setup
		 *
		 * @subcommand setup
		 * @when after_wp_load
		 */
		public function setup() {
			if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
				WP_CLI::error( 'RabbitMQ client not available.' );
				return;
			}

			$this->require_capability( 'manage_options' );

			WP_CLI::log( 'Setting up RabbitMQ infrastructure...' );

			try {
				$client = WP_MCP_AI_RabbitMQ_Client::get_instance();
				$client->setup_infrastructure();
				WP_CLI::success( 'RabbitMQ infrastructure setup complete!' );

			} catch ( Exception $e ) {
				WP_CLI::error( 'Setup failed: ' . $e->getMessage() );
			}
		}

		/**
		 * List RabbitMQ queues and their status.
		 *
		 * ## OPTIONS
		 *
		 * [--format=<format>]
		 * : Output format.
		 * ---
		 * default: table
		 * options:
		 *   - table
		 *   - json
		 *   - yaml
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *     wp mcp-ai rabbitmq list-queues
		 *
		 * @subcommand list-queues
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 * @when after_wp_load
		 */
		public function list_queues( $args, $assoc_args ) {
			$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

			if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
				WP_CLI::error( 'RabbitMQ client not available.' );
				return;
			}

			$client = WP_MCP_AI_RabbitMQ_Client::get_instance();
			$stats  = $client->get_queue_stats();

			if ( ! $stats['available'] ) {
				WP_CLI::error( 'RabbitMQ is not available: ' . ( $stats['error'] ?? 'Unknown error' ) );
				return;
			}

			$items = array();
			foreach ( $stats['queues'] as $name => $queue_data ) {
				if ( isset( $queue_data['error'] ) ) {
					$items[] = array(
						'Queue'     => $name,
						'Messages'  => 'N/A',
						'Consumers' => 'N/A',
						'Status'    => $queue_data['error'],
					);
				} else {
					$items[] = array(
						'Queue'     => $name,
						'Messages'  => $queue_data['messages'],
						'Consumers' => $queue_data['consumers'],
						'Status'    => 'OK',
					);
				}
			}

			\WP_CLI\Utils\format_items( $format, $items, array( 'Queue', 'Messages', 'Consumers', 'Status' ) );
		}

		/**
		 * Publish a test message to verify queue functionality.
		 *
		 * ## EXAMPLES
		 *
		 *     wp mcp-ai rabbitmq send-test-message
		 *
		 * @subcommand send-test-message
		 * @when after_wp_load
		 */
		public function send_test_message() {
			if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
				WP_CLI::error( 'RabbitMQ client not available.' );
				return;
			}

			$client = WP_MCP_AI_RabbitMQ_Client::get_instance();

			if ( ! $client->is_available() ) {
				WP_CLI::error( 'RabbitMQ is not available.' );
				return;
			}

			$this->require_capability( 'manage_options' );

			WP_CLI::log( 'Sending test message...' );

			$test_message = array(
				'type'      => 'test',
				'timestamp' => current_time( 'mysql' ),
				'source'    => 'wp-cli',
			);

			$success = $client->publish( 'tools', 'test', $test_message );

			if ( $success ) {
				WP_CLI::success( 'Test message sent successfully!' );
			} else {
				WP_CLI::error( 'Failed to send test message.' );
			}
		}

		/**
		 * Start a tool execution worker.
		 *
		 * This command runs a continuous loop consuming messages from the tool execution queue.
		 *
		 * ## OPTIONS
		 *
		 * [--queue=<queue>]
		 * : Queue to consume from.
		 * ---
		 * default: tool.execution
		 * options:
		 *   - tool.execution
		 *   - tool.execution.priority.high
		 *   - tool.execution.async
		 * ---
		 *
		 * [--max-jobs=<num>]
		 * : Maximum number of jobs to process before exiting. 0 for unlimited.
		 * ---
		 * default: 0
		 * ---
		 *
		 * [--timeout=<seconds>]
		 * : Maximum time in seconds for each job.
		 * ---
		 * default: 300
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *     # Start a worker for normal priority tools
		 *     wp mcp-ai rabbitmq worker
		 *
		 *     # Start a worker for high priority tools, process 100 jobs then exit
		 *     wp mcp-ai rabbitmq worker --queue=tool.execution.priority.high --max-jobs=100
		 *
		 * @subcommand worker
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 * @when after_wp_load
		 */
		public function worker( $args, $assoc_args ) {
			$queue    = \WP_CLI\Utils\get_flag_value( $assoc_args, 'queue', 'tool.execution' );
			$max_jobs = absint( \WP_CLI\Utils\get_flag_value( $assoc_args, 'max-jobs', 0 ) );
			$timeout  = absint( \WP_CLI\Utils\get_flag_value( $assoc_args, 'timeout', 300 ) );

			if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
				WP_CLI::error( 'RabbitMQ client not available.' );
				return;
			}

			$client = WP_MCP_AI_RabbitMQ_Client::get_instance();

			if ( ! $client->is_available() ) {
				WP_CLI::error( 'RabbitMQ is not available.' );
				return;
			}

			$this->require_capability( 'manage_options' );

			WP_CLI::log( sprintf( 'Starting worker for queue: %s', $queue ) );
			WP_CLI::log( 'Press Ctrl+C to stop.' );
			WP_CLI::log( '' );

			$jobs_processed = 0;
			$registry       = WP_MCP_AI_Tool_Registry::get_instance();

			// TODO: Implement actual message consumption loop when AMQPQueue::consume is available.
			// This is a placeholder showing the intended structure.

			WP_CLI::warning(
				'Worker mode requires the php-amqp extension with consumer support. ' .
				'This is a placeholder implementation. Full worker functionality will be added when RabbitMQ is enabled on your Cloudways server.'
			);

			WP_CLI::success( sprintf( 'Worker stopped. Processed %d jobs.', $jobs_processed ) );
		}
	}

}

if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai rabbitmq', 'WP_MCP_AI_CLI_RabbitMQ_Command' );
}
