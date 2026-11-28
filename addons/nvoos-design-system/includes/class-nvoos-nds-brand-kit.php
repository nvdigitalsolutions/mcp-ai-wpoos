<?php
/**
 * NV oOS Design System — Brand Kit.
 *
 * Stores the brand identity that sits on top of the token registry. The kit
 * references tokens by id rather than duplicating values, so one token edit
 * updates the site, the block editor and every email.
 *
 * @package NV_oOS_Design_System
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brand identity store and exporter.
 *
 * @since 1.0.0
 */
class NV_oOS_Design_System_Brand_Kit {

	/**
	 * Option key (autoload disabled).
	 *
	 * @var string
	 */
	const OPTION_KEY = 'nvoos_nds_brand';

	/**
	 * Default brand fields.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'name'          => '',
			'tagline'       => '',
			'sub_brand'     => '',
			'logo_url'      => '',
			'logo_dark_url' => '',
			'icon_url'      => '',
			'favicon_url'   => '',
			'contact_email' => '',
			'voice'         => '',
			'social'        => array(),
		);
	}

	/**
	 * Read the stored brand kit.
	 *
	 * @return array<string, mixed>
	 */
	public static function get() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$brand = wp_parse_args( $stored, self::defaults() );

		if ( ! is_array( $brand['social'] ) ) {
			$brand['social'] = array();
		}

