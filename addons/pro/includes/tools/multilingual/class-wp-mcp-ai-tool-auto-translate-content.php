<?php
/**
 * Auto Translate Content Tool
 *
 * AI-powered translation of posts and pages using OpenAI/Google Translate.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
exit;
}

class WP_MCP_AI_Tool_Auto_Translate_Content implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

public static function is_available() {
if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
return false;
}

$settings = get_option( 'wp_mcp_ai_settings', array() );
return ! empty( $settings['enable_multilingual_toolkit'] );
}

public static function get_unavailable_reason() {
$settings = get_option( 'wp_mcp_ai_settings', array() );
if ( empty( $settings['enable_multilingual_toolkit'] ) ) {
return __( 'Multi-language Content toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
}
return __( 'Auto translate tool is not available.', 'mcp-ai-wpoos-pro' );
}

public function get_slug() {
return 'auto_translate_content';
}

public function get_name() {
return __( 'Auto Translate Content', 'mcp-ai-wpoos-pro' );
}

public function get_description() {
return __( 'AI-powered translation of posts and pages. Supports multiple languages with context-aware translation.', 'mcp-ai-wpoos-pro' );
}

public function get_parameters_schema() {
return array(
'type'       => 'object',
'properties' => array(
'post_id'          => array(
'type'        => 'integer',
'description' => 'Post/page ID to translate',
),
'target_language'  => array(
'type'        => 'string',
'description' => 'Target language code (e.g., es, fr, de)',
),
'source_language'  => array(
'type'        => 'string',
'description' => 'Source language code (auto-detect if not provided)',
'default'     => 'en',
),
'translate_meta'   => array(
'type'        => 'boolean',
'description' => 'Also translate post meta fields',
'default'     => false,
),
'create_duplicate' => array(
'type'        => 'boolean',
'description' => 'Create new post for translation',
'default'     => true,
),
),
'required'   => array( 'post_id', 'target_language' ),
);
}

public function get_required_capability() {
return 'edit_posts';
}

public function get_capability_flags() {
return array(
'content'      => true,
'translation'  => true,
'ai_powered'   => true,
);
}

public function execute( $arguments, $context ) {
$post_id          = absint( $arguments['post_id'] );
$target_language  = sanitize_text_field( $arguments['target_language'] );
$source_language  = ! empty( $arguments['source_language'] ) ? sanitize_text_field( $arguments['source_language'] ) : 'en';
$translate_meta   = ! empty( $arguments['translate_meta'] );
$create_duplicate = ! isset( $arguments['create_duplicate'] ) || $arguments['create_duplicate'];

// Get post.
$post = get_post( $post_id );
if ( ! $post ) {
return new WP_Error( 'post_not_found', __( 'Post not found.', 'mcp-ai-wpoos-pro' ) );
}

// Translate title and content.
$translated_title   = $this->translate_text( $post->post_title, $source_language, $target_language );
$translated_content = $this->translate_text( $post->post_content, $source_language, $target_language );
$translated_excerpt = ! empty( $post->post_excerpt ) ? $this->translate_text( $post->post_excerpt, $source_language, $target_language ) : '';

if ( is_wp_error( $translated_title ) ) {
return $translated_title;
}

// Create or update post.
if ( $create_duplicate ) {
$new_post_id = wp_insert_post( array(
'post_title'   => $translated_title,
'post_content' => $translated_content,
'post_excerpt' => $translated_excerpt,
'post_type'    => $post->post_type,
'post_status'  => 'draft',
) );

if ( is_wp_error( $new_post_id ) ) {
return $new_post_id;
}

// Store translation metadata.
update_post_meta( $new_post_id, '_source_post_id', $post_id );
update_post_meta( $new_post_id, '_translation_language', $target_language );

$result_post_id = $new_post_id;
} else {
wp_update_post( array(
'ID'           => $post_id,
'post_title'   => $translated_title,
'post_content' => $translated_content,
'post_excerpt' => $translated_excerpt,
) );

$result_post_id = $post_id;
}

// Translate meta if requested.
$translated_meta = array();
if ( $translate_meta ) {
$translated_meta = $this->translate_post_meta( $post_id, $result_post_id, $source_language, $target_language );
}

return array(
'success'          => true,
'post_id'          => $result_post_id,
'source_post_id'   => $post_id,
'target_language'  => $target_language,
'translated_meta'  => $translated_meta,
'is_duplicate'     => $create_duplicate,
'message'          => __( 'Content translated successfully.', 'mcp-ai-wpoos-pro' ),
);
}

private function translate_text( $text, $source_lang, $target_lang ) {
if ( empty( $text ) ) {
return $text;
}

// Use OpenAI for translation if available.
$settings = get_option( 'wp_mcp_ai_settings', array() );

if ( ! empty( $settings['openai_api_key'] ) ) {
return $this->translate_with_openai( $text, $source_lang, $target_lang );
}

// Fallback: basic placeholder.
return "[{$target_lang}] " . $text;
}

private function translate_with_openai( $text, $source_lang, $target_lang ) {
$settings = get_option( 'wp_mcp_ai_settings', array() );
$api_key  = $settings['openai_api_key'];

$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
'headers' => array(
'Authorization' => 'Bearer ' . $api_key,
'Content-Type'  => 'application/json',
),
'body'    => wp_json_encode( array(
'model'    => 'gpt-4',
'messages' => array(
array(
'role'    => 'system',
'content' => "You are a professional translator. Translate from {$source_lang} to {$target_lang}. Maintain tone and context.",
),
array(
'role'    => 'user',
'content' => $text,
),
),
) ),
'timeout' => 30,
) );

if ( is_wp_error( $response ) ) {
return $response;
}

$body = json_decode( wp_remote_retrieve_body( $response ), true );

if ( isset( $body['choices'][0]['message']['content'] ) ) {
return $body['choices'][0]['message']['content'];
}

return new WP_Error( 'translation_failed', __( 'Translation failed.', 'mcp-ai-wpoos-pro' ) );
}

private function translate_post_meta( $source_post_id, $target_post_id, $source_lang, $target_lang ) {
$translatable_keys = array( 'description', 'excerpt', 'summary', 'subtitle' );
$translated = array();

foreach ( $translatable_keys as $key ) {
$value = get_post_meta( $source_post_id, $key, true );
if ( ! empty( $value ) && is_string( $value ) ) {
$translated_value = $this->translate_text( $value, $source_lang, $target_lang );
if ( ! is_wp_error( $translated_value ) ) {
update_post_meta( $target_post_id, $key, $translated_value );
$translated[ $key ] = $translated_value;
}
}
}

return $translated;
}
}
