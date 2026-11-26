<?php
/**
 * License validation resolver.
 *
 * The checkout addon issues a license on `/verify` and stores it in
 * `{prefix}nvoos_checkout_licenses`. This class answers the other half of the
 * contract: "is this key still good for this site?", which the WordPress Pro
 * plugin (`/api/licenses/check|activate`) and the Laravel runtime
 * (`LicensedProEntitlement`) both ask.
 *
 * ## Policy (industry practice for license validation)
 *
 * - **Opaque keys only.** Nothing is encoded in the key; the stored row is the
 *   single source of truth, so a key never has to be reissued to change a plan
 *   or an expiry.
 * - **Instance binding.** A key is issued to one `site_url`. Validation
 *   compares the requested site with the stored one (scheme, path, query and
 *   port are ignored; host case is folded) and never re-binds on a mismatch —
 *   that is what stops one key being shared across installs.
 * - **Uniform answers.** Unknown, revoked, wrong-product and wrong-site keys
 *   all return the same `{status:invalid}` body and HTTP 200. A 404 or a
 *   stack-trace-shaped error would turn the endpoint into a key oracle.
 * - **Nothing secret in the response.** No row ids, payment intents, buyer
 *   data or database errors ever leave the server.
 * - **Plan is a server decision.** The table records the product; the plan
 *   returned to the client comes from `plan_for_product()` so a commercial
 *   re-tiering does not need a schema change or a reissue.
 *
 * @package NV_oOS_Checkout_API
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve a license key against the checkout license store.
 *
 * @since 0.2.0
 */
class NVOOS_Checkout_API_License_Resolver {

	/**
	 * Requested product => product actually stored at issue time.
	 *
	 * The WordPress Pro plugin and the Laravel runtime ask with the internal
	 * product slug (`mcp-ai-wpoos-pro`); the checkout sells the bundle as
	 * `nvoos-oos-complete`. Both name the same entitlement, so validation
	 * treats them as the same product. A product that is not listed here and
	 * not equal to the stored one is a mismatch.
	 *
	 * @var array<string,string>
	 */
	public const PRODUCT_ALIASES = array(
		'mcp-ai-wpoos-pro' => 'nvoos-oos-complete',
	);

	/**
	 * Resolve a key for one site.
	 *
	 * @since 0.2.0
	 *
	 * @param string $license_key Key presented by the client.
	 * @param string $site_url    Site URL or stable instance id.
	 * @param string $product     Product slug the client is licensed for.
	 * @return array{status:string,plan?:string,product?:string,checked_at?:string}
	 */
	public static function resolve( string $license_key, string $site_url, string $product ): array {
		$key = strtolower( trim( $license_key ) );

		if ( '' === $key || '' === trim( $site_url ) ) {
			return self::invalid();
		}

		$row = NVOOS_Checkout_API_License_Store::get_by_key( $key );

		if ( ! is_array( $row ) ) {
			return self::invalid();
		}

		if ( NVOOS_Checkout_API_License_Store::STATUS_ACTIVE !== (string) ( $row['status'] ?? '' ) ) {
			return self::invalid();
		}

		$stored_product = (string) ( $row['product'] ?? '' );

		if ( ! self::product_matches( $product, $stored_product ) ) {
			return self::invalid();
		}

		if ( ! self::sites_match( $site_url, (string) ( $row['site_url'] ?? '' ) ) ) {
			return self::invalid();
		}

		/**
		 * Filter the plan returned for a valid license.
		 *
		 * @since 0.2.0
		 *
		 * @param string               $plan    Plan slug from `ProPlanCatalog`.
		 * @param string               $product Stored product slug.
		 * @param array<string,mixed>  $row     Full license row.
		 */
		$plan = (string) apply_filters( 'nvoos_checkout_license_plan', self::plan_for_product( $stored_product ), $stored_product, $row );

		return array(
			'status'     => 'valid',
			'plan'       => $plan,
			'product'    => $stored_product,
			'checked_at' => gmdate( 'c' ),
		);
	}

	/**
	 * The plan a product is sold under.
	 *
	 * Kept as one method so a re-tiering touches one place: the checkout does
	 * not store a plan column, and the client's `ProPlanCatalog` is the
	 * authority on what a plan unlocks. `filter` above can override per license
	 * without a deploy.
	 *
	 * @since 0.2.0
	 *
	 * @param string $product Stored product slug.
	 * @return string
	 */
	public static function plan_for_product( string $product ): string {
		$product = strtolower( trim( $product ) );

		if ( 'nvoos-content-graph-ai' === $product ) {
			return 'compliance';
		}

		return 'professional';
	}

	/**
	 * Does the requested product name the stored entitlement?
	 *
	 * @since 0.2.0
	 *
	 * @param string $requested Product the client asked about.
	 * @param string $stored    Product recorded on the license.
	 * @return bool
	 */
	private static function product_matches( string $requested, string $stored ): bool {
		$requested = strtolower( trim( $requested ) );
		$stored    = strtolower( trim( $stored ) );

		if ( '' === $requested || '' === $stored ) {
			return false;
		}

		if ( $requested === $stored ) {
			return true;
		}

		$forward = self::PRODUCT_ALIASES[ $requested ] ?? '';
		if ( '' !== $forward && $forward === $stored ) {
			return true;
		}

		$reverse = self::PRODUCT_ALIASES[ $stored ] ?? '';

		return '' !== $reverse && $reverse === $requested;
	}

	/**
	 * Do the two site identifiers name the same installation?
	 *
	 * Accepts a URL (`https://estate.nvoos.cloud/`) or a bare instance id
	 * (`estate-nvoos-cloud`) because the Laravel runtime sends the latter.
	 * Scheme, path, query, fragment and port are dropped and the host is
	 * lower-cased, so `https://Site.example/` and `site.example` match while
	 * `www.site.example` does not (a subdomain is a different installation).
	 *
	 * @since 0.2.0
	 *
	 * @param string $requested Site presented by the client.
	 * @param string $stored    Site recorded on the license.
	 * @return bool
	 */
	private static function sites_match( string $requested, string $stored ): bool {
		$requested = self::normalise_site( $requested );
		$stored    = self::normalise_site( $stored );

		return '' !== $requested && '' !== $stored && $requested === $stored;
	}

	/**
	 * Reduce a site URL or instance id to its comparable host.
	 *
	 * @since 0.2.0
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function normalise_site( string $value ): string {
		$value = strtolower( trim( $value ) );

		if ( '' === $value ) {
			return '';
		}

		// Drop a scheme, then everything from the first path/query/fragment.
		$value = (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $value );
		$value = (string) preg_replace( '#[/?\#].*$#', '', $value );
		// Drop a port: TLS termination in front of the app is the norm and the
		// binding is per host, not per ephemeral port.
		$value = (string) preg_replace( '#:\d+$#', '', $value );

		return rtrim( $value, '.' );
	}

	/**
	 * The one negative answer every failure mode returns.
	 *
	 * @since 0.2.0
	 *
	 * @return array{status:string}
	 */
	private static function invalid(): array {
		return array( 'status' => 'invalid' );
	}
}
