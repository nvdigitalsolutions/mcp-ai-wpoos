<?php
/**
 * Template: Live Coder
 *
 * Renders the algorave live coding interface.
 * Used by the [algorave_live_coder] shortcode.
 *
 * @package NV_oOS_Algorave
 * @since   1.0.0
 *
 * @var array $atts Shortcode attributes (bpm, scale, visualizer).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bpm           = absint( $atts['bpm'] ?? 120 );
$scale         = sanitize_text_field( $atts['scale'] ?? 'C minor' );
$show_viz      = 'true' === ( $atts['visualizer'] ?? 'true' );
$default_code  = sprintf(
	"// Algorave Live Coder — %s @ %d BPM\n"
	. "// Press Ctrl+Enter to play, Ctrl+. to stop\n\n"
	. "stack(\n"
	. "  s(\"bd*4\").gain(0.8),\n"
	. "  s(\"~ hh ~ hh\").gain(0.5),\n"
	. "  s(\"~ sd ~ sd\").gain(0.7)\n"
	. ")",
	esc_attr( $scale ),
	$bpm
);
?>
<div id="algorave-live-coder" class="algorave-live-coder" data-bpm="<?php echo esc_attr( $bpm ); ?>" data-scale="<?php echo esc_attr( $scale ); ?>">

	<!-- Toolbar -->
	<div class="algorave-toolbar">
		<button type="button" class="algorave-btn algorave-btn-play">▶ Play</button>
		<button type="button" class="algorave-btn algorave-btn-stop">■ Stop</button>

		<label for="algorave-engine"><?php esc_html_e( 'Engine:', 'nvoos-algorave' ); ?></label>
		<select id="algorave-engine" class="algorave-engine-select">
			<option value="strudel"><?php esc_html_e( 'Strudel', 'nvoos-algorave' ); ?></option>
			<option value="tonejs"><?php esc_html_e( 'Tone.js', 'nvoos-algorave' ); ?></option>
		</select>

		<label for="algorave-bpm"><?php esc_html_e( 'BPM:', 'nvoos-algorave' ); ?></label>
		<input type="number" id="algorave-bpm" class="algorave-bpm-input" value="<?php echo esc_attr( $bpm ); ?>" min="20" max="300" />

		<span class="algorave-status-indicator" title="<?php esc_attr_e( 'Playback status', 'nvoos-algorave' ); ?>"></span>
	</div>

	<!-- Code Editor -->
	<textarea class="algorave-code-editor" placeholder="<?php esc_attr_e( 'Type your pattern code here...', 'nvoos-algorave' ); ?>" spellcheck="false" autocomplete="off" autocorrect="off" autocapitalize="off"><?php echo esc_textarea( $default_code ); ?></textarea>

	<!-- Keyboard Shortcuts -->
	<div class="algorave-hints">
		<span><kbd>Ctrl</kbd>+<kbd>Enter</kbd> <?php esc_html_e( 'Play', 'nvoos-algorave' ); ?></span>
		<span><kbd>Ctrl</kbd>+<kbd>.</kbd> <?php esc_html_e( 'Stop', 'nvoos-algorave' ); ?></span>
		<span><kbd>Tab</kbd> <?php esc_html_e( 'Indent', 'nvoos-algorave' ); ?></span>
	</div>

	<?php if ( $show_viz ) : ?>
	<!-- Audio Visualizer -->
	<div class="algorave-visualizer">
		<canvas id="algorave-visualizer-canvas"></canvas>
		<div class="algorave-visualizer-controls">
			<button type="button" data-mode="waveform" title="<?php esc_attr_e( 'Waveform', 'nvoos-algorave' ); ?>">〰</button>
			<button type="button" data-mode="spectrum" title="<?php esc_attr_e( 'Spectrum', 'nvoos-algorave' ); ?>">📊</button>
			<button type="button" data-mode="bars" title="<?php esc_attr_e( 'Bars', 'nvoos-algorave' ); ?>">▮▮▮</button>
			<button type="button" data-mode="circular" title="<?php esc_attr_e( 'Circular', 'nvoos-algorave' ); ?>">◎</button>
		</div>
	</div>
	<?php endif; ?>

</div>
