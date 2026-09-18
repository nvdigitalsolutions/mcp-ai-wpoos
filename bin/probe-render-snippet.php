<?php
// Playground probe snippet — embedded into a copy of the demo blueprint's
// runPHP step by bin/make-probe-blueprint.php (which strips this opening
// tag). Renders the Ollama Test Lab page server-side and writes the full
// HTML plus a diagnostic report into /verify-out for local inspection.

$report = array(
	'home'       => home_url(),
	'assistants' => array(),
);

$q = new WP_Query(
	array(
		'post_type'      => 'mcp_ai_assistant',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'no_found_rows'  => true,
		'orderby'        => 'ID',
		'order'          => 'ASC',
	)
);
foreach ( $q->posts as $p ) {
	$report['assistants'][] = array(
		'id'    => (int) $p->ID,
		'title' => get_the_title( $p ),
	);
}

$page                 = get_page_by_path( 'ollama-test-lab' );
$report['page_found'] = $page instanceof WP_Post ? (int) $page->ID : 0;
$report['seeded_option'] = get_option( 'nvoos_ollama_demo_seeded' );

if ( $page instanceof WP_Post ) {
	$report['content_len']    = strlen( $page->post_content );
	$report['content_head']   = substr( $page->post_content, 0, 400 );
	$report['db_content_len'] = strlen( (string) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT post_content FROM ' . $GLOBALS['wpdb']->posts . ' WHERE ID = %d', $page->ID ) ) );

	// Bypass the the_content filter chain: shortcodes directly.
	$raw = do_shortcode( wpautop( $page->post_content ) );
	$report['do_shortcode_len'] = strlen( $raw );
	$report['do_shortcode_head'] = substr( $raw, 0, 300 );
	file_put_contents( '/verify-out/shortcode-out.html', $raw );

	// Extract every inline script from the shortcode output so the host can
	// node --check each block.
	if ( ! is_dir( '/verify-out/js' ) ) {
		mkdir( '/verify-out/js', 0777, true );
	}
	preg_match_all( '#<script\b[^>]*>(.*?)</script>#s', $raw, $jsm );
	foreach ( $jsm[1] as $jdx => $jsrc ) {
		if ( trim( $jsrc ) !== '' ) {
			file_put_contents( '/verify-out/js/block-' . ( $jdx + 1 ) . '.js', $jsrc );
		}
	}

	$GLOBALS['post'] = $page;
	setup_postdata( $page );

	// Render the REAL page through the active theme's template loader +
	// admin bar, matching what a browser receives as closely as possible.
	// Fake the main query (the runPHP request already has one), then drive
	// template-loader the same way wp-blog-header does.
	$GLOBALS['wp_query']     = new WP_Query( array( 'pagename' => 'ollama-test-lab' ) );
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
	$GLOBALS['post']         = $GLOBALS['wp_query']->posts[0] ?? null;
	setup_postdata( $GLOBALS['post'] );

	ob_start();
	require ABSPATH . WPINC . '/template-loader.php';
	$real = ob_get_clean();
	file_put_contents( '/verify-out/real-render.html', $real );
	$report['real_render_len'] = strlen( $real );

	ob_start();
	?>
<!DOCTYPE html>
<html>
<head>
<?php wp_head(); ?>
</head>
<body>
<?php
	echo apply_filters( 'the_content', $page->post_content );
	wp_footer();
?>
</body>
</html>
	<?php
	$html = ob_get_clean();
	wp_reset_postdata();

	file_put_contents( '/verify-out/page.html', $html );

	$lines                 = preg_split( '/\r\n|\r|\n/', $html );
	$report['total_lines'] = count( $lines );
	foreach ( array( 663, 664, 665, 666, 667, 668, 669, 670 ) as $n ) {
		$report[ 'line_' . $n ] = isset( $lines[ $n - 1 ] ) ? $lines[ $n - 1 ] : '';
	}

	// Non-ASCII line inventory (first 30).
	$na_lines = array();
	foreach ( $lines as $i => $line ) {
		if ( preg_match( '/[^\x20-\x7E\t]/', $line ) ) {
			$na_lines[] = array(
				'line'   => $i + 1,
				'sample' => substr( $line, 0, 160 ),
			);
			if ( count( $na_lines ) >= 30 ) {
				break;
			}
		}
	}
	$report['nonascii_lines'] = $na_lines;

	// Inline script inventory.
	preg_match_all( '#<script\b[^>]*>(.*?)</script>#s', $html, $m );
	$scripts = array();
	foreach ( $m[1] as $idx => $src ) {
		$entry = array(
			'index'    => $idx + 1,
			'length'   => strlen( $src ),
			'head'     => substr( $src, 0, 90 ),
			'nonascii' => array(),
			'ctrl'     => 0,
		);
		if ( preg_match( '/[^\x20-\x7E\r\n\t]/', $src ) ) {
			preg_match_all( '/[^\x20-\x7E\r\n\t]/', $src, $na );
			foreach ( array_slice( array_unique( $na[0] ), 0, 10 ) as $ch ) {
				$entry['nonascii'][] = bin2hex( $ch );
			}
		}
		if ( preg_match_all( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $src, $ctrl ) ) {
			$entry['ctrl'] = count( $ctrl[0] );
		}
		$scripts[] = $entry;
	}
	$report['scripts'] = $scripts;
}

file_put_contents(
	'/verify-out/report.json',
	json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
);
