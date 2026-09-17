<?php
// Playground blueprint dev tooling — this snippet is embedded into a
// blueprint's runPHP step by bin/generate-ollama-blueprint.php (which
// strips this opening tag). The guard satisfies Plugin Check's
// direct-access rule when the dev folder is scanned; inside the embedded
// context ABSPATH is always defined, so the guard passes through.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NV oOS × Ollama — demo seed for the WordPress Playground blueprint.
 *
 * This file is EMBEDDED into the blueprint's runPHP step by
 * bin/generate-ollama-blueprint.php, which strips the opening <?php tag.
 * It must stay a self-contained snippet:
 *
 *   - No namespace, no Composer dependencies, no closing ?> tag.
 *   - WordPress functions only (it runs right after wp-load).
 *   - Idempotent: guarded by the nvoos_ollama_demo_seeded option.
 *
 * What it does:
 *   1. Pre-configures the plugin for a local Ollama instance
 *      (http://localhost:11434 — inside Playground that IS the user's
 *      machine, because WordPress runs in the browser).
 *   2. Creates a demo assistant ("Oma", a Project Asteria archivist)
 *      wired to the Ollama provider and sets it as the default assistant.
 *   3. Creates the "Ollama Test Lab" frontend page embedding
 *      [ollama_status] (the bundled mu-plugin) + [mcp_ai_chat].
 *
 * @package WP_MCP_AI
 */

/**
 * Seed the Ollama demo. Idempotent.
 *
 * @return void
 */
