<?php
/**
 * RabbitMQ Client for WP oOS.
 *
 * Provides message queue integration for agentic workflows and tool management
 * when deployed on Cloudways with RabbitMQ enabled.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RabbitMQ Client class.
 *
 * Handles connection management, message publishing, and queue operations
 * for RabbitMQ integration with WP oOS agentic workflows.
 */
class WP_MCP_AI_RabbitMQ_Client {

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_RabbitMQ_Client|null
	 */
	private static $instance = null;

	/**
	 * AMQP connection.
	 *
	 * @var AMQPConnection|null
	 */
	private $connection = null;

	/**
	 * AMQP channel.
	 *
	 * @var AMQPChannel|null
	 */
	private $channel = null;

	/**
	 * Configuration array.
	 *
	 * @var array
	 */
	private $config = array();

	/**
	 * Whether the client is available (extension loaded and configured).
	 *
	 * @var bool|null
	 */
	private $available = null;

	/**
	 * Exchange definitions.
	 *
	 * @var array
	 */
	const EXCHANGES = array(
		'tools'      => array(
			'name'    => 'wp_mcp_ai.tools',
			'type'    => 'direct',
			'durable' => true,
		),
		'chat'       => array(
			'name'    => 'wp_mcp_ai.chat',
			'type'    => 'topic',
			'durable' => true,
		),
		'deadletter' => array(
			'name'    => 'wp_mcp_ai.deadletter',
			'type'    => 'fanout',
			'durable' => true,
		),
		'analytics'  => array(
			'name'    => 'wp_mcp_ai.analytics',
			'type'    => 'fanout',
			'durable' => true,
		),
	);

	/**
	 * Queue definitions.
	 *
	 * @var array
	 */
	const QUEUES = array(
		'tool.execution'               => array(
			'exchange'    => 'tools',
			'routing_key' => 'execute.normal',
			'durable'     => true,
			'arguments'   => array(
				'x-message-ttl'             => 300000, // 5 minutes.
				'x-dead-letter-exchange'    => 'wp_mcp_ai.deadletter',
				'x-dead-letter-routing-key' => 'failed',
			),
		),
		'tool.execution.priority.high' => array(
			'exchange'    => 'tools',
			'routing_key' => 'execute.high',
			'durable'     => true,
			'arguments'   => array(
				'x-message-ttl'             => 30000, // 30 seconds.
				'x-dead-letter-exchange'    => 'wp_mcp_ai.deadletter',
				'x-dead-letter-routing-key' => 'failed',
				'x-max-priority'            => 10,
			),
		),
		'tool.execution.async'         => array(
			'exchange'    => 'tools',
			'routing_key' => 'execute.async',
			'durable'     => true,
			'arguments'   => array(
				'x-message-ttl'             => 3600000, // 1 hour.
				'x-dead-letter-exchange'    => 'wp_mcp_ai.deadletter',
				'x-dead-letter-routing-key' => 'failed',
			),
		),
		'tool.results'                 => array(
			'exchange'    => 'tools',
			'routing_key' => 'results',
			'durable'     => true,
			'arguments'   => array(
				'x-message-ttl' => 600000, // 10 minutes.
			),
		),
		'agentic.workflow'             => array(
			'exchange'    => 'chat',
			'routing_key' => 'workflow.#',
			'durable'     => true,
			'arguments'   => array(
				'x-message-ttl' => 1800000, // 30 minutes.
			),
		),
		'deadletter.queue'             => array(
			'exchange'    => 'deadletter',
			'routing_key' => '#',
			'durable'     => true,
			'arguments'   => array(
				'x-message-ttl' => 86400000, // 24 hours.
			),
		),
	);

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_RabbitMQ_Client
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor for singleton pattern.
	 */
	private function __construct() {
		$this->load_config();
	}

