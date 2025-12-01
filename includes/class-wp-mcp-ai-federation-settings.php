<?php
/**
 * Federation settings management.
 *
 * Handles settings UI and configuration for the federation features.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages federation-related settings.
 */
class WP_MCP_AI_Federation_Settings {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ), 20 );
	}

	/**
	 * Register federation settings.
	 */
	public function register_settings() {
		// Federation section.
		add_settings_section(
			'wp_mcp_ai_federation_section',
			__( 'Federation & Discovery', 'wp-mcp-ai' ),
			array( $this, 'render_federation_section_description' ),
			WP_MCP_AI_Admin_Settings::PAGE_SLUG
		);

		add_settings_field(
			'enable_federation',
			__( 'Enable Federation', 'wp-mcp-ai' ),
			array( $this, 'render_enable_federation_field' ),
			WP_MCP_AI_Admin_Settings::PAGE_SLUG,
			'wp_mcp_ai_federation_section'
		);

		add_settings_field(
			'enable_federation_directory',
			__( 'Enable Directory Service', 'wp-mcp-ai' ),
			array( $this, 'render_enable_directory_field' ),
			WP_MCP_AI_Admin_Settings::PAGE_SLUG,
			'wp_mcp_ai_federation_section'
		);

		add_settings_field(
			'federation_regions',
			__( 'Regions', 'wp-mcp-ai' ),
			array( $this, 'render_regions_field' ),
			WP_MCP_AI_Admin_Settings::PAGE_SLUG,
			'wp_mcp_ai_federation_section'
		);

		add_settings_field(
			'federation_data_tags',
			__( 'Data Tags', 'wp-mcp-ai' ),
			array( $this, 'render_data_tags_field' ),
			WP_MCP_AI_Admin_Settings::PAGE_SLUG,
			'wp_mcp_ai_federation_section'
		);

		add_settings_field(
			'federation_qps',
			__( 'Queries Per Second (QPS)', 'wp-mcp-ai' ),
			array( $this, 'render_qps_field' ),
			WP_MCP_AI_Admin_Settings::PAGE_SLUG,
			'wp_mcp_ai_federation_section'
		);

		add_settings_field(
			'federation_burst',
			__( 'Burst Limit', 'wp-mcp-ai' ),
			array( $this, 'render_burst_field' ),
			WP_MCP_AI_Admin_Settings::PAGE_SLUG,
			'wp_mcp_ai_federation_section'
		);
	}

	/**
	 * Render federation section description.
	 */
	public function render_federation_section_description() {
		?>
		<p><?php esc_html_e( 'Configure federation settings to publish this site\'s capabilities and participate in the AI peer network.', 'wp-mcp-ai' ); ?></p>
		<p class="description">
			<?php
			printf(
				/* translators: %s: well-known URL */
				esc_html__( 'When enabled, your site will publish capabilities at: %s', 'wp-mcp-ai' ),
				'<code>' . esc_html( trailingslashit( get_site_url() ) . '.well-known/ai-peer' ) . '</code>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render enable federation checkbox.
	 */
	public function render_enable_federation_field() {
		$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$enabled  = isset( $settings['enable_federation'] ) ? (bool) $settings['enable_federation'] : false;
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( WP_MCP_AI_Admin_Settings::OPTION_NAME ); ?>[enable_federation]"
				value="1"
				<?php checked( $enabled ); ?>
			/>
			<?php esc_html_e( 'Enable federation well-known endpoints', 'wp-mcp-ai' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Publishes /.well-known/ai-peer and /.well-known/jwks.json endpoints for peer discovery.', 'wp-mcp-ai' ); ?>
		</p>
		<?php
	}

	/**
	 * Render enable directory checkbox.
	 */
	public function render_enable_directory_field() {
		$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$enabled  = isset( $settings['enable_federation_directory'] ) ? (bool) $settings['enable_federation_directory'] : false;
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( WP_MCP_AI_Admin_Settings::OPTION_NAME ); ?>[enable_federation_directory]"
				value="1"
				<?php checked( $enabled ); ?>
			/>
			<?php esc_html_e( 'Enable directory service', 'wp-mcp-ai' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Allows this site to act as a directory service for registering and discovering other AI peers.', 'wp-mcp-ai' ); ?>
		</p>
		<?php
		if ( $enabled ) :
			?>
			<p class="description" style="margin-top: 10px;">
				<?php
				printf(
					/* translators: %s: directory API URL */
					esc_html__( 'Directory API available at: %s', 'wp-mcp-ai' ),
					'<code>' . esc_html( rest_url( 'ai-dir/v1' ) ) . '</code>'
				);
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render regions field.
	 */
	public function render_regions_field() {
		$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$regions  = isset( $settings['federation_regions'] ) ? $settings['federation_regions'] : 'global';

		// Convert array to comma-separated string if needed.
		if ( is_array( $regions ) ) {
			$regions = implode( ', ', $regions );
		}
		?>
		<input
			type="text"
			name="<?php echo esc_attr( WP_MCP_AI_Admin_Settings::OPTION_NAME ); ?>[federation_regions]"
			value="<?php echo esc_attr( $regions ); ?>"
			class="regular-text"
			placeholder="us, eu, ap, global"
		/>
		<p class="description">
			<?php esc_html_e( 'Comma-separated list of regions this site operates in (e.g., us, eu, ap, global).', 'wp-mcp-ai' ); ?>
		</p>
		<?php
	}

	/**
	 * Render data tags field.
	 */
	public function render_data_tags_field() {
		$settings  = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$data_tags = isset( $settings['federation_data_tags'] ) ? $settings['federation_data_tags'] : '';

		// Convert array to comma-separated string if needed.
		if ( is_array( $data_tags ) ) {
			$data_tags = implode( ', ', $data_tags );
		}
		?>
		<input
			type="text"
			name="<?php echo esc_attr( WP_MCP_AI_Admin_Settings::OPTION_NAME ); ?>[federation_data_tags]"
			value="<?php echo esc_attr( $data_tags ); ?>"
			class="regular-text"
			placeholder="no_pii, gdpr_ok, hipaa_like"
		/>
		<p class="description">
			<?php esc_html_e( 'Comma-separated list of data handling tags (e.g., no_pii, gdpr_ok, hipaa_like).', 'wp-mcp-ai' ); ?>
		</p>
		<?php
	}

	/**
	 * Render QPS field.
	 */
	public function render_qps_field() {
		$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$qps      = isset( $settings['federation_qps'] ) ? absint( $settings['federation_qps'] ) : 5;
		?>
		<input
			type="number"
			name="<?php echo esc_attr( WP_MCP_AI_Admin_Settings::OPTION_NAME ); ?>[federation_qps]"
			value="<?php echo esc_attr( $qps ); ?>"
			min="1"
			max="1000"
			class="small-text"
		/>
		<p class="description">
			<?php esc_html_e( 'Maximum queries per second this site can handle from peers.', 'wp-mcp-ai' ); ?>
		</p>
		<?php
	}

	/**
	 * Render burst field.
	 */
	public function render_burst_field() {
		$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$burst    = isset( $settings['federation_burst'] ) ? absint( $settings['federation_burst'] ) : 10;
		?>
		<input
			type="number"
			name="<?php echo esc_attr( WP_MCP_AI_Admin_Settings::OPTION_NAME ); ?>[federation_burst]"
			value="<?php echo esc_attr( $burst ); ?>"
			min="1"
			max="1000"
			class="small-text"
		/>
		<p class="description">
			<?php esc_html_e( 'Maximum burst capacity for handling simultaneous requests.', 'wp-mcp-ai' ); ?>
		</p>
		<?php
	}

	/**
	 * Get federation settings with defaults.
	 *
	 * @return array Federation settings.
	 */
	public static function get_settings() {
		$all_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

		$defaults = array(
			'enable_federation'           => false,
			'enable_federation_directory' => false,
			'federation_regions'          => array( 'global' ),
			'federation_data_tags'        => array(),
			'federation_qps'              => 5,
			'federation_burst'            => 10,
			'federation_jwks_keys'        => array(),
			'federation_price_hints'      => array(),
		);

		$federation_settings = array();
		foreach ( $defaults as $key => $default_value ) {
			if ( isset( $all_settings[ $key ] ) ) {
				$federation_settings[ $key ] = $all_settings[ $key ];
			} else {
				$federation_settings[ $key ] = $default_value;
			}
		}

		// Ensure regions is an array.
		if ( is_string( $federation_settings['federation_regions'] ) ) {
			$federation_settings['federation_regions'] = array_map(
				'trim',
				explode( ',', $federation_settings['federation_regions'] )
			);
		}

		// Ensure data_tags is an array.
		if ( is_string( $federation_settings['federation_data_tags'] ) ) {
			$federation_settings['federation_data_tags'] = array_filter(
				array_map(
					'trim',
					explode( ',', $federation_settings['federation_data_tags'] )
				)
			);
		}

		return $federation_settings;
	}

	/**
	 * Check if federation is enabled.
	 *
	 * @return bool True if federation is enabled.
	 */
	public static function is_federation_enabled() {
		$settings = self::get_settings();
		return ! empty( $settings['enable_federation'] );
	}

	/**
	 * Check if directory service is enabled.
	 *
	 * @return bool True if directory service is enabled.
	 */
	public static function is_directory_enabled() {
		$settings = self::get_settings();
		return ! empty( $settings['enable_federation_directory'] );
	}
}
