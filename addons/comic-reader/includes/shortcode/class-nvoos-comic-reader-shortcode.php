<?php
/**
 * NV oOS Comic Reader — Shortcode
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode handler for [nvoos_comic_reader].
 *
 * @since 0.1.0
 */
class NV_oOS_Comic_Reader_Shortcode {

	/**
	 * Shortcode tag.
	 *
	 * @var string
	 */
	const SHORTCODE = 'nvoos_comic_reader';

	/**
	 * Register the shortcode.
	 *
	 * @return void
	 */
	public static function register() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
	}

	/**
	 * Render the shortcode output.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'        => '',
				'mode'      => 'library',
				'height'    => '',
				'direction' => 'ltr',
			),
			$atts,
			self::SHORTCODE
		);

		$can_render = apply_filters( 'nvoos_comic_reader_can_render', true, $atts );
		if ( ! $can_render ) {
			return '';
		}

		$config = array(
			'comicId'   => absint( $atts['id'] ),
			'mode'      => in_array( $atts['mode'], array( 'library', 'reader' ), true ) ? $atts['mode'] : 'library',
			'height'    => sanitize_text_field( $atts['height'] ),
			'direction' => in_array( $atts['direction'], array( 'ltr', 'rtl' ), true ) ? $atts['direction'] : 'ltr',
		);

		self::enqueue_assets( $config );

		$config_json = wp_json_encode( $config );
		if ( false === $config_json ) {
			$config_json = '{}';
		}

		$height_attr = '' !== $config['height'] ? 'min-height:' . $config['height'] . ';' : '';

		return sprintf(
			'<div class="nvoos-comic-reader-root" role="application" aria-label="%1$s" data-config="%2$s" style="%3$s"></div>',
			esc_attr__( 'Comic Reader', 'nvoos-comic-reader' ),
			esc_attr( $config_json ),
			esc_attr( $height_attr )
		);
	}

	/**
	 * Enqueue the SPA bundle scripts and styles.
	 *
	 * @param array $config Per-instance configuration.
	 * @return void
	 */
	public static function enqueue_assets( $config ) {
		wp_register_style(
			'nvoos-comic-reader',
			NVOOS_COMIC_READER_URL . 'assets/dist/comic-reader.css',
			array(),
			NVOOS_COMIC_READER_VERSION
		);
		wp_register_script(
			'nvoos-comic-reader',
			NVOOS_COMIC_READER_URL . 'assets/dist/comic-reader.js',
			array( 'wp-i18n' ),
			NVOOS_COMIC_READER_VERSION,
			true
		);
		wp_set_script_translations(
			'nvoos-comic-reader',
			'nvoos-comic-reader',
			NVOOS_COMIC_READER_PATH . 'languages'
		);
		wp_localize_script(
			'nvoos-comic-reader',
			'NVOOS_COMIC_READER',
			array(
				'apiUrl' => esc_url_raw( rest_url( NV_oOS_Comic_Reader_REST::REST_NAMESPACE ) ),
				'nonce'  => wp_create_nonce( 'wp_rest' ),
				'config' => $config,
				'i18n'   => array(
					'loading'           => __( 'Loading comic…', 'nvoos-comic-reader' ),
					'noComics'          => __( 'No comics found in your library.', 'nvoos-comic-reader' ),
					'dropHint'          => __( 'Drop CBR/CBZ files here or click to upload', 'nvoos-comic-reader' ),
					'pageOf'            => __( 'Page %1$d of %2$d', 'nvoos-comic-reader' ),
					'zoomIn'            => __( 'Zoom in', 'nvoos-comic-reader' ),
					'zoomOut'           => __( 'Zoom out', 'nvoos-comic-reader' ),
					'fitWidth'          => __( 'Fit width', 'nvoos-comic-reader' ),
					'fitHeight'         => __( 'Fit height', 'nvoos-comic-reader' ),
					'fullscreen'        => __( 'Fullscreen', 'nvoos-comic-reader' ),
					'exitFullscreen'    => __( 'Exit fullscreen', 'nvoos-comic-reader' ),
					'previousPage'      => __( 'Previous page', 'nvoos-comic-reader' ),
					'nextPage'          => __( 'Next page', 'nvoos-comic-reader' ),
					'library'           => __( 'Library', 'nvoos-comic-reader' ),
					'deleteComic'       => __( 'Delete comic', 'nvoos-comic-reader' ),
					'confirmDelete'     => __( 'Are you sure you want to delete this comic?', 'nvoos-comic-reader' ),
					'uploading'         => __( 'Uploading…', 'nvoos-comic-reader' ),
					'extracting'        => __( 'Extracting pages…', 'nvoos-comic-reader' ),
					'errorLoad'         => __( 'Failed to load comic.', 'nvoos-comic-reader' ),
					'unsupported'       => __( 'Unsupported file format.', 'nvoos-comic-reader' ),
					'singlePage'        => __( 'Single page', 'nvoos-comic-reader' ),
					'doublePage'        => __( 'Double page', 'nvoos-comic-reader' ),
					'readingLtr'        => __( 'Left-to-right', 'nvoos-comic-reader' ),
					'readingRtl'        => __( 'Right-to-left', 'nvoos-comic-reader' ),
					// Creator mode i18n (v0.2.0).
					'create'            => __( 'Create Comic', 'nvoos-comic-reader' ),
					'comicCreator'      => __( 'Comic Creator', 'nvoos-comic-reader' ),
					'creatorSteps'      => __( 'Creator Steps', 'nvoos-comic-reader' ),
					'previous'          => __( 'Previous', 'nvoos-comic-reader' ),
					'next'              => __( 'Next', 'nvoos-comic-reader' ),
					'previousStep'      => __( 'Previous step', 'nvoos-comic-reader' ),
					'nextStep'          => __( 'Next step', 'nvoos-comic-reader' ),
					'creatingComic'     => __( 'Creating new comic…', 'nvoos-comic-reader' ),
					'script'            => __( 'Script', 'nvoos-comic-reader' ),
					'characters'        => __( 'Characters', 'nvoos-comic-reader' ),
					'style'             => __( 'Style', 'nvoos-comic-reader' ),
					'panels'            => __( 'Panels', 'nvoos-comic-reader' ),
					'export'            => __( 'Export', 'nvoos-comic-reader' ),
					'generateScript'    => __( 'Generate Script', 'nvoos-comic-reader' ),
					'generatingScript'  => __( 'Generating script…', 'nvoos-comic-reader' ),
					'scriptPlaceholder' => __( 'Enter your story premise or script text…', 'nvoos-comic-reader' ),
					'premise'           => __( 'Premise', 'nvoos-comic-reader' ),
					'genre'             => __( 'Genre', 'nvoos-comic-reader' ),
					'panelCount'        => __( 'Panel Count', 'nvoos-comic-reader' ),
					'characterName'     => __( 'Character Name', 'nvoos-comic-reader' ),
					'characterDesc'     => __( 'Character Description', 'nvoos-comic-reader' ),
					'characterNotes'    => __( 'Style Notes', 'nvoos-comic-reader' ),
					'addCharacter'      => __( 'Add Character', 'nvoos-comic-reader' ),
					'generateReference' => __( 'Generate Reference Image', 'nvoos-comic-reader' ),
					'comicStyle'        => __( 'Comic Style', 'nvoos-comic-reader' ),
					'selectStyle'       => __( 'Select Art Style', 'nvoos-comic-reader' ),
					'generateAll'       => __( 'Generate All Panels', 'nvoos-comic-reader' ),
					'generatingPanel'   => __( 'Generating panels…', 'nvoos-comic-reader' ),
					'regenerate'        => __( 'Regenerate', 'nvoos-comic-reader' ),
					'exportComic'       => __( 'Export Comic', 'nvoos-comic-reader' ),
					'exporting'         => __( 'Exporting…', 'nvoos-comic-reader' ),
					'downloadComic'     => __( 'Download Comic', 'nvoos-comic-reader' ),
					'exportFormat'      => __( 'Export Format', 'nvoos-comic-reader' ),
					'noPanels'          => __( 'No panels yet. Generate a script first.', 'nvoos-comic-reader' ),
					'noCharacters'      => __( 'No characters defined yet.', 'nvoos-comic-reader' ),
					'editComic'         => __( 'Edit Comic', 'nvoos-comic-reader' ),
					'createComic'       => __( 'Create Comic', 'nvoos-comic-reader' ),
				),
			)
		);
		wp_enqueue_style( 'nvoos-comic-reader' );
		wp_enqueue_script( 'nvoos-comic-reader' );
	}
}
