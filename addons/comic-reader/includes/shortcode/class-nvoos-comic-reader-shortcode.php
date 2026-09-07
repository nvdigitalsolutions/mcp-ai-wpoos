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
		$js_path  = NVOOS_COMIC_READER_PATH . 'assets/dist/comic-reader.js';
		$css_path = NVOOS_COMIC_READER_PATH . 'assets/dist/comic-reader.css';
		$js_ver   = file_exists( $js_path ) ? filemtime( $js_path ) : NVOOS_COMIC_READER_VERSION;
		$css_ver  = file_exists( $css_path ) ? filemtime( $css_path ) : NVOOS_COMIC_READER_VERSION;

		wp_register_style(
			'nvoos-comic-reader',
			NVOOS_COMIC_READER_URL . 'assets/dist/comic-reader.css',
			array(),
			$css_ver
		);
		wp_register_script(
			'nvoos-comic-reader',
			NVOOS_COMIC_READER_URL . 'assets/dist/comic-reader.js',
			array( 'wp-i18n' ),
			$js_ver,
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
					// Reader UX (v0.3.0).
					'retry'             => __( 'Retry', 'nvoos-comic-reader' ),
					'cancel'            => __( 'Cancel', 'nvoos-comic-reader' ),
					'noPages'           => __( 'No pages to display.', 'nvoos-comic-reader' ),
					'pageAlt'           => __( 'Page %1$d', 'nvoos-comic-reader' ),
					'settings'          => __( 'Reader settings', 'nvoos-comic-reader' ),
					'close'             => __( 'Close', 'nvoos-comic-reader' ),
					'help'              => __( 'Keyboard shortcuts', 'nvoos-comic-reader' ),
					'thumbnails'        => __( 'Pages overview', 'nvoos-comic-reader' ),
					'readingMode'       => __( 'Reading mode', 'nvoos-comic-reader' ),
					'modePaged'         => __( 'Paged', 'nvoos-comic-reader' ),
					'modeScroll'        => __( 'Vertical scroll', 'nvoos-comic-reader' ),
					'modeWebtoon'       => __( 'Webtoon', 'nvoos-comic-reader' ),
					'scaleType'         => __( 'Scale', 'nvoos-comic-reader' ),
					'fitScreen'         => __( 'Fit to screen', 'nvoos-comic-reader' ),
					'original'          => __( 'Original size', 'nvoos-comic-reader' ),
					'background'        => __( 'Background', 'nvoos-comic-reader' ),
					'backgroundWhite'   => __( 'White', 'nvoos-comic-reader' ),
					'backgroundGray'    => __( 'Gray', 'nvoos-comic-reader' ),
					'backgroundBlack'   => __( 'Black', 'nvoos-comic-reader' ),
					'transitions'       => __( 'Page transition', 'nvoos-comic-reader' ),
					'transitionNone'    => __( 'None', 'nvoos-comic-reader' ),
					'transitionFade'    => __( 'Fade', 'nvoos-comic-reader' ),
					'transitionSlide'   => __( 'Slide', 'nvoos-comic-reader' ),
					'gestures'          => __( 'Touch gestures', 'nvoos-comic-reader' ),
					'gesturesOn'        => __( 'Enabled', 'nvoos-comic-reader' ),
					'gesturesOff'       => __( 'Disabled', 'nvoos-comic-reader' ),
					'continueReading'   => __( 'Continue reading', 'nvoos-comic-reader' ),
					'allComics'         => __( 'All comics', 'nvoos-comic-reader' ),
					'searchPlaceholder' => __( 'Search comics…', 'nvoos-comic-reader' ),
					'sortLabel'         => __( 'Sort', 'nvoos-comic-reader' ),
					'sortDate'          => __( 'Newest first', 'nvoos-comic-reader' ),
					'sortTitle'         => __( 'Title A–Z', 'nvoos-comic-reader' ),
					'sortSize'          => __( 'Largest first', 'nvoos-comic-reader' ),
					'filterSeries'      => __( 'Series', 'nvoos-comic-reader' ),
					'allSeries'         => __( 'All series', 'nvoos-comic-reader' ),
					'noSeries'          => __( 'No series', 'nvoos-comic-reader' ),
					'readPercent'       => __( '%1$d%% read', 'nvoos-comic-reader' ),
					'completedLabel'    => __( 'Completed', 'nvoos-comic-reader' ),
					// Library & metadata (v0.4.0).
					'editMetadata'      => __( 'Edit details', 'nvoos-comic-reader' ),
					'save'              => __( 'Save', 'nvoos-comic-reader' ),
					'title'             => __( 'Title', 'nvoos-comic-reader' ),
					'series'            => __( 'Series', 'nvoos-comic-reader' ),
					'number'            => __( 'Issue number', 'nvoos-comic-reader' ),
					'volume'            => __( 'Volume', 'nvoos-comic-reader' ),
					'writer'            => __( 'Writer', 'nvoos-comic-reader' ),
					'publisher'         => __( 'Publisher', 'nvoos-comic-reader' ),
					'metadataSaved'     => __( 'Details saved.', 'nvoos-comic-reader' ),
					'errorSave'         => __( 'Failed to save details.', 'nvoos-comic-reader' ),
					'addToCollection'   => __( 'Add to collection', 'nvoos-comic-reader' ),
					'collections'       => __( 'Collections', 'nvoos-comic-reader' ),
					'newCollection'     => __( 'New collection…', 'nvoos-comic-reader' ),
					'upload'            => __( 'Upload', 'nvoos-comic-reader' ),
					'noResults'         => __( 'No comics match your search.', 'nvoos-comic-reader' ),
					'pageProgress'      => __( 'Page %1$d', 'nvoos-comic-reader' ),
				),
			)
		);
		wp_enqueue_style( 'nvoos-comic-reader' );
		wp_enqueue_script( 'nvoos-comic-reader' );
	}
}
