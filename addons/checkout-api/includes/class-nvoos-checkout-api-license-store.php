<?php
/**
 * License persistence (custom table).
 *
 * @package NV_oOS_Checkout_API
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * License store.
 *
 * Licenses are plugin-owned records (issued per successful Stripe payment)
 * with their own lifecycle (active → revoked), so they live in a custom
 * table rather than wp_options. All queries use $wpdb->prepare().
 *
 * @since 0.1.0
 */
class NVOOS_Checkout_API_License_Store {

	public const TABLE_NAME     = 'nvoos_checkout_licenses';
	public const DB_VERSION     = '1';
	public const DB_VERSION_KEY = 'nvoos_checkout_licenses_db_version';

	public const STATUS_ACTIVE  = 'active';
	public const STATUS_REVOKED = 'revoked';

	/**
	 * Fully prefixed table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Create or upgrade the licenses table (dbDelta).
	 *
	 * @return void
	 */
	public static function install_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			license_key VARCHAR(64) NOT NULL,
			product VARCHAR(64) NOT NULL,
			site_url VARCHAR(255) NOT NULL,
			stripe_payment_intent VARCHAR(64) NOT NULL,
			stripe_customer VARCHAR(64) NOT NULL DEFAULT '',
			amount INT(11) NOT NULL DEFAULT 0,
			currency CHAR(3) NOT NULL DEFAULT 'usd',
			addon_version VARCHAR(16) NOT NULL DEFAULT '',
			status VARCHAR(16) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY license_key (license_key),
			UNIQUE KEY payment_intent (stripe_payment_intent),
			KEY status (status)
		) {$charset};";

		dbDelta( $sql );

		update_option( self::DB_VERSION_KEY, self::DB_VERSION, false );
	}

	/**
	 * Create a license record.
	 *
	 * @param array<string,mixed> $data License fields.
	 * @return array<string,mixed>|WP_Error Stored row (with id) or error.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'license_key'           => (string) ( $data['license_key'] ?? '' ),
				'product'               => (string) ( $data['product'] ?? '' ),
				'site_url'              => (string) ( $data['site_url'] ?? '' ),
				'stripe_payment_intent' => (string) ( $data['stripe_payment_intent'] ?? '' ),
				'stripe_customer'       => (string) ( $data['stripe_customer'] ?? '' ),
				'amount'                => (int) ( $data['amount'] ?? 0 ),
				'currency'              => (string) ( $data['currency'] ?? 'usd' ),
				'addon_version'         => (string) ( $data['addon_version'] ?? '' ),
				'status'                => self::STATUS_ACTIVE,
				'created_at'            => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'nvoos_checkout_license_insert_failed', __( 'Could not store the license.', 'nvoos-checkout-api' ) );
		}

		$data['id']         = (int) $wpdb->insert_id;
		$data['status']     = self::STATUS_ACTIVE;
		$data['created_at'] = $now;
		return $data;
	}

	/**
	 * Fetch a license by its key.
	 *
	 * @param string $license_key License key.
	 * @return array<string,mixed>|null
	 */
	public static function get_by_key( string $license_key ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE license_key = %s LIMIT 1',
				$license_key
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Fetch a license by its Stripe payment intent.
	 *
	 * @param string $payment_intent Stripe PaymentIntent ID.
	 * @return array<string,mixed>|null
	 */
	public static function get_by_payment_intent( string $payment_intent ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE stripe_payment_intent = %s LIMIT 1',
				$payment_intent
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Revoke a license (refund/dispute handling).
	 *
	 * @param string $license_key License key.
	 * @return bool True when a row was updated.
	 */
	public static function revoke( string $license_key ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			self::table_name(),
			array( 'status' => self::STATUS_REVOKED ),
			array( 'license_key' => $license_key ),
			array( '%s' ),
			array( '%s' )
		);

		return false !== $updated;
	}

	/**
	 * The most recent licenses (admin table).
	 *
	 * @param int $limit Row limit.
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent( int $limit = 50 ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' ORDER BY id DESC LIMIT %d',
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Total license count.
	 *
	 * @return int
	 */
	public static function count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table_name() );
	}
}