		return $brand;
	}

	/**
	 * Sanitise and persist the brand kit.
	 *
	 * @param array<string, mixed> $data Raw data.
	 * @return array<string, mixed> Saved brand.
	 */
	public static function save( $data ) {
		$data = is_array( $data ) ? $data : array();

		$social = array();
		if ( isset( $data['social'] ) ) {
			$raw = is_array( $data['social'] ) ? $data['social'] : preg_split( '/\r\n|\r|\n/', (string) $data['social'] );
			foreach ( (array) $raw as $url ) {
				$url = esc_url_raw( trim( (string) $url ) );
				if ( '' !== $url ) {
					$social[] = $url;
				}
			}
		}

		$brand = array(
			'name'          => sanitize_text_field( isset( $data['name'] ) ? $data['name'] : '' ),
			'tagline'       => sanitize_text_field( isset( $data['tagline'] ) ? $data['tagline'] : '' ),
			'sub_brand'     => sanitize_text_field( isset( $data['sub_brand'] ) ? $data['sub_brand'] : '' ),
			'logo_url'      => esc_url_raw( isset( $data['logo_url'] ) ? $data['logo_url'] : '' ),
			'logo_dark_url' => esc_url_raw( isset( $data['logo_dark_url'] ) ? $data['logo_dark_url'] : '' ),
			'icon_url'      => esc_url_raw( isset( $data['icon_url'] ) ? $data['icon_url'] : '' ),
			'favicon_url'   => esc_url_raw( isset( $data['favicon_url'] ) ? $data['favicon_url'] : '' ),
			'contact_email' => sanitize_email( isset( $data['contact_email'] ) ? $data['contact_email'] : '' ),
			'voice'         => sanitize_textarea_field( isset( $data['voice'] ) ? $data['voice'] : '' ),
			'social'        => array_values( array_unique( $social ) ),
		);

		update_option( self::OPTION_KEY, $brand, false );

		return $brand;
	}

	/**
	 * Export the brand kit as a portable document.
	 *
	 * @return array<string, mixed>
	 */
	public static function export() {
		$brand    = self::get();
		$exporter = new NV_oOS_Design_System_DTCG_Exporter( NV_oOS_Design_System_Plugin::token_registry() );

		return array(
			'$schema'        => 'https://nvdigitalsolutions.com/schemas/nvoos-brand-kit/v1.json',
			'format'         => 'nvoos-brand-kit/v1',
			'generated'      => gmdate( 'c' ),
			'identity'       => $brand,
			'tokens'         => $exporter->export_array(),
			'themeJson'      => NV_oOS_Design_System_Global_Styles::build_partial(),
			'activeTemplate' => NV_oOS_Design_System_Email_Template_Registry::get_active_slug(),
		);
	}

	/**
	 * Render a self-contained, printable brand guide.
	 *
	 * @return string HTML.
	 */
	public static function export_guide_html() {
		$brand    = self::get();
		$registry = NV_oOS_Design_System_Plugin::token_registry();
		$tokens   = $registry->get_all();
		$pairs    = NV_oOS_Design_System_Contrast_Auditor::audit_pairs(
			NV_oOS_Design_System_Contrast_Auditor::derive_pairs( $tokens )
		);

		$palette = array();
		foreach ( $tokens as $token ) {
			if ( 'color' === $token->type && ! preg_match( '/_(dark|hc)$/', $token->id ) ) {
				$palette[] = $token;
			}
		}

		ob_start();
		?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?php echo esc_html( $brand['name'] ? $brand['name'] : get_bloginfo( 'name' ) ); ?> — Brand Guide</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:960px;margin:40px auto;padding:0 24px;color:#1a1a1a;line-height:1.6}
h1,h2{border-bottom:1px solid #e5e5e5;padding-bottom:8px}
h1{font-size:36px}h2{margin-top:40px;font-size:24px}
.swatches{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px}
.swatch{border:1px solid #e5e5e5;border-radius:8px;overflow:hidden}
.swatch .chip{height:80px}.swatch .meta{padding:8px;font-size:12px}
table{border-collapse:collapse;width:100%}th,td{border:1px solid #e5e5e5;padding:8px;text-align:left;font-size:14px}
.pass{color:#0a7f3f;font-weight:600}.fail{color:#b32d2e;font-weight:600}
@media print{body{margin:0}}
</style>
</head>
<body>
<h1><?php echo esc_html( $brand['name'] ? $brand['name'] : get_bloginfo( 'name' ) ); ?></h1>
<p><?php echo esc_html( $brand['tagline'] ); ?></p>
<p><strong><?php esc_html_e( 'Generated', 'nvoos-design-system' ); ?>:</strong> <?php echo esc_html( gmdate( 'Y-m-d' ) ); ?></p>

<h2><?php esc_html_e( 'Identity', 'nvoos-design-system' ); ?></h2>
<table>
<tr><th><?php esc_html_e( 'Field', 'nvoos-design-system' ); ?></th><th><?php esc_html_e( 'Value', 'nvoos-design-system' ); ?></th></tr>
<tr><td><?php esc_html_e( 'Sub-brand', 'nvoos-design-system' ); ?></td><td><?php echo esc_html( $brand['sub_brand'] ); ?></td></tr>
<tr><td><?php esc_html_e( 'Contact', 'nvoos-design-system' ); ?></td><td><?php echo esc_html( $brand['contact_email'] ); ?></td></tr>
<tr><td><?php esc_html_e( 'Voice', 'nvoos-design-system' ); ?></td><td><?php echo esc_html( $brand['voice'] ); ?></td></tr>
</table>

<h2><?php esc_html_e( 'Palette', 'nvoos-design-system' ); ?></h2>
<div class="swatches">
		<?php foreach ( $palette as $token ) : ?>
<div class="swatch">
<div class="chip" style="background:<?php echo esc_attr( $token->value ); ?>"></div>
<div class="meta"><strong><?php echo esc_html( $token->label ); ?></strong><br><code><?php echo esc_html( $token->css_var() ); ?></code><br><?php echo esc_html( $token->value ); ?></div>
</div>
		<?php endforeach; ?>
</div>

<h2><?php esc_html_e( 'Contrast Audit', 'nvoos-design-system' ); ?></h2>
<table>
<tr><th><?php esc_html_e( 'Pair', 'nvoos-design-system' ); ?></th><th><?php esc_html_e( 'Ratio', 'nvoos-design-system' ); ?></th><th><?php esc_html_e( 'Required', 'nvoos-design-system' ); ?></th><th><?php esc_html_e( 'Result', 'nvoos-design-system' ); ?></th></tr>
		<?php foreach ( $pairs as $pair ) : ?>
<tr>
<td><?php echo esc_html( $pair['label'] ); ?></td>
<td><?php echo null === $pair['ratio'] ? '—' : esc_html( number_format_i18n( (float) $pair['ratio'], 2 ) ); ?></td>
<td><?php echo esc_html( number_format_i18n( (float) $pair['required'], 1 ) ); ?>:1</td>
<td class="<?php echo $pair['pass'] ? 'pass' : 'fail'; ?>"><?php echo $pair['pass'] ? esc_html__( 'Pass', 'nvoos-design-system' ) : esc_html__( 'Fail', 'nvoos-design-system' ); ?></td>
</tr>
		<?php endforeach; ?>
</table>

<h2><?php esc_html_e( 'Token Reference', 'nvoos-design-system' ); ?></h2>
<table>
<tr><th><?php esc_html_e( 'Token', 'nvoos-design-system' ); ?></th><th><?php esc_html_e( 'CSS variable', 'nvoos-design-system' ); ?></th><th><?php esc_html_e( 'Value', 'nvoos-design-system' ); ?></th></tr>
		<?php foreach ( $tokens as $token ) : ?>
<tr><td><?php echo esc_html( $token->label ); ?></td><td><code><?php echo esc_html( $token->css_var() ); ?></code></td><td><code><?php echo esc_html( $token->value ); ?></code></td></tr>
		<?php endforeach; ?>
</table>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}
}
