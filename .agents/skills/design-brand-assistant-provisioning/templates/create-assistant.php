<?php
/**
 * Create / update the "{{ASSISTANT_TITLE}}" assistant (NV oOS Pro Toolkit).
 *
 * Idempotent: re-running updates the existing assistant (matched by title)
 * instead of duplicating it. The system prompt below mirrors
 * assistant-system-prompt.md — keep both in sync.
 *
 * Run from the Design Stack repo root on Windows:
 *   wsl docker compose exec -T wordpress php < projects/{{brand}}/create-assistant.php
 *
 * Usage:
 *   1. Replace {{ASSISTANT_TITLE}}, the {{PROMPT}} nowdoc content, and any
 *      tool slugs your deployment does not register (check
 *      `wp mcp-ai tool list` first — slugs below are verified on v1.1.79).
 *   2. The script prints the assistant ID and the next step (credential).
 */

define( 'WP_USE_THEMES', false );
require_once '/var/www/html/wp-load.php';

$title = '{{ASSISTANT_TITLE}}';

// The prompt is a PHP NOWDOC (no variable interpolation) — safe to paste
// any text. Avoid angle-bracket placeholders; sanitization strips them.
$prompt = <<<'PROMPT'
{{PASTE SYSTEM PROMPT HERE}}
PROMPT;

// Verified brand-manager tool set (base slugs; resolve to _validated
// variants on the MCP surface). Trim to what `wp mcp-ai tool list` shows.
$tools = array(
	// Content & publishing.
	'create_post', 'save_post', 'get_recent_posts', 'search_content',
	'get_site_summary', 'get_user_info',
	// Research.
	'web_search', 'web_search_validated', 'deep_research',
	'run_crawl4ai_job', 'semantic_content_search',
	// Image generation.
	'generate_gemini_image', 'generate_gemini_image_validated',
	'generate_openai_image', 'generate_openai_image_validated',
	'edit_gemini_image', 'edit_gemini_image_validated', 'edit_openai_image',
	'create_image_variation',
	// Vision & captioning.
	'analyze_image', 'extract_image_text', 'generate_image_caption',
	'generate_image_caption_validated', 'generate_image_alt_text',
	'generate_image_alt_text_validated',
	// Image optimization.
	'resize_image', 'crop_image', 'rotate_image', 'convert_image_format',
	'remove_background', 'vectorize_image',
	// Video & audio.
	'generate_sora_video', 'generate_veo_video', 'check_video_status',
	'extract_video_frames', 'transcode_video', 'generate_music',
	'generate_music_validated',
	// Social publishing.
	'post_facebook_instagram', 'post_linkedin_update',
	'get_facebook_instagram_insights', 'get_linkedin_insights',
	'download_facebook_page_images', 'download_instagram_page_images',
	// Pro scheduling.
	'create_pro_schedule', 'update_pro_schedule', 'delete_pro_schedule',
	'list_pro_schedules', 'get_schedule_run_history',
	'dry_run_pro_schedule', 'schedule_channel_broadcast',
	'get_schedule_latest_result',
	// Calendar.
	'list_google_calendars', 'list_google_calendar_events',
	'create_google_calendar_event', 'update_google_calendar_event',
	'quick_add_google_calendar_event', 'check_google_calendar_availability',
	// Communication & delivery.
	'send_telegram_message', 'get_telegram_updates',
	// Skills, memory & knowledge.
	'load_skill', 'retrieve_agent_memory', 'store_agent_context',
	'recall_memory', 'wake_up_context', 'semantic_context_search',
	'scope_memory', 'batch_manage_memory',
	// Paper Store (drafts & research).
	'paper_store_list', 'paper_store_read', 'paper_store_search',
	'paper_store_write', 'paper_store_update',
	// OKF knowledge bundles.
	'okf_browse', 'okf_read_concept', 'okf_search', 'okf_traverse',
	'okf_list_bundles', 'okf_write_concept',
	// Platform integration.
	'toolkit_cpt', 'remote_wp_connection',
	// Utilities.
	'list_mcp_tools', 'list_available_models', 'count_tokens',
	'get_environment_status', 'get_site_health', 'submit_document_prompt',
	'create_chart', 'create_chart_validated', 'create_text_embeddings',
);

// Reuse the existing assistant if present (idempotent).
$existing = get_posts( array(
	'post_type'   => 'mcp_ai_assistant',
	'title'       => $title,
	'numberposts' => 1,
	'post_status' => 'any',
) );

if ( ! empty( $existing ) ) {
	$post_id = (int) $existing[0]->ID;
	wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
	echo "Updated existing assistant ID: {$post_id}\n";
} else {
	$post_id = wp_insert_post( array(
		'post_type'    => 'mcp_ai_assistant',
		'post_title'   => $title,
		'post_content' => '{{ONE-LINE DESCRIPTION OF WHAT THE ASSISTANT DOES}}',
		'post_status'  => 'publish',
	), true );

	if ( is_wp_error( $post_id ) ) {
		die( 'Failed to create assistant: ' . $post_id->get_error_message() . "\n" );
	}
	echo "Created assistant ID: {$post_id}\n";
}

// Runtime meta. Do not rely on `wp mcp-ai assistant create --model/--system-prompt`:
// those flags write legacy keys the chat runtime does not read.
update_post_meta( $post_id, '_wp_mcp_ai_provider', '{{PROVIDER_SLUG}}' );
update_post_meta( $post_id, '_wp_mcp_ai_model', '{{MODEL_ID}}' );
update_post_meta( $post_id, '_wp_mcp_ai_system_prompt', $prompt );
update_post_meta( $post_id, '_wp_mcp_ai_tools', $tools );
update_post_meta( $post_id, '_wp_mcp_ai_classification', 'internal' );
update_post_meta( $post_id, 'mcp_ai_required_capability', 'manage_options' );

echo 'Meta updated. Tools assigned: ' . count( $tools ) . "\n";
echo "Next step: wsl docker compose exec -T wordpress wp --allow-root mcp-ai credential issue {$post_id} --user=1 --porcelain\n";
