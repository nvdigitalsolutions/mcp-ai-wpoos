<?php
/**
 * NV oOS Comic Reader — Settings
 *
 * Registers the addon's settings page (Settings → Comic Reader) and exposes
 * the site-wide defaults: reader preferences, library/upload controls, and
 * capability overrides. Stored in the `nvoos_comic_reader_settings` option
 * (cleaned up by uninstall.php).
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings storage and admin page for the comic reader.
 *
 * @since 0.5.0
 */
class NV_oOS_Comic_Reader_Settings {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'nvoos_comic_reader_settings';

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'nvoos-comic-reader-settings';

	/**
	 * Capability required to manage the settings page.
	 *
	 * @var string
	 */
	const PAGE_CAPABILITY = 'manage_options';

	/**
	 * Default settings values.
	 *
	 * @var array<string,mixed>
	 */
	const DEFAULTS = array(
		'reader_direction'   => 'ltr',
		'reader_mode'        => 'paged',
		'reader_scale'       => 'fit-width',
		'reader_background'  => 'gray',
		'reader_double_page' => 0,
		'reader_transition'  => 'fade',
		'reader_gestures'    => 1,
		'max_upload_mb'      => 256,
		'cover_extraction'   => 1,
		'progress_sync'      => 1,
		'capability_read'    => '',
		'capability_upload'  => '',
		'capability_delete'  => '',
		'capability_edit'    => '',
	);

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Add the settings page under the Settings menu.
	 *
	 * @return void
	 */
	public static function add_menu_page() {
		add_options_page(
			__( 'Comic Reader', 'nvoos-comic-reader' ),
			__( 'Comic Reader', 'nvoos-comic-reader' ),
			// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- declared constant.
			self::PAGE_CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register the settings group, sections, and fields.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			'nvoos_comic_reader_settings_group',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::DEFAULTS,
			)
		);

		add_settings_section(
			'nvoos_cr_reader_defaults',
			__( 'Reader Defaults', 'nvoos-comic-reader' ),
			array( __CLASS__, 'render_reader_defaults_intro' ),
			self::PAGE_SLUG
		);

		add_settings_section(
			'nvoos_cr_library',
			__( 'Library & Uploads', 'nvoos-comic-reader' ),
			array( __CLASS__, 'render_library_intro' ),
			self::PAGE_SLUG
		);

		add_settings_section(
			'nvoos_cr_permissions',
			__( 'Permissions', 'nvoos-comic-reader' ),
			array( __CLASS__, 'render_permissions_intro' ),
			self::PAGE_SLUG
		);

		self::add_select_field(
			'reader_direction',
			'nvoos_cr_reader_defaults',
			__( 'Reading direction', 'nvoos-comic-reader' ),
			array(
				'ltr' => __( 'Left-to-right', 'nvoos-comic-reader' ),
				'rtl' => __( 'Right-to-left', 'nvoos-comic-reader' ),
			)
		);
		self::add_select_field(
			'reader_mode',
			'nvoos_cr_reader_defaults',
			__( 'Reading mode', 'nvoos-comic-reader' ),
			array(
				'paged'   => __( 'Paged', 'nvoos-comic-reader' ),
				'scroll'  => __( 'Vertical scroll', 'nvoos-comic-reader' ),
				'webtoon' => __( 'Webtoon', 'nvoos-comic-reader' ),
			)
		);
		self::add_select_field(
			'reader_scale',
			'nvoos_cr_reader_defaults',
			__( 'Scale', 'nvoos-comic-reader' ),
			array(
				'fit-screen' => __( 'Fit to screen', 'nvoos-comic-reader' ),
				'fit-width'  => __( 'Fit width', 'nvoos-comic-reader' ),
				'fit-height' => __( 'Fit height', 'nvoos-comic-reader' ),
				'none'       => __( 'Original size', 'nvoos-comic-reader' ),
			)
		);
		self::add_select_field(
			'reader_background',
			'nvoos_cr_reader_defaults',
			__( 'Background', 'nvoos-comic-reader' ),
			array(
				'white' => __( 'White', 'nvoos-comic-reader' ),
				'gray'  => __( 'Gray', 'nvoos-comic-reader' ),
				'black' => __( 'Black', 'nvoos-comic-reader' ),
			)
		);
		self::add_select_field(
			'reader_transition',
			'nvoos_cr_reader_defaults',
			__( 'Page transition', 'nvoos-comic-reader' ),
			array(
				'none'  => __( 'None', 'nvoos-comic-reader' ),
				'fade'  => __( 'Fade', 'nvoos-comic-reader' ),
				'slide' => __( 'Slide', 'nvoos-comic-reader' ),
			)
		);
		self::add_checkbox_field( 'reader_double_page', 'nvoos_cr_reader_defaults', __( 'Double-page spread by default', 'nvoos-comic-reader' ) );
		self::add_checkbox_field( 'reader_gestures', 'nvoos_cr_reader_defaults', __( 'Touch gestures', 'nvoos-comic-reader' ) );