	/**
	 * Load configuration from settings or constants.
	 */
	private function load_config() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		$this->config = array(
			'enabled'  => $this->get_config_value( 'rabbitmq_enabled', $settings, false ),
			'host'     => $this->get_config_value( 'rabbitmq_host', $settings, 'localhost' ),
			'port'     => (int) $this->get_config_value( 'rabbitmq_port', $settings, 5672 ),
			'username' => $this->get_config_value( 'rabbitmq_username', $settings, 'guest' ),
			'password' => $this->get_config_value( 'rabbitmq_password', $settings, 'guest' ),
			'vhost'    => $this->get_config_value( 'rabbitmq_vhost', $settings, '/' ),
			'prefix'   => $this->get_config_value( 'rabbitmq_queue_prefix', $settings, 'wp_mcp_ai' ),
		);
	}

	/**
	 * Get configuration value from constant or settings.
	 *
	 * @param string $key      Setting key.
	 * @param array  $settings Settings array.
	 * @param mixed  $default  Default value.
	 * @return mixed Configuration value.
	 */
	private function get_config_value( $key, $settings, $default ) {
		// Check for constant first (uppercase, with prefix).
		$constant_name = 'WP_MCP_AI_' . strtoupper( $key );
		if ( defined( $constant_name ) ) {
			return constant( $constant_name );
		}

		// Then check settings.
		if ( isset( $settings[ $key ] ) && '' !== $settings[ $key ] ) {
			return $settings[ $key ];
		}

		return $default;
	}

	/**
	 * Check if RabbitMQ integration is available.
	 *
	 * @return bool Whether RabbitMQ is available.
	 */
	public function is_available() {
		if ( null !== $this->available ) {
			return $this->available;
		}

		// Check if enabled in settings.
		if ( ! $this->config['enabled'] ) {
			$this->available = false;
			return false;
		}

		// Check if AMQP extension is loaded.
		if ( ! extension_loaded( 'amqp' ) ) {
			$this->available = false;
			return false;
		}

		// Try to establish connection.
		try {
			$this->connect();
			$this->available = true;
		} catch ( Exception $e ) {
			WP_MCP_AI_Logger::log_error(
				'RabbitMQ connection failed',
				array(
					'error' => $e->getMessage(),
					'host'  => $this->config['host'],
					'port'  => $this->config['port'],
				)
			);
			$this->available = false;
		}

		return $this->available;
	}

	/**
	 * Establish connection to RabbitMQ.
	 *
	 * @throws Exception If connection fails.
	 */
	public function connect() {
		if ( null !== $this->connection && $this->connection->isConnected() ) {
			return;
		}

		if ( ! extension_loaded( 'amqp' ) ) {
			throw new Exception( 'AMQP extension not loaded' );
		}

		$connection_params = array(
			'host'            => $this->config['host'],
			'port'            => $this->config['port'],
			'login'           => $this->config['username'],
			'password'        => $this->config['password'],
			'vhost'           => $this->config['vhost'],
			'connect_timeout' => 5,
			'read_timeout'    => 30,
			'write_timeout'   => 30,
		);

		$this->connection = new AMQPConnection( $connection_params );
		$this->connection->connect();

		$this->channel = new AMQPChannel( $this->connection );

		// Set QoS (prefetch count).
		$this->channel->qos( 0, 1 );
	}

	/**
	 * Disconnect from RabbitMQ.
	 */
	public function disconnect() {
		if ( null !== $this->channel ) {
			$this->channel = null;
		}

		if ( null !== $this->connection && $this->connection->isConnected() ) {
			$this->connection->disconnect();
		}

		$this->connection = null;
	}

	/**
	 * Get the AMQP channel.
	 *
	 * @return AMQPChannel
	 * @throws Exception If not connected.
	 */
	public function get_channel() {
		if ( null === $this->channel ) {
			$this->connect();
		}
		return $this->channel;
	}

	/**
	 * Declare exchanges and queues.
	 *
	 * @throws Exception If declaration fails.
	 */
	public function setup_infrastructure() {
		$channel = $this->get_channel();

		// Declare exchanges.
		foreach ( self::EXCHANGES as $key => $config ) {
			$exchange = new AMQPExchange( $channel );
			$exchange->setName( $config['name'] );
			$exchange->setType( $config['type'] );

			$flags = AMQP_DURABLE;
			if ( isset( $config['durable'] ) && ! $config['durable'] ) {
				$flags = AMQP_NOPARAM;
			}
			$exchange->setFlags( $flags );
			$exchange->declareExchange();
		}

		// Declare queues.
		foreach ( self::QUEUES as $name => $config ) {
			$queue = new AMQPQueue( $channel );
			$queue->setName( $this->get_queue_name( $name ) );

			$flags = AMQP_DURABLE;
			if ( isset( $config['durable'] ) && ! $config['durable'] ) {
				$flags = AMQP_NOPARAM;
			}
			$queue->setFlags( $flags );

			if ( isset( $config['arguments'] ) ) {
				$queue->setArguments( $config['arguments'] );
			}

			$queue->declareQueue();

			// Bind to exchange.
			$exchange_name = self::EXCHANGES[ $config['exchange'] ]['name'];
			$queue->bind( $exchange_name, $config['routing_key'] );
		}

		WP_MCP_AI_Logger::log_event(
			'rabbitmq_setup',
			'RabbitMQ infrastructure setup complete',
			array(
				'exchanges' => count( self::EXCHANGES ),
				'queues'    => count( self::QUEUES ),
			)
		);
	}

	/**
	 * Get full queue name with prefix.
	 *
	 * @param string $queue_name Base queue name.
	 * @return string Full queue name.
	 */
	private function get_queue_name( $queue_name ) {
		return $this->config['prefix'] . '.' . $queue_name;
	}

	/**
	 * Publish a message to an exchange.
	 *
	 * @param string $exchange_key Exchange key from EXCHANGES constant.
	 * @param string $routing_key  Routing key for the message.
	 * @param array  $message      Message payload.
	 * @param array  $properties   Optional message properties.
	 * @return bool Whether publish succeeded.
	 */
	public function publish( $exchange_key, $routing_key, array $message, array $properties = array() ) {
		if ( ! $this->is_available() ) {
			return false;
		}

		try {
			$channel  = $this->get_channel();
			$exchange = new AMQPExchange( $channel );
			$exchange->setName( self::EXCHANGES[ $exchange_key ]['name'] );

			// Add metadata to message.
			$message['_metadata'] = array(
				'timestamp'  => microtime( true ),
				'message_id' => wp_generate_uuid4(),
				'site_id'    => get_current_blog_id(),
			);

			$body = wp_json_encode( $message );

			$default_properties = array(
				'content_type'  => 'application/json',
				'delivery_mode' => 2, // Persistent.
			);

			$properties = wp_parse_args( $properties, $default_properties );

			$exchange->publish( $body, $routing_key, AMQP_NOPARAM, $properties );

			WP_MCP_AI_Logger::log_event(
				'rabbitmq_publish',
				'Message published',
				array(
					'exchange'    => $exchange_key,
					'routing_key' => $routing_key,
					'message_id'  => $message['_metadata']['message_id'],
				)
			);

			return true;

		} catch ( Exception $e ) {
			WP_MCP_AI_Logger::log_error(
				'RabbitMQ publish failed',
				array(
					'exchange' => $exchange_key,
					'error'    => $e->getMessage(),
				)
			);
			return false;
		}
	}

	/**
	 * Publish a tool execution request.
	 *
	 * @param string $tool_name Tool slug.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @param string $priority  Priority level: 'high', 'normal', 'async'.
	 * @return string|false Job ID or false on failure.
	 */
	public function queue_tool_execution( $tool_name, array $arguments, array $context, $priority = 'normal' ) {
		$job_id = wp_generate_uuid4();

		$message = array(
			'job_id'       => $job_id,
			'tool_name'    => sanitize_key( $tool_name ),
			'arguments'    => $arguments,
			'context'      => $context,
			'priority'     => $priority,
			'created_at'   => current_time( 'mysql' ),
			'user_id'      => isset( $context['user_id'] ) ? $context['user_id'] : get_current_user_id(),
			'assistant_id' => isset( $context['assistant_id'] ) ? $context['assistant_id'] : 0,
		);

		// Determine routing key based on priority.
		$routing_keys = array(
			'high'   => 'execute.high',
			'normal' => 'execute.normal',
			'async'  => 'execute.async',
		);

		$routing_key = isset( $routing_keys[ $priority ] ) ? $routing_keys[ $priority ] : 'execute.normal';

		$properties = array();
		if ( 'high' === $priority ) {
			$properties['priority'] = 10;
		}

		$success = $this->publish( 'tools', $routing_key, $message, $properties );

		if ( $success ) {
			// Store job reference in transient for result retrieval.
			set_transient( 'wp_mcp_ai_job_' . $job_id, $message, 3600 );
			return $job_id;
		}

		return false;
	}

	/**
	 * Check if a job has completed and get its result.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null Result array or null if not ready.
	 */
	public function get_job_result( $job_id ) {
		$result = get_transient( 'wp_mcp_ai_job_result_' . $job_id );

		if ( false !== $result ) {
			// Clean up transients.
			delete_transient( 'wp_mcp_ai_job_' . $job_id );
			delete_transient( 'wp_mcp_ai_job_result_' . $job_id );
			return $result;
		}

		return null;
	}

	/**
	 * Store a job result.
	 *
	 * @param string $job_id Job ID.
	 * @param mixed  $result Job result.
	 * @param string $status Status: 'success', 'error', 'timeout'.
	 */
	public function store_job_result( $job_id, $result, $status = 'success' ) {
		$result_data = array(
			'job_id'       => $job_id,
			'result'       => $result,
			'status'       => $status,
			'completed_at' => current_time( 'mysql' ),
		);

		set_transient( 'wp_mcp_ai_job_result_' . $job_id, $result_data, 3600 );

		// Also publish to results queue for SSE updates.
		$this->publish( 'tools', 'results', $result_data );
	}

	/**
	 * Get queue statistics.
	 *
	 * @return array Queue statistics.
	 */
	public function get_queue_stats() {
		if ( ! $this->is_available() ) {
			return array(
				'available' => false,
				'error'     => 'RabbitMQ not available',
			);
		}

		$stats = array(
			'available' => true,
			'queues'    => array(),
		);

		try {
			$channel = $this->get_channel();

			foreach ( self::QUEUES as $name => $config ) {
				$queue = new AMQPQueue( $channel );
				$queue->setName( $this->get_queue_name( $name ) );

				// This is a passive declare to get queue info.
				try {
					$queue->setFlags( AMQP_PASSIVE );
					$queue->declareQueue();

					$stats['queues'][ $name ] = array(
						'messages'  => method_exists( $queue, 'getLength' ) ? $queue->getLength() : 0,
						'consumers' => 0, // Would require management API for accurate count.
					);
				} catch ( Exception $e ) {
					$stats['queues'][ $name ] = array(
						'error' => 'Queue not found',
					);
				}
			}
		} catch ( Exception $e ) {
			$stats['error'] = $e->getMessage();
		}

		return $stats;
	}

	/**
	 * Health check for RabbitMQ connection.
	 *
	 * @return array Health status.
	 */
	public function health_check() {
		$status = array(
			'status'     => 'unknown',
			'connection' => array(
				'connected' => false,
				'host'      => $this->config['host'],
				'port'      => $this->config['port'],
				'vhost'     => $this->config['vhost'],
			),
			'extension'  => extension_loaded( 'amqp' ),
			'enabled'    => $this->config['enabled'],
		);

		if ( ! $this->config['enabled'] ) {
			$status['status'] = 'disabled';
			return $status;
		}

		if ( ! extension_loaded( 'amqp' ) ) {
			$status['status'] = 'extension_missing';
			return $status;
		}

		try {
			$this->connect();
			$status['connection']['connected'] = true;
			$status['status']                  = 'healthy';
			$status['queues']                  = $this->get_queue_stats();
		} catch ( Exception $e ) {
			$status['status'] = 'connection_failed';
			$status['error']  = $e->getMessage();
		}

		return $status;
	}

	/**
	 * Destructor - ensure connection is closed.
	 */
	public function __destruct() {
		$this->disconnect();
	}
}
