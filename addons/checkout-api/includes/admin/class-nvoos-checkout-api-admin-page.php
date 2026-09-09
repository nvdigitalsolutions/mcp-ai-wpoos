<?php
/**
 * Admin page: storefront settings + license table.
 *
 * @package NV_oOS_Checkout_API
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vendor-side admin page.
 *
 * Two sections:
 *   1. Storefront settings — Stripe keys, price, currency, test mode,
 *      addon version, ZIP source. Served via the Settings API.
 *   2. Licenses — recent rows with a per-row revoke action (admin-post,
 *      nonce-protected).
 *
 * @since 0.1.0
 */
class NVOOS_Checkout_API_Admin_Page {

	public const MENU_SLUG        = 'nvoos-checkout';
	public const NONCE_REVOKE     = 'nvoos_checkout_revoke_license';
	public const NONCE_CREATE_PRODUCT = 'nvoos_checkout_create_product';
	public const ACTION_CREATE_PRODUCT = 'nvoos_checkout_create_product';

	/**
	 * Register menu + settings.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_nvoos_checkout_revoke', array( __CLASS__, 'handle_revoke' ) );
		add_action( 'admin_post_' . self::ACTION_CREATE_PRODUCT, array( __CLASS__, 'handle_create_product' ) );
	}

	/**
	 * Add the top-level menu page.
	 *
	 * @return void
	 */
	public static function add_menu(): void {
		add_menu_page(
			__( 'NV oOS Checkout', 'nvoos-checkout-api' ),
			__( 'NV oOS Checkout', 'nvoos-checkout-api' ),
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-cart',
			86
		);
	}