		self::add_number_field( 'max_upload_mb', 'nvoos_cr_library', __( 'Maximum upload size (MB)', 'nvoos-comic-reader' ), 1, 4096 );
		self::add_checkbox_field( 'cover_extraction', 'nvoos_cr_library', __( 'Extract covers automatically (CBZ)', 'nvoos-comic-reader' ) );
		self::add_checkbox_field( 'progress_sync', 'nvoos_cr_library', __( 'Sync reading progress to the server', 'nvoos-comic-reader' ) );

		$capability_labels = array(
			'read'   => __( 'Read capability', 'nvoos-comic-reader' ),
			'upload' => __( 'Upload capability', 'nvoos-comic-reader' ),
			'delete' => __( 'Delete capability', 'nvoos-comic-reader' ),
			'edit'   => __( 'Edit capability', 'nvoos-comic-reader' ),
		);
		foreach ( $capability_labels as $context => $label ) {
			self::add_capability_field( $context, $label );
		}
	}

	/**
	 * Register a select settings field.
	 *
	 * @param string $key     Setting key.
	 * @param string $section Settings section slug.
	 * @param string $label   Field label.
	 * @param array  $options Value → label options.
	 * @return void
	 */
	private static function add_select_field( $key, $section, $label, $options ) {
		add_settings_field(
			'nvoos_cr_' . $key,
			$label,
			function () use ( $key, $options ) {
				$value = self::get( $key );
				echo '<select name="' . esc_attr( self::OPTION_NAME . '[' . $key . ']' ) . '">';
				foreach ( $options as $option_value => $option_label ) {
					printf(
						'<option value="%1$s"%2$s>%3$s</option>',
						esc_attr( $option_value ),
						selected( $value, $option_value, false ),
						esc_html( $option_label )
					);
				}
				echo '</select>';
			},
			self::PAGE_SLUG,
			$section
		);
	}

	/**
	 * Register a checkbox settings field.
	 *
	 * @param string $key     Setting key.
	 * @param string $section Settings section slug.
	 * @param string $label   Field label.
	 * @return void
	 */
	private static function add_checkbox_field( $key, $section, $label ) {
		add_settings_field(
			'nvoos_cr_' . $key,
			$label,
			function () use ( $key ) {
				printf(
					'<input type="checkbox" name="%1$s" value="1"%2$s />',
					esc_attr( self::OPTION_NAME . '[' . $key . ']' ),
					checked( 1, (int) self::get( $key ), false )
				);
			},
			self::PAGE_SLUG,
			$section
		);
	}

	/**
	 * Register a number settings field.
	 *
	 * @param string $key     Setting key.
	 * @param string $section Settings section slug.
	 * @param string $label   Field label.
	 * @param int    $min     Minimum allowed value.
	 * @param int    $max     Maximum allowed value.
	 * @return void
	 */
	private static function add_number_field( $key, $section, $label, $min, $max ) {
		add_settings_field(
			'nvoos_cr_' . $key,
			$label,
			function () use ( $key, $min, $max ) {
				printf(
					'<input type="number" name="%1$s" value="%2$s" min="%3$d" max="%4$d" />',
					esc_attr( self::OPTION_NAME . '[' . $key . ']' ),
					esc_attr( (string) self::get( $key ) ),
					(int) $min,
					(int) $max
				);
			},
			self::PAGE_SLUG,
			$section
		);
	}

	/**
	 * Register a capability settings field (blank = use the filter default).
	 *
	 * @param string $context read|upload|delete|edit.
	 * @param string $label   Field label.
	 * @return void
	 */
	private static function add_capability_field( $context, $label ) {
		$key = 'capability_' . $context;

		add_settings_field(
			'nvoos_cr_' . $key,
			$label,
			function () use ( $key ) {
				printf(
					'<input type="text" class="regular-text" name="%1$s" value="%2$s" placeholder="%3$s" />',
					esc_attr( self::OPTION_NAME . '[' . $key . ']' ),
					esc_attr( (string) self::get( $key ) ),
					esc_attr__( 'Default (filterable)', 'nvoos-comic-reader' )
				);
			},
			self::PAGE_SLUG,
			'nvoos_cr_permissions'
		);
	}

	/**
	 * Section intro: reader defaults.
	 *
	 * @return void
	 */
	public static function render_reader_defaults_intro() {
		echo '<p>' . esc_html__( 'Site-wide reader defaults. Readers can override them per comic from the reader settings dialog.', 'nvoos-comic-reader' ) . '</p>';
	}

	/**
	 * Section intro: library & uploads.
	 *
	 * @return void
	 */
	public static function render_library_intro() {
		echo '<p>' . esc_html__( 'Controls for the comic library and upload handling.', 'nvoos-comic-reader' ) . '</p>';
	}

	/**
	 * Section intro: permissions.
	 *
	 * @return void
	 */
	public static function render_permissions_intro() {
		echo '<p>' . esc_html__( 'Leave blank to use the default capabilities (each is filterable with the matching nvoos_comic_reader_*_capability hook).', 'nvoos-comic-reader' ) . '</p>';
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public static function render_page() {
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- declared constant.
		if ( ! current_user_can( self::PAGE_CAPABILITY ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Comic Reader Settings', 'nvoos-comic-reader' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'nvoos_comic_reader_settings_group' );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Sanitize the settings array before storage.
	 *
	 * @param mixed $input Raw settings input.
	 * @return array Sanitized settings.
	 */
	public static function sanitize_settings( $input ) {
		$clean = self::DEFAULTS;

		if ( ! is_array( $input ) ) {
			return $clean;
		}

		$selects = array(
			'reader_direction'  => array( 'ltr', 'rtl' ),
			'reader_mode'       => array( 'paged', 'scroll', 'webtoon' ),
			'reader_scale'      => array( 'fit-screen', 'fit-width', 'fit-height', 'none' ),
			'reader_background' => array( 'white', 'gray', 'black' ),
			'reader_transition' => array( 'none', 'fade', 'slide' ),
		);
		foreach ( $selects as $key => $allowed ) {
			if ( isset( $input[ $key ] ) && in_array( $input[ $key ], $allowed, true ) ) {
				$clean[ $key ] = $input[ $key ];
			}
		}

		foreach ( array( 'reader_double_page', 'reader_gestures', 'cover_extraction', 'progress_sync' ) as $key ) {
			$clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		if ( isset( $input['max_upload_mb'] ) ) {
			$clean['max_upload_mb'] = max( 1, min( 4096, (int) $input['max_upload_mb'] ) );
		}

		foreach ( array( 'read', 'upload', 'delete', 'edit' ) as $context ) {
			$key = 'capability_' . $context;
			if ( isset( $input[ $key ] ) ) {
				$value         = sanitize_text_field( wp_unslash( (string) $input[ $key ] ) );
				$clean[ $key ] = preg_replace( '/[^a-z0-9_]/', '', $value );
			}
		}

		return $clean;
	}

	/**
	 * Get all stored settings merged over the defaults.
	 *
	 * @return array<string,mixed> Merged settings.
	 */
	public static function get_all() {
		$stored = get_option( self::OPTION_NAME, array() );
		return is_array( $stored ) ? array_merge( self::DEFAULTS, $stored ) : self::DEFAULTS;
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Fallback when unset.
	 * @return mixed Setting value.
	 */
	public static function get( $key, $fallback = null ) {
		$settings = self::get_all();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $fallback;
	}

	/**
	 * Get the effective capability for a permission context.
	 *
	 * Resolution order: stored setting override → filterable default → built-in.
	 *
	 * @param string $context read|upload|delete|edit.
	 * @return string Effective capability slug.
	 */
	public static function get_capability( $context ) {
		$filter_defaults = array(
			'read'   => 'read',
			'upload' => 'upload_files',
			'delete' => 'delete_posts',
			'edit'   => 'edit_posts',
		);
		$filter_hooks    = array(
			'read'   => 'nvoos_comic_reader_read_capability',
			'upload' => 'nvoos_comic_reader_upload_capability',
			'delete' => 'nvoos_comic_reader_delete_capability',
			'edit'   => 'nvoos_comic_reader_edit_capability',
		);

		$default = isset( $filter_defaults[ $context ] ) ? $filter_defaults[ $context ] : 'read';
		if ( isset( $filter_hooks[ $context ] ) ) {
			$default = (string) apply_filters( $filter_hooks[ $context ], $default );
		}

		$stored = (string) self::get( 'capability_' . $context, '' );
		return '' !== $stored ? $stored : $default;
	}

	/**
	 * The reader defaults exposed to the SPA (shortcode localization).
	 *
	 * @return array<string,mixed> Reader defaults for the frontend.
	 */
	public static function get_reader_defaults() {
		return array(
			'direction'   => self::get( 'reader_direction', 'ltr' ),
			'readingMode' => self::get( 'reader_mode', 'paged' ),
			'scale'       => self::get( 'reader_scale', 'fit-width' ),
			'background'  => self::get( 'reader_background', 'gray' ),
			'doublePage'  => (bool) self::get( 'reader_double_page', 0 ),
			'transition'  => self::get( 'reader_transition', 'fade' ),
			'gestures'    => (bool) self::get( 'reader_gestures', 1 ),
		);
	}

	/**
	 * The maximum allowed upload size in bytes (settings override the filter).
	 *
	 * @return int Maximum upload size in bytes.
	 */
	public static function get_max_upload_bytes() {
		$default = (int) self::get( 'max_upload_mb', 256 ) * MB_IN_BYTES;
		return (int) apply_filters( 'nvoos_comic_reader_max_upload_bytes', $default );
	}

	/**
	 * Whether server-side progress sync is enabled.
	 *
	 * @return bool
	 */
	public static function progress_sync_enabled() {
		return (bool) self::get( 'progress_sync', 1 );
	}

	/**
	 * Whether automatic cover extraction is enabled.
	 *
	 * @return bool
	 */
	public static function cover_extraction_enabled() {
		return (bool) self::get( 'cover_extraction', 1 );
	}
}
