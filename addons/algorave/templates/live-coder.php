<?php
/**
 * Template: Live Coder
 *
 * Renders the algorave live coding interface with sample bank
 * selection, effects quick-reference, and pattern presets.
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

$bpm          = absint( $atts['bpm'] ?? 120 );
$scale        = sanitize_text_field( $atts['scale'] ?? 'C minor' );
$show_viz     = 'true' === ( $atts['visualizer'] ?? 'true' );
$default_code = sprintf(
	"// Algorave Live Coder — %s @ %d BPM\n"
	. "// Press Ctrl+Enter to play, Ctrl+. to stop\n"
	. "setcps(%s)\n\n"
	. "stack(\n"
	. "  s(\"bd*4\").bank(\"RolandTR808\").gain(0.8),\n"
	. "  s(\"~ hh ~ hh\").bank(\"RolandTR808\").gain(0.5),\n"
	. "  s(\"~ sd ~ sd\").bank(\"RolandTR808\").gain(0.7)\n"
	. "    .room(0.2)\n"
	. ')',
	esc_attr( $scale ),
	$bpm,
	number_format( $bpm / 60 / 4, 4 )
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

		<label for="algorave-bank"><?php esc_html_e( 'Bank:', 'nvoos-algorave' ); ?></label>
		<select id="algorave-bank" class="algorave-bank-select">
			<option value=""><?php esc_html_e( 'Default', 'nvoos-algorave' ); ?></option>
			<option value="RolandTR808">TR-808</option>
			<option value="RolandTR909">TR-909</option>
			<option value="RolandCR78">CR-78</option>
			<option value="AkaiLinn"><?php esc_html_e( 'Akai Linn', 'nvoos-algorave' ); ?></option>
			<option value="RhythmAce"><?php esc_html_e( 'Rhythm Ace', 'nvoos-algorave' ); ?></option>
			<option value="KorgMinipops"><?php esc_html_e( 'Korg Minipops', 'nvoos-algorave' ); ?></option>
		</select>

		<label for="algorave-bpm"><?php esc_html_e( 'BPM:', 'nvoos-algorave' ); ?></label>
		<input type="number" id="algorave-bpm" class="algorave-bpm-input" value="<?php echo esc_attr( $bpm ); ?>" min="20" max="300" />

		<span class="algorave-status-indicator" title="<?php esc_attr_e( 'Playback status', 'nvoos-algorave' ); ?>"></span>
	</div>

	<!--
		Raw-eval warning (F-AI-01). Shown by algorave-live-coder.js only when the
		site operator has enabled the Tone.js engine via WP_MCP_AI_ALLOW_TONEJS_EVAL
		and the Tone.js engine is selected. The Tone.js engine compiles user-typed
		code with `new Function`, so it runs with the page's own permissions.
	-->
	<div class="algorave-eval-warning" role="alert" hidden>
		⚠️ <?php esc_html_e( 'Tone.js live coding runs pasted code with your site permissions. Only run code you trust.', 'nvoos-algorave' ); ?>
	</div>

	<!-- Pattern Presets -->
	<div class="algorave-presets">
		<span class="algorave-presets-label"><?php esc_html_e( 'Presets:', 'nvoos-algorave' ); ?></span>
		<button type="button" class="algorave-preset-btn" data-preset="techno"><?php esc_html_e( 'Techno', 'nvoos-algorave' ); ?></button>
		<button type="button" class="algorave-preset-btn" data-preset="house"><?php esc_html_e( 'House', 'nvoos-algorave' ); ?></button>
		<button type="button" class="algorave-preset-btn" data-preset="ambient"><?php esc_html_e( 'Ambient', 'nvoos-algorave' ); ?></button>
		<button type="button" class="algorave-preset-btn" data-preset="dnb"><?php esc_html_e( 'DnB', 'nvoos-algorave' ); ?></button>
		<button type="button" class="algorave-preset-btn" data-preset="minimal"><?php esc_html_e( 'Minimal', 'nvoos-algorave' ); ?></button>
		<button type="button" class="algorave-preset-btn" data-preset="trap"><?php esc_html_e( 'Trap', 'nvoos-algorave' ); ?></button>
		<button type="button" class="algorave-preset-btn" data-preset="lofi"><?php esc_html_e( 'Lo-Fi', 'nvoos-algorave' ); ?></button>
		<button type="button" class="algorave-preset-btn" data-preset="dub"><?php esc_html_e( 'Dub', 'nvoos-algorave' ); ?></button>
		<button type="button" class="algorave-preset-btn" data-preset="dubstep"><?php esc_html_e( 'Dubstep', 'nvoos-algorave' ); ?></button>
		<button type="button" class="algorave-preset-btn" data-preset="trance"><?php esc_html_e( 'Trance', 'nvoos-algorave' ); ?></button>
		<button type="button" class="algorave-preset-btn" data-preset="synthwave"><?php esc_html_e( 'Synthwave', 'nvoos-algorave' ); ?></button>
	</div>

	<!-- Code Editor -->
	<textarea class="algorave-code-editor" placeholder="<?php esc_attr_e( 'Type your pattern code here...', 'nvoos-algorave' ); ?>" spellcheck="false" autocomplete="off" autocorrect="off" autocapitalize="off"><?php echo esc_textarea( $default_code ); ?></textarea>

	<!-- Pattern Visualization (pianoroll / punchcard) -->
	<div class="algorave-pattern-viz" style="display:none;">
		<canvas id="algorave-pattern-viz-canvas"></canvas>
	</div>

	<!-- Effects Quick Reference (collapsible) -->
	<details class="algorave-effects-ref">
		<summary class="algorave-effects-ref-toggle"><?php esc_html_e( 'Strudel Quick Reference', 'nvoos-algorave' ); ?></summary>
		<div class="algorave-effects-ref-content">
			<div class="algorave-ref-column">
				<h4><?php esc_html_e( 'Mini-notation', 'nvoos-algorave' ); ?></h4>
				<code>*n</code> <?php esc_html_e( 'speed up', 'nvoos-algorave' ); ?><br>
				<code>/n</code> <?php esc_html_e( 'slow down', 'nvoos-algorave' ); ?><br>
				<code>~</code> <?php esc_html_e( 'rest', 'nvoos-algorave' ); ?><br>
				<code>[ ]</code> <?php esc_html_e( 'sub-sequence', 'nvoos-algorave' ); ?><br>
				<code>&lt; &gt;</code> <?php esc_html_e( 'alternate', 'nvoos-algorave' ); ?><br>
				<code>,</code> <?php esc_html_e( 'parallel', 'nvoos-algorave' ); ?><br>
				<code>?</code> <?php esc_html_e( 'random 50%', 'nvoos-algorave' ); ?><br>
				<code>(k,n)</code> <?php esc_html_e( 'Euclidean', 'nvoos-algorave' ); ?><br>
				<code>:n</code> <?php esc_html_e( 'sample index', 'nvoos-algorave' ); ?>
			</div>
			<div class="algorave-ref-column">
				<h4><?php esc_html_e( 'Effects', 'nvoos-algorave' ); ?></h4>
				<code>.gain(0-1)</code> <?php esc_html_e( 'volume', 'nvoos-algorave' ); ?><br>
				<code>.room(0-1)</code> <?php esc_html_e( 'reverb', 'nvoos-algorave' ); ?><br>
				<code>.delay(0-1)</code> <?php esc_html_e( 'echo', 'nvoos-algorave' ); ?><br>
				<code>.lpf(Hz)</code> <?php esc_html_e( 'lowpass', 'nvoos-algorave' ); ?><br>
				<code>.hpf(Hz)</code> <?php esc_html_e( 'highpass', 'nvoos-algorave' ); ?><br>
				<code>.crush(bits)</code> <?php esc_html_e( 'bitcrush', 'nvoos-algorave' ); ?><br>
				<code>.distort(0-1)</code> <?php esc_html_e( 'distortion', 'nvoos-algorave' ); ?><br>
				<code>.pan(-1,1)</code> <?php esc_html_e( 'stereo', 'nvoos-algorave' ); ?><br>
				<code>.speed(rate)</code> <?php esc_html_e( 'playback', 'nvoos-algorave' ); ?>
			</div>
			<div class="algorave-ref-column">
				<h4><?php esc_html_e( 'Transforms', 'nvoos-algorave' ); ?></h4>
				<code>.every(n, fn)</code><br>
				<code>.sometimes(fn)</code><br>
				<code>.slow(n)</code><br>
				<code>.fast(n)</code><br>
				<code>.rev()</code><br>
				<code>.jux(fn)</code><br>
				<code>.midi()</code><br>
				<code>setcps(n)</code><br>
				<code>stack(...)</code>
			</div>
		</div>
	</details>

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
			<button type="button" class="is-active" data-mode="waveform" title="<?php esc_attr_e( 'Waveform', 'nvoos-algorave' ); ?>">〰</button>
			<button type="button" data-mode="spectrum" title="<?php esc_attr_e( 'Spectrum', 'nvoos-algorave' ); ?>">📊</button>
			<button type="button" data-mode="bars" title="<?php esc_attr_e( 'Bars', 'nvoos-algorave' ); ?>">▮▮▮</button>
			<button type="button" data-mode="circular" title="<?php esc_attr_e( 'Circular', 'nvoos-algorave' ); ?>">◎</button>
			<button type="button" data-mode="particles" title="<?php esc_attr_e( 'Particles', 'nvoos-algorave' ); ?>">✦</button>
			<button type="button" data-mode="scope" title="<?php esc_attr_e( 'Scope', 'nvoos-algorave' ); ?>">⏛</button>
			<button type="button" data-mode="spectrogram" title="<?php esc_attr_e( 'Spectrogram', 'nvoos-algorave' ); ?>">🌊</button>
			<button type="button" data-mode="lissajous" title="<?php esc_attr_e( 'Lissajous', 'nvoos-algorave' ); ?>">∞</button>
		</div>
	</div>
	<?php endif; ?>

</div>
