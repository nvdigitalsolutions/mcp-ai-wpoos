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
	public const DB_VERSION     = '4';
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
			buyer_email VARCHAR(255) NOT NULL DEFAULT '',
			buyer_country CHAR(2) NOT NULL DEFAULT '',
			terms_agreed_at DATETIME NULL DEFAULT NULL,
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

		// NULL (not '') for the DATETIME column when consent was never
		// recorded — the webhook issuance path has no browser consent.
		$terms_agreed_at = (string) ( $data['terms_agreed_at'] ?? '' );
		$terms_agreed_at = '' !== $terms_agreed_at ? $terms_agreed_at : null;

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
				'buyer_email'           => (string) ( $data['buyer_email'] ?? '' ),
				'buyer_country'         => (string) ( $data['buyer_country'] ?? '' ),
				'terms_agreed_at'       => $terms_agreed_at,
				'status'                => self::STATUS_ACTIVE,
				'created_at'            => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
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

		$table = self::table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from a class constant; values are prepared.
				"SELECT * FROM {$table} WHERE license_key = %s LIMIT 1",
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

		$table = self::table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from a class constant; values are prepared.
				"SELECT * FROM {$table} WHERE stripe_payment_intent = %s LIMIT 1",
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
	 * Record the buyer's Terms-of-Service consent timestamp on a license.
	 *
	 * Only fills an empty value — a consent timestamp, once recorded, is
	 * never overwritten. Used by /verify when the license was already
	 * issued by the payment webhook before the browser completed its flow.
	 *
	 * @param string $license_key      License key.
	 * @param string $terms_agreed_at  GMT MySQL datetime (Y-m-d H:i:s).
	 * @return bool True when a row was updated.
	 */
	public static function set_terms_agreed( string $license_key, string $terms_agreed_at ): bool {
		global $wpdb;

		// The column only ever holds NULL (no consent yet) or a valid
		// datetime, so IS NULL alone gates the fill — comparing against ''
		// trips MySQL 8 strict mode ("Incorrect DATETIME value").
		$table   = self::table_name();
		$updated = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from a class constant; values are prepared.
				"UPDATE {$table} SET terms_agreed_at = %s WHERE license_key = %s AND terms_agreed_at IS NULL",
				$terms_agreed_at,
				$license_key
			)
		);

		return false !== $updated;
	}

	/**
	 * Record the buyer's receipt/refund email on a license.
	 *
	 * Only fills an empty value — an email, once recorded, is never
	 * overwritten. Used by /verify when the license was already issued by
	 * the payment webhook before the browser completed its flow.
	 *
	 * @param string $license_key License key.
	 * @param string $email       Sanitized buyer email.
	 * @return bool True when a row was updated.
	 */
	public static function set_buyer_email( string $license_key, string $email ): bool {
		global $wpdb;

		$table   = self::table_name();
		$updated = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from a class constant; values are prepared.
				"UPDATE {$table} SET buyer_email = %s WHERE license_key = %s AND buyer_email = ''",
				$email,
				$license_key
			)
		);

		return false !== $updated;
	}

	/**
	 * Record the buyer's country code on a license (VAT records).
	 *
	 * Fill-once, like the email — the first country the buyer declares at
	 * purchase is the one kept for VAT evidence.
	 *
	 * @param string $license_key License key.
	 * @param string $country     ISO 3166-1 alpha-2 country code.
	 * @return bool True when a row was updated.
	 */
	public static function set_buyer_country( string $license_key, string $country ): bool {
		global $wpdb;

		$table   = self::table_name();
		$updated = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from a class constant; values are prepared.
				"UPDATE {$table} SET buyer_country = %s WHERE license_key = %s AND buyer_country = ''",
				$country,
				$license_key
			)
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

		$table = self::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from a class constant; values are prepared.
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d",
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

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a class constant; no user input in this query.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}