function nvoos_ollama_demo_seed(): void {
	if ( get_option( 'nvoos_ollama_demo_seeded' ) ) {
		return;
	}

	// 1. Configure the plugin for local Ollama.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( ! is_array( $settings ) ) {
		$settings = array();
	}
	$settings['enable_ollama']                       = true;
	$settings['ollama_endpoint_url']                 = 'http://localhost:11434';
	$settings['ollama_model']                        = 'llama3.1:8b';
	$settings['ollama_use_openai_compatible_endpoint'] = false;
	$settings['default_provider']                    = 'ollama';
	$settings['default_model']                       = 'llama3.1:8b';

	// Put Ollama first in the provider priority list.
	$priority = isset( $settings['provider_priority_list'] ) && is_array( $settings['provider_priority_list'] )
		? $settings['provider_priority_list']
		: array();
	$priority = array_values( array_diff( $priority, array( 'ollama' ) ) );
	array_unshift( $priority, 'ollama' );
	$settings['provider_priority_list'] = $priority;

	update_option( 'wp_mcp_ai_settings', $settings );

	// 2. Demo assistant: Oma, Project Asteria archivist.
	$assistant_id = 0;
	$existing     = get_page_by_path( 'oma-asteria-guide', OBJECT, 'mcp_ai_assistant' );
	if ( $existing instanceof WP_Post ) {
		$assistant_id = (int) $existing->ID;
	} else {
		$inserted = wp_insert_post(
			array(
				'post_title'  => 'Oma — Asteria Guide',
				'post_name'   => 'oma-asteria-guide',
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_author' => 1,
			),
			true
		);
		if ( ! is_wp_error( $inserted ) ) {
			$assistant_id = (int) $inserted;
			update_post_meta( $assistant_id, '_wp_mcp_ai_provider', 'ollama' );
			update_post_meta( $assistant_id, '_wp_mcp_ai_model', 'llama3.1:8b' );
			update_post_meta( $assistant_id, '_wp_mcp_ai_temperature', 0.7 );
			update_post_meta(
				$assistant_id,
				'_wp_mcp_ai_system_prompt',
				'You are Oma, the sector archivist of Project Asteria, a fictional sci-fi universe. You are warm, precise, and fond of footnotes. Keep answers under 150 words. You are running on a local model via Ollama inside a WordPress Playground demo of NV oOS.'
			);
		}
	}

	if ( $assistant_id > 0 ) {
		$settings                     = get_option( 'wp_mcp_ai_settings', array() );
		$settings['default_assistant'] = $assistant_id;
		update_option( 'wp_mcp_ai_settings', $settings );
	}

	// 3. The Ollama Test Lab page (frontend chat + live status banner).
	if ( ! get_page_by_path( 'ollama-test-lab', OBJECT, 'page' ) ) {
		// Prefer the Pro SPA v2 embedded chat surface (chat-first, drawers,
		// tool shortcuts, OKF drawer); fall back to the legacy chat shortcode
		// when Pro is absent (e.g. base-only zips).
		if ( shortcode_exists( 'nvoos_pro_spa' ) ) {
			// show_sidebar=0 keeps the transcripts/threads/sessions request
			// burst off the cold Playground worker (the Complete bundle boots
			// slowly per request; parallel REST calls can exhaust the worker's
			// messaging budget and crash the instance).
			$chat_shortcode = '[nvoos_pro_spa assistant_id="' . $assistant_id . '" theme="dark" height="720px" show_sidebar="0"]';
		} elseif ( $assistant_id > 0 ) {
			$chat_shortcode = '[mcp_ai_chat assistant="' . $assistant_id . '"]';
		} else {
			$chat_shortcode = '[mcp_ai_chat]';
		}

		$page = '<p>This entire site — WordPress, the NV oOS plugin, and the chat below — is running in <strong>your browser</strong> via WordPress Playground. The chat surface is the Pro SPA v2 embedded mode, powered by <strong>your local Ollama</strong>. No prompts ever leave your machine.</p>' . "\n"
			. '<p>[ollama_status]</p>' . "\n"
			. '<p>' . $chat_shortcode . '</p>' . "\n"
			. '<h2>Not working yet? Three-step setup</h2>' . "\n"
			. '<ol>' . "\n"
			. '<li><strong>Install and run Ollama</strong> — <a href="https://ollama.com/download">ollama.com/download</a>, then pull a model: <code>ollama pull llama3.1:8b</code> (any model you pull works; the demo defaults to llama3.1:8b).</li>' . "\n"
			. '<li><strong>Allow this site\'s origin</strong> — Ollama blocks browser cross-origin requests by default. Windows: <code>setx OLLAMA_ORIGINS "https://playground.wordpress.net,http://localhost,http://127.0.0.1"</code>, then quit Ollama from the tray and relaunch. macOS / Linux: <code>OLLAMA_ORIGINS="https://playground.wordpress.net,http://localhost,http://127.0.0.1" ollama serve</code>.</li>' . "\n"
			. '<li><strong>Refresh this page</strong> — the status banner above turns green and the chat answers.</li>' . "\n"
			. '</ol>' . "\n"
			. '<h2>Browser notes</h2>' . "\n"
			. '<ul>' . "\n"
			. '<li><strong>Firefox</strong>: works out of the box once Ollama allows the origin.</li>' . "\n"
			. '<li><strong>Chrome / Edge</strong>: may show an "allow access to your local network" prompt — allow it. If localhost requests are still blocked, disable the Private Network Access check at <code>chrome://flags/#block-insecure-private-network-requests</code>.</li>' . "\n"
			. '<li><strong>Bulletproof fallback</strong>: run Playground on your machine — <code>npx @wp-playground/cli server</code> — and open this blueprint link on the printed local URL. A local origin reaches local Ollama with zero browser policy friction.</li>' . "\n"
			. '</ul>' . "\n"
			. '<h2>Where things live</h2>' . "\n"
			. '<ul>' . "\n"
			. '<li>Assistant: <a href="/wp-admin/post.php?post=' . (int) $assistant_id . '&action=edit">edit Oma</a> — swap the model, temperature, tools, or system prompt.</li>' . "\n"
			. '<li>Provider: <a href="/wp-admin/admin.php?page=wp-mcp-ai-settings">NV oOS → Settings → Providers → Ollama</a> — endpoint, model, and the "Test connection" button.</li>' . "\n"
			. '</ul>' . "\n";

		wp_insert_post(
			array(
				'post_title'   => 'Ollama Test Lab',
				'post_name'    => 'ollama-test-lab',
				'post_content' => $page,
				'post_status'  => 'publish',
				'post_author'  => 1,
				'post_type'    => 'page',
			),
			true
		);
	}

	update_option( 'nvoos_ollama_demo_seeded', 1 );
}