	/**
	 * Register the settings group and fields.
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			'nvoos_checkout_settings_group',
			NVOOS_Checkout_API_Settings::OPTION,
			array( 'sanitize_callback' => array( 'NVOOS_Checkout_API_Settings', 'sanitize' ) )
		);
	}

	/**
	 * Handle the admin-post license revocation.
	 *
	 * @return void
	 */
	public static function handle_revoke(): void {
		check_admin_referer( self::NONCE_REVOKE );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'nvoos-checkout-api' ) );
		}

		$license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
		if ( '' !== $license_key ) {
			NVOOS_Checkout_API_License_Store::revoke( $license_key );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&revoked=1' ) );
		exit;
	}

	/**
	 * Handle the admin-post Stripe Product/Price creation.
	 *
	 * Idempotent: creates the Product only when no product_id is stored,
	 * and the one-time Price only when no price_id is stored. The IDs are
	 * recorded in settings and then attached to every PaymentIntent as
	 * metadata (reporting/tax only — charging behavior is unchanged).
	 *
	 * @return void
	 */
	public static function handle_create_product(): void {
		check_admin_referer( self::NONCE_CREATE_PRODUCT );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'nvoos-checkout-api' ) );
		}

		$secret = NVOOS_Checkout_API_Settings::stripe_secret_key();
		if ( '' === $secret ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&stripe_product=error&reason=unconfigured' ) );
			exit;
		}

		$client = new NVOOS_Checkout_API_Stripe_Client( $secret );

		$product_id = NVOOS_Checkout_API_Settings::product_id();
		if ( '' === $product_id ) {
			$product = $client->create_product( NVOOS_Checkout_API_Settings::product_name() );
			if ( is_wp_error( $product ) || empty( $product['id'] ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&stripe_product=error&reason=product' ) );
				exit;
			}
			$product_id = (string) $product['id'];
			NVOOS_Checkout_API_Settings::update_field( 'product_id', $product_id );
		}

		if ( '' === NVOOS_Checkout_API_Settings::price_id() ) {
			$price = $client->create_price(
				$product_id,
				NVOOS_Checkout_API_Settings::price_cents(),
				NVOOS_Checkout_API_Settings::currency()
			);
			if ( is_wp_error( $price ) || empty( $price['id'] ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&stripe_product=error&reason=price' ) );
				exit;
			}
			NVOOS_Checkout_API_Settings::update_field( 'price_id', (string) $price['id'] );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&stripe_product=created' ) );
		exit;
	}

	/**
	 * Render the admin page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'nvoos-checkout-api' ) );
		}

		$settings = NVOOS_Checkout_API_Settings::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'NV oOS Checkout', 'nvoos-checkout-api' ); ?></h1>

			<?php settings_errors(); ?>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- flag read only. ?>
			<?php if ( isset( $_GET['revoked'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'License revoked.', 'nvoos-checkout-api' ); ?></p></div>
			<?php endif; ?>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- flag read only. ?>
			<?php if ( isset( $_GET['stripe_product'] ) ) : ?>
				<?php if ( 'created' === $_GET['stripe_product'] ) : ?>
					<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Stripe product and price created. They are now recorded on every payment.', 'nvoos-checkout-api' ); ?></p></div>
				<?php else : ?>
					<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not create the Stripe product/price. Check the secret key and try again.', 'nvoos-checkout-api' ); ?></p></div>
				<?php endif; ?>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Storefront Settings', 'nvoos-checkout-api' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'nvoos_checkout_settings_group' ); ?>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row"><label for="nvoos-checkout-secret"><?php esc_html_e( 'Stripe secret key', 'nvoos-checkout-api' ); ?></label></th>
							<td>
								<input type="password" id="nvoos-checkout-secret" name="<?php echo esc_attr( NVOOS_Checkout_API_Settings::OPTION ); ?>[stripe_secret_key]" value="<?php echo esc_attr( NVOOS_Checkout_API_Settings::stripe_secret_key() ); ?>" class="regular-text" autocomplete="new-password">
								<p class="description"><?php esc_html_e( 'sk_live_… or sk_test_…. Leave blank to keep the stored value. Stored encrypted at rest; never exposed via any endpoint.', 'nvoos-checkout-api' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="nvoos-checkout-publishable"><?php esc_html_e( 'Stripe publishable key', 'nvoos-checkout-api' ); ?></label></th>
							<td>
								<input type="text" id="nvoos-checkout-publishable" name="<?php echo esc_attr( NVOOS_Checkout_API_Settings::OPTION ); ?>[stripe_publishable_key]" value="<?php echo esc_attr( NVOOS_Checkout_API_Settings::stripe_publishable_key() ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'pk_live_… or pk_test_…. Sent to customer sites with each payment session.', 'nvoos-checkout-api' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="nvoos-checkout-whsecret"><?php esc_html_e( 'Stripe webhook secret', 'nvoos-checkout-api' ); ?></label></th>
							<td>
								<input type="password" id="nvoos-checkout-whsecret" name="<?php echo esc_attr( NVOOS_Checkout_API_Settings::OPTION ); ?>[stripe_webhook_secret]" value="<?php echo esc_attr( NVOOS_Checkout_API_Settings::stripe_webhook_secret() ); ?>" class="regular-text" autocomplete="new-password">
								<p class="description">
									<?php
									echo wp_kses(
										sprintf(
											/* translators: %s: webhook endpoint URL. */
											__( 'whsec_…. Point Stripe at %s with events: payment_intent.succeeded, charge.refunded, charge.dispute.created.', 'nvoos-checkout-api' ),
											'<code>' . esc_html( rest_url( NVOOS_Checkout_API_Rest_Controller::REST_NAMESPACE . '/webhooks/stripe' ) ) . '</code>'
										),
										array( 'code' => array() )
									);
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="nvoos-checkout-price"><?php esc_html_e( 'Price (cents)', 'nvoos-checkout-api' ); ?></label></th>
							<td>
								<input type="number" id="nvoos-checkout-price" name="<?php echo esc_attr( NVOOS_Checkout_API_Settings::OPTION ); ?>[price_cents]" value="<?php echo esc_attr( (string) $settings['price_cents'] ); ?>" class="small-text" min="50">
								<span class="description"><?php echo esc_html( sprintf( '= $%s', number_format( (int) $settings['price_cents'] / 100, 2 ) ) ); ?></span>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="nvoos-checkout-currency"><?php esc_html_e( 'Currency', 'nvoos-checkout-api' ); ?></label></th>
							<td>
								<input type="text" id="nvoos-checkout-currency" name="<?php echo esc_attr( NVOOS_Checkout_API_Settings::OPTION ); ?>[currency]" value="<?php echo esc_attr( $settings['currency'] ); ?>" class="small-text" maxlength="3">
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Test mode', 'nvoos-checkout-api' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( NVOOS_Checkout_API_Settings::OPTION ); ?>[test_mode]" value="1" <?php checked( (bool) $settings['test_mode'] ); ?>>
									<?php esc_html_e( 'Use Stripe test keys (a live secret key always runs live).', 'nvoos-checkout-api' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="nvoos-checkout-version"><?php esc_html_e( 'Addon version', 'nvoos-checkout-api' ); ?></label></th>
							<td>
								<input type="text" id="nvoos-checkout-version" name="<?php echo esc_attr( NVOOS_Checkout_API_Settings::OPTION ); ?>[addon_version]" value="<?php echo esc_attr( $settings['addon_version'] ); ?>" class="small-text">
								<p class="description"><?php esc_html_e( 'Version recorded on new licenses and used in the ZIP source pattern.', 'nvoos-checkout-api' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="nvoos-checkout-zipsource"><?php esc_html_e( 'ZIP source', 'nvoos-checkout-api' ); ?></label></th>
							<td>
								<input type="text" id="nvoos-checkout-zipsource" name="<?php echo esc_attr( NVOOS_Checkout_API_Settings::OPTION ); ?>[zip_source]" value="<?php echo esc_attr( $settings['zip_source'] ); ?>" class="large-text">
								<p class="description"><?php esc_html_e( 'https URL or absolute server path. Use {VERSION} as the version placeholder.', 'nvoos-checkout-api' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="nvoos-checkout-terms"><?php esc_html_e( 'Terms of Service URL', 'nvoos-checkout-api' ); ?></label></th>
							<td>
								<input type="text" id="nvoos-checkout-terms" name="<?php echo esc_attr( NVOOS_Checkout_API_Settings::OPTION ); ?>[terms_url]" value="<?php echo esc_attr( $settings['terms_url'] ); ?>" class="large-text">
								<p class="description"><?php esc_html_e( 'Shown next to the consent checkbox in the purchase modal. Leave blank to use the default.', 'nvoos-checkout-api' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="nvoos-checkout-refund"><?php esc_html_e( 'Refund Policy URL', 'nvoos-checkout-api' ); ?></label></th>
							<td>
								<input type="text" id="nvoos-checkout-refund" name="<?php echo esc_attr( NVOOS_Checkout_API_Settings::OPTION ); ?>[refund_policy_url]" value="<?php echo esc_attr( $settings['refund_policy_url'] ); ?>" class="large-text">
								<p class="description"><?php esc_html_e( 'Shown next to the consent checkbox in the purchase modal. Leave blank to use the default.', 'nvoos-checkout-api' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="nvoos-checkout-descriptor"><?php esc_html_e( 'Statement descriptor', 'nvoos-checkout-api' ); ?></label></th>
							<td>
								<input type="text" id="nvoos-checkout-descriptor" name="<?php echo esc_attr( NVOOS_Checkout_API_Settings::OPTION ); ?>[statement_descriptor]" value="<?php echo esc_attr( $settings['statement_descriptor'] ); ?>" class="regular-text" maxlength="22">
								<p class="description"><?php esc_html_e( 'Appears on buyers\' card statements (5–22 characters, e.g. NV OOS COMPLETE). Leave blank for the Stripe default.', 'nvoos-checkout-api' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="nvoos-checkout-productname"><?php esc_html_e( 'Stripe product name', 'nvoos-checkout-api' ); ?></label></th>
							<td>
								<input type="text" id="nvoos-checkout-productname" name="<?php echo esc_attr( NVOOS_Checkout_API_Settings::OPTION ); ?>[product_name]" value="<?php echo esc_attr( $settings['product_name'] ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'Used when creating the Stripe product below (type: service).', 'nvoos-checkout-api' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Stripe product & price', 'nvoos-checkout-api' ); ?></th>
							<td>
								<p>
									<?php esc_html_e( 'Product ID:', 'nvoos-checkout-api' ); ?>
									<code><?php echo esc_html( '' !== $settings['product_id'] ? $settings['product_id'] : '—' ); ?></code><br>
									<?php esc_html_e( 'Price ID:', 'nvoos-checkout-api' ); ?>
									<code><?php echo esc_html( '' !== $settings['price_id'] ? $settings['price_id'] : '—' ); ?></code>
								</p>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CREATE_PRODUCT ); ?>">
									<?php wp_nonce_field( self::NONCE_CREATE_PRODUCT ); ?>
									<?php submit_button( __( 'Create product & price in Stripe', 'nvoos-checkout-api' ), 'secondary', 'submit', false ); ?>
								</form>
								<p class="description"><?php esc_html_e( 'Creates a one-time price matching the configured price/currency and records both IDs on every payment for reporting and tax tooling. Safe to re-run.', 'nvoos-checkout-api' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Licenses', 'nvoos-checkout-api' ); ?></h2>
			<?php self::render_license_table(); ?>
		</div>
		<?php
	}

	/**
	 * Render the recent-licenses table.
	 *
	 * @return void
	 */
	private static function render_license_table(): void {
		$rows = NVOOS_Checkout_API_License_Store::recent( 50 );

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No licenses issued yet.', 'nvoos-checkout-api' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'License key', 'nvoos-checkout-api' ) . '</th>';
		echo '<th>' . esc_html__( 'Product', 'nvoos-checkout-api' ) . '</th>';
		echo '<th>' . esc_html__( 'Site', 'nvoos-checkout-api' ) . '</th>';
		echo '<th>' . esc_html__( 'Buyer email', 'nvoos-checkout-api' ) . '</th>';
		echo '<th>' . esc_html__( 'Country', 'nvoos-checkout-api' ) . '</th>';
		echo '<th>' . esc_html__( 'Amount', 'nvoos-checkout-api' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'nvoos-checkout-api' ) . '</th>';
		echo '<th>' . esc_html__( 'Terms agreed', 'nvoos-checkout-api' ) . '</th>';
		echo '<th>' . esc_html__( 'Issued', 'nvoos-checkout-api' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$revoked = NVOOS_Checkout_API_License_Store::STATUS_REVOKED === ( $row['status'] ?? '' );
			echo '<tr>';
			echo '<td><code>' . esc_html( $row['license_key'] ) . '</code></td>';
			echo '<td>' . esc_html( $row['product'] ) . '</td>';
			echo '<td>' . esc_html( $row['site_url'] ) . '</td>';
			echo '<td>' . ( empty( $row['buyer_email'] ) ? '—' : esc_html( $row['buyer_email'] ) ) . '</td>';
			echo '<td>' . ( empty( $row['buyer_country'] ) ? '—' : esc_html( $row['buyer_country'] ) ) . '</td>';
			echo '<td>' . esc_html( number_format( (int) $row['amount'] / 100, 2 ) . ' ' . strtoupper( (string) $row['currency'] ) ) . '</td>';
			echo '<td>' . esc_html( $row['status'] ) . '</td>';
			echo '<td>' . ( empty( $row['terms_agreed_at'] ) ? '—' : esc_html( $row['terms_agreed_at'] ) ) . '</td>';
			echo '<td>' . esc_html( $row['created_at'] ) . '</td>';
			echo '<td>';
			if ( ! $revoked ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
				echo '<input type="hidden" name="action" value="nvoos_checkout_revoke">';
				echo '<input type="hidden" name="license_key" value="' . esc_attr( (string) $row['license_key'] ) . '">';
				wp_nonce_field( self::NONCE_REVOKE );
				submit_button( __( 'Revoke', 'nvoos-checkout-api' ), 'small', 'submit', false );
				echo '</form>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}
}
