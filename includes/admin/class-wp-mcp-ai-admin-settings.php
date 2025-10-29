<?php
/**
 * Admin settings for WP MCP AI.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles registration and rendering of the plugin's settings page.
 */
class WP_MCP_AI_Admin_Settings {
    const DEFAULT_MEMORY_MAX_FILE_BYTES = 5242880; // 5 MB.
    const OPTION_NAME = 'wp_mcp_ai_settings';
    const SETTINGS_GROUP = 'wp_mcp_ai_settings_group';
    const PAGE_SLUG = 'wp-mcp-ai-settings';

    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_filter( 'wp_mcp_ai_memory_max_file_bytes', array( $this, 'filter_memory_max_file_bytes' ), 10, 2 );
    }

    /**
     * Returns the option defaults.
     *
     * @return array
     */
    public static function get_default_settings() {
        return array(
            'openai_api_key'       => '',
            'gemini_api_key'       => '',
            'default_assistant'    => 0,
            'enable_logging'       => false,
            'default_model'        => 'gpt-4o-mini',
            'default_gemini_model' => 'gemini-1.5-flash',
            'default_provider'     => 'openai',
            'request_timeout'      => 30,
            'memory_max_file_bytes' => self::DEFAULT_MEMORY_MAX_FILE_BYTES,
            'auth0_domain'         => '',
            'auth0_audience'       => '',
            'auth0_required_scope' => '',
            'delete_on_uninstall'  => false,
            'crawl4ai_base_url'    => '',
            'crawl4ai_api_key'     => '',
            'cloudflare_api_token' => '',
            'cloudflare_zone_id'   => '',
            'mailjet_api_key'      => '',
            'mailjet_api_secret'   => '',
            'mailjet_from_email'   => '',
            'mailjet_from_name'    => '',
            'quickbooks_company_id' => '',
            'quickbooks_api_key'    => '',
            'group_email_capability'      => 'publish_posts',
            'group_email_max_recipients'  => 100,
            'openai_image_model'          => 'gpt-image-1',
            'openai_image_size'           => '1024x1024',
            'openai_image_quality'        => 'standard',
            'openai_image_response_format' => 'b64_json',
            'openai_speech_model'         => 'gpt-4o-mini-tts',
            'openai_speech_voice'         => 'alloy',
            'openai_speech_format'        => 'mp3',
            'chat_colors'           => self::get_default_chat_colors(),
            'allowed_image_mimes'  => array(),
            'allowed_file_mimes'   => array(),
        );
    }

    /**
     * Returns metadata about configurable chat colors.
     *
     * @return array
     */
    public static function get_chat_color_definitions() {
        return array(
            'container-border' => array(
                'label'       => __( 'Container border', 'wp-mcp-ai' ),
                'group'       => 'container',
                'default'     => '#d5d5d5',
                'format'      => 'hex',
                'description' => __( 'Border surrounding the chat interface.', 'wp-mcp-ai' ),
            ),
            'container-background' => array(
                'label'       => __( 'Container background', 'wp-mcp-ai' ),
                'group'       => 'container',
                'default'     => '#fff',
                'format'      => 'hex',
                'description' => __( 'Main background color for the chat container.', 'wp-mcp-ai' ),
            ),
            'container-shadow' => array(
                'label'       => __( 'Container shadow', 'wp-mcp-ai' ),
                'group'       => 'container',
                'default'     => 'rgba(15, 23, 42, 0.08)',
                'format'      => 'rgba',
                'description' => __( 'Drop shadow applied to the chat container.', 'wp-mcp-ai' ),
            ),
            'bubble-neutral-background' => array(
                'label'       => __( 'Default bubble background', 'wp-mcp-ai' ),
                'group'       => 'default-message',
                'default'     => '#f5f5f5',
                'format'      => 'hex',
                'description' => __( 'Background color for neutral chat bubbles.', 'wp-mcp-ai' ),
            ),
            'bubble-neutral-text' => array(
                'label'       => __( 'Default bubble text', 'wp-mcp-ai' ),
                'group'       => 'default-message',
                'default'     => '#111827',
                'format'      => 'hex',
                'description' => __( 'Primary text color inside neutral chat bubbles.', 'wp-mcp-ai' ),
            ),
            'bubble-neutral-border' => array(
                'label'       => __( 'Default bubble border', 'wp-mcp-ai' ),
                'group'       => 'default-message',
                'default'     => 'rgba(148, 163, 184, 0.4)',
                'format'      => 'rgba',
                'description' => __( 'Border color used for neutral chat bubbles.', 'wp-mcp-ai' ),
            ),
            'bubble-neutral-shadow' => array(
                'label'       => __( 'Default bubble shadow', 'wp-mcp-ai' ),
                'group'       => 'default-message',
                'default'     => 'rgba(15, 23, 42, 0.06)',
                'format'      => 'rgba',
                'description' => __( 'Soft shadow beneath neutral chat bubbles.', 'wp-mcp-ai' ),
            ),
            'bubble-heading-text' => array(
                'label'       => __( 'Bubble heading text', 'wp-mcp-ai' ),
                'group'       => 'default-message',
                'default'     => '#1e293b',
                'format'      => 'hex',
                'description' => __( 'Heading color for titles inside chat bubbles.', 'wp-mcp-ai' ),
            ),
            'code-block-background' => array(
                'label'       => __( 'Code block background', 'wp-mcp-ai' ),
                'group'       => 'default-message',
                'default'     => '#0f172a',
                'format'      => 'hex',
                'description' => __( 'Background for preformatted code blocks.', 'wp-mcp-ai' ),
            ),
            'code-block-text' => array(
                'label'       => __( 'Code block text', 'wp-mcp-ai' ),
                'group'       => 'default-message',
                'default'     => '#f8fafc',
                'format'      => 'hex',
                'description' => __( 'Text color for preformatted code blocks.', 'wp-mcp-ai' ),
            ),
            'code-block-border' => array(
                'label'       => __( 'Code block border', 'wp-mcp-ai' ),
                'group'       => 'default-message',
                'default'     => 'rgba(59, 130, 246, 0.25)',
                'format'      => 'rgba',
                'description' => __( 'Outline applied to code blocks.', 'wp-mcp-ai' ),
            ),
            'blockquote-border' => array(
                'label'       => __( 'Blockquote border', 'wp-mcp-ai' ),
                'group'       => 'default-message',
                'default'     => 'rgba(59, 130, 246, 0.4)',
                'format'      => 'rgba',
                'description' => __( 'Accent border for blockquotes within bubbles.', 'wp-mcp-ai' ),
            ),
            'blockquote-background' => array(
                'label'       => __( 'Blockquote background', 'wp-mcp-ai' ),
                'group'       => 'default-message',
                'default'     => '#eef2ff',
                'format'      => 'hex',
                'description' => __( 'Background fill for blockquotes inside chat bubbles.', 'wp-mcp-ai' ),
            ),
            'blockquote-text' => array(
                'label'       => __( 'Blockquote text', 'wp-mcp-ai' ),
                'group'       => 'default-message',
                'default'     => '#1e293b',
                'format'      => 'hex',
                'description' => __( 'Text color for quoted content.', 'wp-mcp-ai' ),
            ),
            'inline-code-background' => array(
                'label'       => __( 'Inline code background', 'wp-mcp-ai' ),
                'group'       => 'default-message',
                'default'     => 'rgba(15, 23, 42, 0.08)',
                'format'      => 'rgba',
                'description' => __( 'Background color for inline code snippets.', 'wp-mcp-ai' ),
            ),
            'inline-code-text' => array(
                'label'       => __( 'Inline code text', 'wp-mcp-ai' ),
                'group'       => 'default-message',
                'default'     => '#0f172a',
                'format'      => 'hex',
                'description' => __( 'Text color for inline code snippets.', 'wp-mcp-ai' ),
            ),
            'bubble-link-text' => array(
                'label'       => __( 'Default link text', 'wp-mcp-ai' ),
                'group'       => 'default-message',
                'default'     => '#fff',
                'format'      => 'hex',
                'description' => __( 'Link color inside neutral chat bubbles.', 'wp-mcp-ai' ),
            ),
            'speech-button-background' => array(
                'label'       => __( 'Speech button background', 'wp-mcp-ai' ),
                'group'       => 'speech',
                'default'     => 'rgba(15, 23, 42, 0.82)',
                'format'      => 'rgba',
                'description' => __( 'Default background for the speech playback button.', 'wp-mcp-ai' ),
            ),
            'speech-button-text' => array(
                'label'       => __( 'Speech button icon', 'wp-mcp-ai' ),
                'group'       => 'speech',
                'default'     => '#fff',
                'format'      => 'hex',
                'description' => __( 'Icon color within the speech playback button.', 'wp-mcp-ai' ),
            ),
            'speech-button-hover-background' => array(
                'label'       => __( 'Speech button hover', 'wp-mcp-ai' ),
                'group'       => 'speech',
                'default'     => 'rgba(15, 23, 42, 0.94)',
                'format'      => 'rgba',
                'description' => __( 'Background color when hovering over the speech button.', 'wp-mcp-ai' ),
            ),
            'speech-button-focus-ring' => array(
                'label'       => __( 'Speech button focus ring', 'wp-mcp-ai' ),
                'group'       => 'speech',
                'default'     => 'rgba(59, 130, 246, 0.45)',
                'format'      => 'rgba',
                'description' => __( 'Outline color when the speech button receives focus.', 'wp-mcp-ai' ),
            ),
            'speech-button-error-background' => array(
                'label'       => __( 'Speech button error', 'wp-mcp-ai' ),
                'group'       => 'speech',
                'default'     => 'rgba(220, 38, 38, 0.88)',
                'format'      => 'rgba',
                'description' => __( 'Background color when a speech error is shown.', 'wp-mcp-ai' ),
            ),
            'user-bubble-gradient-start' => array(
                'label'       => __( 'User bubble gradient start', 'wp-mcp-ai' ),
                'group'       => 'user-message',
                'default'     => '#2747f0',
                'format'      => 'hex',
                'description' => __( 'Starting color for the user message gradient.', 'wp-mcp-ai' ),
            ),
            'user-bubble-gradient-end' => array(
                'label'       => __( 'User bubble gradient end', 'wp-mcp-ai' ),
                'group'       => 'user-message',
                'default'     => '#4855f5',
                'format'      => 'hex',
                'description' => __( 'Ending color for the user message gradient.', 'wp-mcp-ai' ),
            ),
            'user-bubble-text' => array(
                'label'       => __( 'User bubble text', 'wp-mcp-ai' ),
                'group'       => 'user-message',
                'default'     => '#fff',
                'format'      => 'hex',
                'description' => __( 'Text and link color for user messages.', 'wp-mcp-ai' ),
            ),
            'user-bubble-shadow' => array(
                'label'       => __( 'User bubble shadow', 'wp-mcp-ai' ),
                'group'       => 'user-message',
                'default'     => 'rgba(39, 71, 240, 0.35)',
                'format'      => 'rgba',
                'description' => __( 'Shadow cast by user chat bubbles.', 'wp-mcp-ai' ),
            ),
            'assistant-bubble-background' => array(
                'label'       => __( 'Assistant bubble background', 'wp-mcp-ai' ),
                'group'       => 'assistant-message',
                'default'     => '#f8faff',
                'format'      => 'hex',
                'description' => __( 'Background color for assistant responses.', 'wp-mcp-ai' ),
            ),
            'assistant-bubble-border' => array(
                'label'       => __( 'Assistant bubble border', 'wp-mcp-ai' ),
                'group'       => 'assistant-message',
                'default'     => 'rgba(59, 130, 246, 0.25)',
                'format'      => 'rgba',
                'description' => __( 'Border color for assistant responses.', 'wp-mcp-ai' ),
            ),
            'assistant-bubble-shadow' => array(
                'label'       => __( 'Assistant bubble shadow', 'wp-mcp-ai' ),
                'group'       => 'assistant-message',
                'default'     => 'rgba(59, 130, 246, 0.08)',
                'format'      => 'rgba',
                'description' => __( 'Shadow used beneath assistant responses.', 'wp-mcp-ai' ),
            ),
            'assistant-strong-text' => array(
                'label'       => __( 'Assistant strong text', 'wp-mcp-ai' ),
                'group'       => 'assistant-message',
                'default'     => '#1d4ed8',
                'format'      => 'hex',
                'description' => __( 'Accent color for bold text in assistant messages.', 'wp-mcp-ai' ),
            ),
            'assistant-em-text' => array(
                'label'       => __( 'Assistant emphasized text', 'wp-mcp-ai' ),
                'group'       => 'assistant-message',
                'default'     => '#4338ca',
                'format'      => 'hex',
                'description' => __( 'Accent color for italic text in assistant messages.', 'wp-mcp-ai' ),
            ),
            'tool-bubble-background' => array(
                'label'       => __( 'Tool bubble background', 'wp-mcp-ai' ),
                'group'       => 'tool-message',
                'default'     => '#0f172a',
                'format'      => 'hex',
                'description' => __( 'Background color for tool output bubbles.', 'wp-mcp-ai' ),
            ),
            'tool-bubble-text' => array(
                'label'       => __( 'Tool bubble text', 'wp-mcp-ai' ),
                'group'       => 'tool-message',
                'default'     => '#e2e8f0',
                'format'      => 'hex',
                'description' => __( 'Text color used in tool output bubbles.', 'wp-mcp-ai' ),
            ),
            'tool-bubble-border' => array(
                'label'       => __( 'Tool bubble border', 'wp-mcp-ai' ),
                'group'       => 'tool-message',
                'default'     => 'rgba(96, 165, 250, 0.35)',
                'format'      => 'rgba',
                'description' => __( 'Border color for tool output bubbles.', 'wp-mcp-ai' ),
            ),
            'tool-bubble-inner-shadow' => array(
                'label'       => __( 'Tool bubble inner shadow', 'wp-mcp-ai' ),
                'group'       => 'tool-message',
                'default'     => 'rgba(30, 64, 175, 0.4)',
                'format'      => 'rgba',
                'description' => __( 'Inset outline applied inside tool bubbles.', 'wp-mcp-ai' ),
            ),
            'tool-bubble-link-text' => array(
                'label'       => __( 'Tool link text', 'wp-mcp-ai' ),
                'group'       => 'tool-message',
                'default'     => '#93c5fd',
                'format'      => 'hex',
                'description' => __( 'Link color for tool output bubbles.', 'wp-mcp-ai' ),
            ),
            'tool-code-background' => array(
                'label'       => __( 'Tool code background', 'wp-mcp-ai' ),
                'group'       => 'tool-message',
                'default'     => 'rgba(148, 163, 184, 0.18)',
                'format'      => 'rgba',
                'description' => __( 'Background for inline code inside tool outputs.', 'wp-mcp-ai' ),
            ),
            'tool-code-text' => array(
                'label'       => __( 'Tool code text', 'wp-mcp-ai' ),
                'group'       => 'tool-message',
                'default'     => '#f8fafc',
                'format'      => 'hex',
                'description' => __( 'Text color for inline code within tool outputs.', 'wp-mcp-ai' ),
            ),
            'system-bubble-background' => array(
                'label'       => __( 'System bubble background', 'wp-mcp-ai' ),
                'group'       => 'system-message',
                'default'     => '#fef9c3',
                'format'      => 'hex',
                'description' => __( 'Background color for system messages.', 'wp-mcp-ai' ),
            ),
            'system-bubble-text' => array(
                'label'       => __( 'System bubble text', 'wp-mcp-ai' ),
                'group'       => 'system-message',
                'default'     => '#854d0e',
                'format'      => 'hex',
                'description' => __( 'Text color used in system messages.', 'wp-mcp-ai' ),
            ),
            'system-bubble-border' => array(
                'label'       => __( 'System bubble border', 'wp-mcp-ai' ),
                'group'       => 'system-message',
                'default'     => '#facc15',
                'format'      => 'hex',
                'description' => __( 'Accent border for system messages.', 'wp-mcp-ai' ),
            ),
            'status-text' => array(
                'label'       => __( 'Status text', 'wp-mcp-ai' ),
                'group'       => 'status',
                'default'     => '#1d4ed8',
                'format'      => 'hex',
                'description' => __( 'Primary color for status messages.', 'wp-mcp-ai' ),
            ),
            'status-background' => array(
                'label'       => __( 'Status background', 'wp-mcp-ai' ),
                'group'       => 'status',
                'default'     => '#eef2ff',
                'format'      => 'hex',
                'description' => __( 'Background for status notices below the transcript.', 'wp-mcp-ai' ),
            ),
            'status-border' => array(
                'label'       => __( 'Status border', 'wp-mcp-ai' ),
                'group'       => 'status',
                'default'     => '#3b82f6',
                'format'      => 'hex',
                'description' => __( 'Accent border for status notices.', 'wp-mcp-ai' ),
            ),
            'label-text' => array(
                'label'       => __( 'Form label text', 'wp-mcp-ai' ),
                'group'       => 'form',
                'default'     => '#0f172a',
                'format'      => 'hex',
                'description' => __( 'Color used for form field labels.', 'wp-mcp-ai' ),
            ),
            'input-border' => array(
                'label'       => __( 'Input border', 'wp-mcp-ai' ),
                'group'       => 'form',
                'default'     => '#cbd5f5',
                'format'      => 'hex',
                'description' => __( 'Border color for the chat input field.', 'wp-mcp-ai' ),
            ),
            'input-background' => array(
                'label'       => __( 'Input background', 'wp-mcp-ai' ),
                'group'       => 'form',
                'default'     => '#f9fafb',
                'format'      => 'hex',
                'description' => __( 'Background color for the chat input field.', 'wp-mcp-ai' ),
            ),
            'input-focus-border' => array(
                'label'       => __( 'Input focus border', 'wp-mcp-ai' ),
                'group'       => 'form',
                'default'     => '#4361ff',
                'format'      => 'hex',
                'description' => __( 'Border color when the input field is focused.', 'wp-mcp-ai' ),
            ),
            'input-focus-shadow' => array(
                'label'       => __( 'Input focus glow', 'wp-mcp-ai' ),
                'group'       => 'form',
                'default'     => 'rgba(67, 97, 255, 0.2)',
                'format'      => 'rgba',
                'description' => __( 'Glow applied when the input field is focused.', 'wp-mcp-ai' ),
            ),
            'attach-border' => array(
                'label'       => __( 'Attachment button border', 'wp-mcp-ai' ),
                'group'       => 'form',
                'default'     => '#c3c4c7',
                'format'      => 'hex',
                'description' => __( 'Border for the “Attach file” button.', 'wp-mcp-ai' ),
            ),
            'attach-text' => array(
                'label'       => __( 'Attachment button text', 'wp-mcp-ai' ),
                'group'       => 'form',
                'default'     => '#1d2327',
                'format'      => 'hex',
                'description' => __( 'Text color for the “Attach file” button and attachment titles.', 'wp-mcp-ai' ),
            ),
            'attach-hover-background' => array(
                'label'       => __( 'Attachment hover background', 'wp-mcp-ai' ),
                'group'       => 'form',
                'default'     => '#f0f0f0',
                'format'      => 'hex',
                'description' => __( 'Background color when hovering the attachment button.', 'wp-mcp-ai' ),
            ),
            'attach-hover-border' => array(
                'label'       => __( 'Attachment hover border', 'wp-mcp-ai' ),
                'group'       => 'form',
                'default'     => '#a7aaad',
                'format'      => 'hex',
                'description' => __( 'Border color when hovering the attachment button.', 'wp-mcp-ai' ),
            ),
            'submit-gradient-start' => array(
                'label'       => __( 'Submit gradient start', 'wp-mcp-ai' ),
                'group'       => 'actions',
                'default'     => '#3b5bff',
                'format'      => 'hex',
                'description' => __( 'Starting color for the Send button gradient.', 'wp-mcp-ai' ),
            ),
            'submit-gradient-end' => array(
                'label'       => __( 'Submit gradient end', 'wp-mcp-ai' ),
                'group'       => 'actions',
                'default'     => '#7c5cff',
                'format'      => 'hex',
                'description' => __( 'Ending color for the Send button gradient.', 'wp-mcp-ai' ),
            ),
            'submit-text' => array(
                'label'       => __( 'Submit button text', 'wp-mcp-ai' ),
                'group'       => 'actions',
                'default'     => '#fff',
                'format'      => 'hex',
                'description' => __( 'Text color for the Send button.', 'wp-mcp-ai' ),
            ),
            'submit-shadow' => array(
                'label'       => __( 'Submit button shadow', 'wp-mcp-ai' ),
                'group'       => 'actions',
                'default'     => 'rgba(59, 91, 255, 0.35)',
                'format'      => 'rgba',
                'description' => __( 'Shadow below the Send button.', 'wp-mcp-ai' ),
            ),
            'submit-hover-gradient-start' => array(
                'label'       => __( 'Submit hover gradient start', 'wp-mcp-ai' ),
                'group'       => 'actions',
                'default'     => '#324cf8',
                'format'      => 'hex',
                'description' => __( 'Starting gradient color when hovering the Send button.', 'wp-mcp-ai' ),
            ),
            'submit-hover-gradient-end' => array(
                'label'       => __( 'Submit hover gradient end', 'wp-mcp-ai' ),
                'group'       => 'actions',
                'default'     => '#6a4bff',
                'format'      => 'hex',
                'description' => __( 'Ending gradient color when hovering the Send button.', 'wp-mcp-ai' ),
            ),
            'submit-hover-shadow' => array(
                'label'       => __( 'Submit hover shadow', 'wp-mcp-ai' ),
                'group'       => 'actions',
                'default'     => 'rgba(50, 76, 248, 0.4)',
                'format'      => 'rgba',
                'description' => __( 'Shadow applied to the Send button on hover.', 'wp-mcp-ai' ),
            ),
            'submit-disabled-background' => array(
                'label'       => __( 'Submit disabled background', 'wp-mcp-ai' ),
                'group'       => 'actions',
                'default'     => '#9aa5ff',
                'format'      => 'hex',
                'description' => __( 'Background color when the Send button is disabled.', 'wp-mcp-ai' ),
            ),
            'attachments-border' => array(
                'label'       => __( 'Attachments border', 'wp-mcp-ai' ),
                'group'       => 'attachments',
                'default'     => '#e2e4e7',
                'format'      => 'hex',
                'description' => __( 'Border for the attachments container and items.', 'wp-mcp-ai' ),
            ),
            'attachments-background' => array(
                'label'       => __( 'Attachments background', 'wp-mcp-ai' ),
                'group'       => 'attachments',
                'default'     => '#f9fafb',
                'format'      => 'hex',
                'description' => __( 'Background color for the attachments container.', 'wp-mcp-ai' ),
            ),
            'attachments-item-background' => array(
                'label'       => __( 'Attachment item background', 'wp-mcp-ai' ),
                'group'       => 'attachments',
                'default'     => '#fff',
                'format'      => 'hex',
                'description' => __( 'Background color for individual attachment rows.', 'wp-mcp-ai' ),
            ),
            'attachments-meta-text' => array(
                'label'       => __( 'Attachment meta text', 'wp-mcp-ai' ),
                'group'       => 'attachments',
                'default'     => '#646970',
                'format'      => 'hex',
                'description' => __( 'Secondary text color for attachment metadata.', 'wp-mcp-ai' ),
            ),
            'attachments-remove-text' => array(
                'label'       => __( 'Remove link text', 'wp-mcp-ai' ),
                'group'       => 'attachments',
                'default'     => '#3858e9',
                'format'      => 'hex',
                'description' => __( 'Link color for removing attachments.', 'wp-mcp-ai' ),
            ),
            'attachments-remove-hover-background' => array(
                'label'       => __( 'Remove link hover background', 'wp-mcp-ai' ),
                'group'       => 'attachments',
                'default'     => 'rgba(56, 88, 233, 0.1)',
                'format'      => 'rgba',
                'description' => __( 'Background when hovering the attachment remove link.', 'wp-mcp-ai' ),
            ),
            'attachments-remove-hover-text' => array(
                'label'       => __( 'Remove link hover text', 'wp-mcp-ai' ),
                'group'       => 'attachments',
                'default'     => '#2b45b8',
                'format'      => 'hex',
                'description' => __( 'Text color when hovering the attachment remove link.', 'wp-mcp-ai' ),
            ),
            'bubble-attachments-text' => array(
                'label'       => __( 'Bubble attachment text', 'wp-mcp-ai' ),
                'group'       => 'attachments',
                'default'     => '#fff',
                'format'      => 'hex',
                'description' => __( 'Text color for attachments listed inside bubbles.', 'wp-mcp-ai' ),
            ),
            'bubble-attachments-link-text' => array(
                'label'       => __( 'Bubble attachment links', 'wp-mcp-ai' ),
                'group'       => 'attachments',
                'default'     => '#fff',
                'format'      => 'hex',
                'description' => __( 'Link color for attachments displayed within bubbles.', 'wp-mcp-ai' ),
            ),
            'notice-border' => array(
                'label'       => __( 'Alert border', 'wp-mcp-ai' ),
                'group'       => 'alerts',
                'default'     => 'rgba(214, 54, 56, 0.35)',
                'format'      => 'rgba',
                'description' => __( 'Border for alert notices rendered by the shortcode.', 'wp-mcp-ai' ),
            ),
            'notice-background' => array(
                'label'       => __( 'Alert background', 'wp-mcp-ai' ),
                'group'       => 'alerts',
                'default'     => '#fef2f2',
                'format'      => 'hex',
                'description' => __( 'Background for alert notices rendered by the shortcode.', 'wp-mcp-ai' ),
            ),
            'notice-text' => array(
                'label'       => __( 'Alert text', 'wp-mcp-ai' ),
                'group'       => 'alerts',
                'default'     => '#8a1f1f',
                'format'      => 'hex',
                'description' => __( 'Text color for alert notices.', 'wp-mcp-ai' ),
            ),
            'notice-shadow' => array(
                'label'       => __( 'Alert shadow', 'wp-mcp-ai' ),
                'group'       => 'alerts',
                'default'     => 'rgba(214, 54, 56, 0.12)',
                'format'      => 'rgba',
                'description' => __( 'Shadow applied to alert notices.', 'wp-mcp-ai' ),
            ),
        );
    }

    /**
     * Returns the default chat colors indexed by color key.
     *
     * @return array
     */
    public static function get_default_chat_colors() {
        $defaults = array();

        foreach ( self::get_chat_color_definitions() as $key => $definition ) {
            $defaults[ $key ] = $definition['default'];
        }

        return $defaults;
    }

    /**
     * Returns the display labels for color groups.
     *
     * @return array
     */
    public static function get_chat_color_groups() {
        return array(
            'container'         => __( 'Chat container', 'wp-mcp-ai' ),
            'default-message'   => __( 'Default message bubble', 'wp-mcp-ai' ),
            'speech'            => __( 'Speech controls', 'wp-mcp-ai' ),
            'user-message'      => __( 'User messages', 'wp-mcp-ai' ),
            'assistant-message' => __( 'Assistant messages', 'wp-mcp-ai' ),
            'tool-message'      => __( 'Tool messages', 'wp-mcp-ai' ),
            'system-message'    => __( 'System messages', 'wp-mcp-ai' ),
            'status'            => __( 'Status notice', 'wp-mcp-ai' ),
            'form'              => __( 'Form elements', 'wp-mcp-ai' ),
            'actions'           => __( 'Action buttons', 'wp-mcp-ai' ),
            'attachments'       => __( 'Attachments', 'wp-mcp-ai' ),
            'alerts'            => __( 'Alert notice', 'wp-mcp-ai' ),
        );
    }

    /**
     * Retrieve the saved chat colors merged with defaults.
     *
     * @return array
     */
    public static function get_chat_colors() {
        $settings = self::get_settings();

        if ( isset( $settings['chat_colors'] ) && is_array( $settings['chat_colors'] ) ) {
            return array_merge( self::get_default_chat_colors(), $settings['chat_colors'] );
        }

        return self::get_default_chat_colors();
    }

    /**
     * Build CSS that injects the selected chat colors.
     *
     * @return string
     */
    public static function get_chat_color_css() {
        $colors       = self::get_chat_colors();
        $definitions  = self::get_chat_color_definitions();
        $declarations = array();

        foreach ( $colors as $key => $value ) {
            if ( '' === $value || ! isset( $definitions[ $key ] ) ) {
                continue;
            }

            $declarations[] = sprintf( '    --wp-mcp-ai-color-%s: %s;', sanitize_key( $key ), $value );
        }

        if ( empty( $declarations ) ) {
            return '';
        }

        return ".wp-mcp-ai-chat {\n" . implode( "\n", $declarations ) . "\n}\n";
    }

    /**
     * Retrieve the merged settings array.
     *
     * @return array
     */
    public static function get_settings() {
        $saved = get_option( self::OPTION_NAME, array() );

        if ( ! is_array( $saved ) ) {
            $saved = array();
        }

        $settings = wp_parse_args( $saved, self::get_default_settings() );

        if ( ! isset( $settings['chat_colors'] ) || ! is_array( $settings['chat_colors'] ) ) {
            $settings['chat_colors'] = self::get_default_chat_colors();
        } else {
            $settings['chat_colors'] = array_merge( self::get_default_chat_colors(), $settings['chat_colors'] );
        }

        return $settings;
    }

    /**
     * Determine whether debug logging is enabled.
     *
     * @return bool
     */
    public static function is_logging_enabled() {
        $settings = self::get_settings();

        return ! empty( $settings['enable_logging'] );
    }

    /**
     * Write a message to the PHP error log when logging is enabled.
     *
     * @param string $message Message to log.
     * @param array  $context Additional context to encode with the message.
     */
    public static function log( $message, $context = array() ) {
        WP_MCP_AI_Logger::log_event( 'debug', (string) $message, $context );
    }

    /**
     * Register the settings page within the WordPress admin.
     */
    public function register_settings_page() {
        add_options_page(
            __( 'WP MCP AI', 'wp-mcp-ai' ),
            __( 'WP MCP AI', 'wp-mcp-ai' ),
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Register the settings, sections, and fields exposed in the admin UI.
     */
    public function register_settings() {
        register_setting( self::SETTINGS_GROUP, self::OPTION_NAME, array( $this, 'sanitize_settings' ) );

        add_settings_section(
            'wp_mcp_ai_openai_section',
            __( 'OpenAI Configuration', 'wp-mcp-ai' ),
            '__return_false',
            self::PAGE_SLUG
        );

        add_settings_field(
            'openai_api_key',
            __( 'OpenAI API Key', 'wp-mcp-ai' ),
            array( $this, 'render_api_key_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_openai_section'
        );

        add_settings_field(
            'default_model',
            __( 'Default OpenAI Model', 'wp-mcp-ai' ),
            array( $this, 'render_default_model_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_openai_section'
        );

        add_settings_field(
            'request_timeout',
            __( 'Request Timeout (seconds)', 'wp-mcp-ai' ),
            array( $this, 'render_timeout_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_openai_section'
        );

        add_settings_section(
            'wp_mcp_ai_gemini_section',
            __( 'Gemini Configuration', 'wp-mcp-ai' ),
            '__return_false',
            self::PAGE_SLUG
        );

        add_settings_field(
            'gemini_api_key',
            __( 'Gemini API Key', 'wp-mcp-ai' ),
            array( $this, 'render_gemini_api_key_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_gemini_section'
        );

        add_settings_field(
            'default_gemini_model',
            __( 'Default Gemini Model', 'wp-mcp-ai' ),
            array( $this, 'render_default_gemini_model_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_gemini_section'
        );

        add_settings_section(
            'wp_mcp_ai_authentication_section',
            __( 'Authentication', 'wp-mcp-ai' ),
            '__return_false',
            self::PAGE_SLUG
        );

        add_settings_field(
            'auth0_domain',
            __( 'Auth0 Domain', 'wp-mcp-ai' ),
            array( $this, 'render_auth0_domain_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_authentication_section'
        );

        add_settings_field(
            'auth0_audience',
            __( 'Auth0 API Audience', 'wp-mcp-ai' ),
            array( $this, 'render_auth0_audience_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_authentication_section'
        );

        add_settings_field(
            'auth0_required_scope',
            __( 'Required Access Scope', 'wp-mcp-ai' ),
            array( $this, 'render_auth0_scope_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_authentication_section'
        );

        add_settings_section(
            'wp_mcp_ai_assistant_section',
            __( 'Assistant Defaults', 'wp-mcp-ai' ),
            '__return_false',
            self::PAGE_SLUG
        );

        add_settings_field(
            'default_provider',
            __( 'Default Provider', 'wp-mcp-ai' ),
            array( $this, 'render_default_provider_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_assistant_section'
        );

        add_settings_field(
            'default_assistant',
            __( 'Default Assistant', 'wp-mcp-ai' ),
            array( $this, 'render_default_assistant_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_assistant_section'
        );

        add_settings_field(
            'enable_logging',
            __( 'Enable Logging', 'wp-mcp-ai' ),
            array( $this, 'render_logging_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_assistant_section'
        );

        add_settings_section(
            'wp_mcp_ai_attachments_section',
            __( 'Attachments', 'wp-mcp-ai' ),
            '__return_false',
            self::PAGE_SLUG
        );

        add_settings_field(
            'allowed_image_mimes',
            __( 'Allowed Image MIME Types', 'wp-mcp-ai' ),
            array( $this, 'render_allowed_image_mimes_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_attachments_section'
        );

        add_settings_field(
            'allowed_file_mimes',
            __( 'Allowed File MIME Types', 'wp-mcp-ai' ),
            array( $this, 'render_allowed_file_mimes_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_attachments_section'
        );

        add_settings_field(
            'memory_max_file_bytes',
            __( 'Maximum Memory File Size', 'wp-mcp-ai' ),
            array( $this, 'render_memory_max_file_bytes_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_attachments_section'
        );

        add_settings_section(
            'wp_mcp_ai_chat_colors_section',
            __( 'Chat Appearance', 'wp-mcp-ai' ),
            array( $this, 'render_chat_colors_section_description' ),
            self::PAGE_SLUG
        );

        add_settings_field(
            'chat_colors',
            __( 'Interface Colors', 'wp-mcp-ai' ),
            array( $this, 'render_chat_colors_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_chat_colors_section'
        );

        add_settings_section(
            'wp_mcp_ai_quickbooks_section',
            __( 'QuickBooks Online', 'wp-mcp-ai' ),
            array( $this, 'render_quickbooks_section_description' ),
            self::PAGE_SLUG
        );

        add_settings_field(
            'quickbooks_company_id',
            __( 'Company ID', 'wp-mcp-ai' ),
            array( $this, 'render_quickbooks_company_id_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_quickbooks_section'
        );

        add_settings_field(
            'quickbooks_api_key',
            __( 'API Key / Access Token', 'wp-mcp-ai' ),
            array( $this, 'render_quickbooks_api_key_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_quickbooks_section'
        );

        add_settings_section(
            'wp_mcp_ai_tools_section',
            __( 'Tools', 'wp-mcp-ai' ),
            array( $this, 'render_tools_section_description' ),
            self::PAGE_SLUG
        );

        add_settings_field(
            'openai_image_model',
            __( 'OpenAI Image Model', 'wp-mcp-ai' ),
            array( $this, 'render_openai_image_model_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_tools_section'
        );

        add_settings_field(
            'openai_image_size',
            __( 'Default Image Size', 'wp-mcp-ai' ),
            array( $this, 'render_openai_image_size_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_tools_section'
        );

        add_settings_field(
            'openai_image_quality',
            __( 'Default Image Quality', 'wp-mcp-ai' ),
            array( $this, 'render_openai_image_quality_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_tools_section'
        );

        add_settings_field(
            'openai_image_response_format',
            __( 'Image Output Type', 'wp-mcp-ai' ),
            array( $this, 'render_openai_image_response_format_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_tools_section'
        );

        add_settings_field(
            'openai_speech_model',
            __( 'OpenAI Speech Model', 'wp-mcp-ai' ),
            array( $this, 'render_openai_speech_model_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_tools_section'
        );

        add_settings_field(
            'openai_speech_voice',
            __( 'Default Speech Voice', 'wp-mcp-ai' ),
            array( $this, 'render_openai_speech_voice_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_tools_section'
        );

        add_settings_field(
            'openai_speech_format',
            __( 'Default Speech Format', 'wp-mcp-ai' ),
            array( $this, 'render_openai_speech_format_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_tools_section'
        );

        add_settings_field(
            'crawl4ai_base_url',
            __( 'Crawl4AI Base URL', 'wp-mcp-ai' ),
            array( $this, 'render_crawl4ai_base_url_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_tools_section'
        );

        add_settings_field(
            'crawl4ai_api_key',
            __( 'Crawl4AI API Key', 'wp-mcp-ai' ),
            array( $this, 'render_crawl4ai_api_key_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_tools_section'
        );

        add_settings_field(
            'cloudflare_zone_id',
            __( 'Cloudflare Zone ID', 'wp-mcp-ai' ),
            array( $this, 'render_cloudflare_zone_id_field' ),
            'mailjet_api_key',
            __( 'Mailjet API Key', 'wp-mcp-ai' ),
            array( $this, 'render_mailjet_api_key_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_tools_section'
        );

        add_settings_field(
            'cloudflare_api_token',
            __( 'Cloudflare API Token', 'wp-mcp-ai' ),
            array( $this, 'render_cloudflare_api_token_field' ),
            'mailjet_api_secret',
            __( 'Mailjet API Secret', 'wp-mcp-ai' ),
            array( $this, 'render_mailjet_api_secret_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_tools_section'
        );

        add_settings_field(
            'mailjet_from_email',
            __( 'Mailjet From Email', 'wp-mcp-ai' ),
            array( $this, 'render_mailjet_from_email_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_tools_section'
        );

        add_settings_field(
            'mailjet_from_name',
            __( 'Mailjet From Name', 'wp-mcp-ai' ),
            array( $this, 'render_mailjet_from_name_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_tools_section'
        );

        add_settings_field(
            'group_email_capability',
            __( 'Group Email Capability', 'wp-mcp-ai' ),
            array( $this, 'render_group_email_capability_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_tools_section'
        );

        add_settings_field(
            'group_email_max_recipients',
            __( 'Group Email Recipient Limit', 'wp-mcp-ai' ),
            array( $this, 'render_group_email_max_recipients_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_tools_section'
        );

        add_settings_section(
            'wp_mcp_ai_maintenance_section',
            __( 'Maintenance', 'wp-mcp-ai' ),
            '__return_false',
            self::PAGE_SLUG
        );

        add_settings_field(
            'delete_on_uninstall',
            __( 'Remove Data on Uninstall', 'wp-mcp-ai' ),
            array( $this, 'render_delete_on_uninstall_field' ),
            self::PAGE_SLUG,
            'wp_mcp_ai_maintenance_section'
        );
    }

    /**
     * Sanitize the submitted settings array.
     *
     * @param array $settings Submitted values.
     * @return array
     */
    public function sanitize_settings( $settings ) {
        $clean = self::get_default_settings();

        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        $clean['chat_colors'] = self::get_default_chat_colors();

        if ( isset( $settings['chat_colors'] ) && is_array( $settings['chat_colors'] ) ) {
            $definitions = self::get_chat_color_definitions();

            foreach ( $clean['chat_colors'] as $color_key => $default_value ) {
                if ( ! isset( $definitions[ $color_key ] ) ) {
                    continue;
                }

                if ( isset( $settings['chat_colors'][ $color_key ] ) ) {
                    $clean['chat_colors'][ $color_key ] = self::sanitize_color_value(
                        $settings['chat_colors'][ $color_key ],
                        $definitions[ $color_key ]['format'],
                        $default_value
                    );
                }
            }
        }

        if ( isset( $settings['openai_api_key'] ) ) {
            $clean['openai_api_key'] = trim( sanitize_text_field( $settings['openai_api_key'] ) );
        }

        if ( isset( $settings['gemini_api_key'] ) ) {
            $clean['gemini_api_key'] = trim( sanitize_text_field( $settings['gemini_api_key'] ) );
        }

        if ( isset( $settings['default_assistant'] ) ) {
            $clean['default_assistant'] = absint( $settings['default_assistant'] );
        }

        $clean['enable_logging'] = ! empty( $settings['enable_logging'] );

        if ( isset( $settings['default_model'] ) ) {
            $clean['default_model'] = sanitize_text_field( $settings['default_model'] );
        }

        if ( isset( $settings['default_gemini_model'] ) ) {
            $clean['default_gemini_model'] = sanitize_text_field( $settings['default_gemini_model'] );
        }

        if ( isset( $settings['default_provider'] ) ) {
            $provider = sanitize_key( $settings['default_provider'] );
            $allowed  = apply_filters( 'wp_mcp_ai_allowed_providers', array( 'openai', 'gemini' ) );

            if ( ! is_array( $allowed ) ) {
                $allowed = array( 'openai', 'gemini' );
            }

            if ( in_array( $provider, $allowed, true ) ) {
                $clean['default_provider'] = $provider;
            }
        }

        if ( isset( $settings['request_timeout'] ) ) {
            $timeout = absint( $settings['request_timeout'] );

            if ( $timeout > 0 ) {
                $clean['request_timeout'] = max( 5, $timeout );
            }
        }

        if ( isset( $settings['memory_max_file_bytes'] ) ) {
            $choices = $this->get_memory_max_file_size_choices();
            $choice  = absint( $settings['memory_max_file_bytes'] );

            if ( isset( $choices[ $choice ] ) ) {
                $clean['memory_max_file_bytes'] = $choice;
            }
        }

        if ( isset( $settings['auth0_domain'] ) ) {
            $clean['auth0_domain'] = trim( sanitize_text_field( $settings['auth0_domain'] ) );
        }

        if ( isset( $settings['auth0_audience'] ) ) {
            $clean['auth0_audience'] = trim( sanitize_text_field( $settings['auth0_audience'] ) );
        }

        if ( isset( $settings['auth0_required_scope'] ) ) {
            $clean['auth0_required_scope'] = trim( sanitize_text_field( $settings['auth0_required_scope'] ) );
        }

        $clean['delete_on_uninstall'] = ! empty( $settings['delete_on_uninstall'] );

        if ( isset( $settings['crawl4ai_base_url'] ) ) {
            $base_url = trim( $settings['crawl4ai_base_url'] );

            $clean['crawl4ai_base_url'] = $base_url ? esc_url_raw( $base_url ) : '';
        }

        if ( isset( $settings['crawl4ai_api_key'] ) ) {
            $clean['crawl4ai_api_key'] = trim( sanitize_text_field( $settings['crawl4ai_api_key'] ) );
        }

        if ( isset( $settings['cloudflare_api_token'] ) ) {
            $clean['cloudflare_api_token'] = trim( sanitize_text_field( $settings['cloudflare_api_token'] ) );
        }

        if ( isset( $settings['cloudflare_zone_id'] ) ) {
            $clean['cloudflare_zone_id'] = trim( sanitize_text_field( $settings['cloudflare_zone_id'] ) );
        if ( isset( $settings['mailjet_api_key'] ) ) {
            $clean['mailjet_api_key'] = trim( sanitize_text_field( $settings['mailjet_api_key'] ) );
        }

        if ( isset( $settings['mailjet_api_secret'] ) ) {
            $clean['mailjet_api_secret'] = trim( sanitize_text_field( $settings['mailjet_api_secret'] ) );
        }

        if ( isset( $settings['mailjet_from_email'] ) ) {
            $clean['mailjet_from_email'] = sanitize_email( $settings['mailjet_from_email'] );
        }

        if ( isset( $settings['mailjet_from_name'] ) ) {
            $clean['mailjet_from_name'] = sanitize_text_field( $settings['mailjet_from_name'] );
        if ( isset( $settings['quickbooks_company_id'] ) ) {
            $clean['quickbooks_company_id'] = trim( sanitize_text_field( $settings['quickbooks_company_id'] ) );
        }

        if ( isset( $settings['quickbooks_api_key'] ) ) {
            $clean['quickbooks_api_key'] = trim( sanitize_text_field( $settings['quickbooks_api_key'] ) );
        }

        if ( isset( $settings['group_email_capability'] ) ) {
            $clean['group_email_capability'] = sanitize_key( $settings['group_email_capability'] );
        }

        if ( isset( $settings['group_email_max_recipients'] ) ) {
            $clean['group_email_max_recipients'] = absint( $settings['group_email_max_recipients'] );
        }

        if ( isset( $settings['openai_image_model'] ) ) {
            $model  = sanitize_text_field( $settings['openai_image_model'] );
            $models = $this->get_openai_image_model_choices();

            if ( isset( $models[ $model ] ) ) {
                $clean['openai_image_model'] = $model;
            }
        }

        if ( isset( $settings['openai_image_size'] ) ) {
            $size   = sanitize_text_field( $settings['openai_image_size'] );
            $sizes  = array_keys( $this->get_openai_image_size_choices() );

            if ( in_array( $size, $sizes, true ) ) {
                $clean['openai_image_size'] = $size;
            }
        }

        if ( isset( $settings['openai_image_quality'] ) ) {
            $quality   = sanitize_key( $settings['openai_image_quality'] );
            $qualities = array_keys( $this->get_openai_image_quality_choices() );

            if ( in_array( $quality, $qualities, true ) ) {
                $clean['openai_image_quality'] = $quality;
            }
        }

        if ( isset( $settings['openai_image_response_format'] ) ) {
            $response_format  = sanitize_key( $settings['openai_image_response_format'] );
            $response_formats = array_keys( $this->get_openai_image_response_format_choices() );

            if ( in_array( $response_format, $response_formats, true ) ) {
                $supports_response_format = true;

                if ( class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
                    $supports_response_format = WP_MCP_AI_OpenAI_Client::image_model_supports_response_format( $clean['openai_image_model'] );
                }

                if ( $supports_response_format ) {
                    $clean['openai_image_response_format'] = $response_format;
                } else {
                    $clean['openai_image_response_format'] = 'b64_json';
                }
            }
        }

        if ( isset( $settings['openai_speech_model'] ) ) {
            $clean['openai_speech_model'] = sanitize_text_field( $settings['openai_speech_model'] );
        }

        if ( isset( $settings['openai_speech_voice'] ) ) {
            $clean['openai_speech_voice'] = sanitize_key( $settings['openai_speech_voice'] );
        }

        if ( isset( $settings['openai_speech_format'] ) ) {
            $format   = sanitize_key( $settings['openai_speech_format'] );
            $formats  = array_keys( $this->get_openai_speech_format_choices() );

            if ( in_array( $format, $formats, true ) ) {
                $clean['openai_speech_format'] = $format;
            }
        }

        if ( isset( $settings['allowed_image_mimes'] ) ) {
            $clean['allowed_image_mimes'] = $this->parse_mime_list( $settings['allowed_image_mimes'] );
        }

        if ( isset( $settings['allowed_file_mimes'] ) ) {
            $clean['allowed_file_mimes'] = $this->parse_mime_list( $settings['allowed_file_mimes'] );
        }

        return $clean;
    }

    /**
     * Sanitize a submitted color value.
     *
     * @param string $value   Submitted value.
     * @param string $format  Expected format (hex or rgba).
     * @param string $default Default color to fall back to.
     * @return string
     */
    private static function sanitize_color_value( $value, $format, $default ) {
        $value = trim( (string) $value );

        if ( '' === $value ) {
            return $default;
        }

        if ( 'rgba' === strtolower( $format ) ) {
            $pattern = '/^rgba\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(0|0?\.\d+|1(?:\.0+)?)\s*\)$/i';

            if ( preg_match( $pattern, $value, $matches ) ) {
                $red   = min( 255, max( 0, (int) $matches[1] ) );
                $green = min( 255, max( 0, (int) $matches[2] ) );
                $blue  = min( 255, max( 0, (int) $matches[3] ) );
                $alpha = min( 1, max( 0, (float) $matches[4] ) );

                $alpha_string = rtrim( rtrim( sprintf( '%.3f', $alpha ), '0' ), '.' );

                if ( '' === $alpha_string ) {
                    $alpha_string = '0';
                }

                return sprintf( 'rgba(%d, %d, %d, %s)', $red, $green, $blue, $alpha_string );
            }

            return $default;
        }

        $color = sanitize_hex_color( $value );

        return $color ? $color : $default;
    }

    /**
     * Enqueue assets used on the settings screen.
     *
     * @param string $hook Current admin hook suffix.
     */
    public function enqueue_admin_assets( $hook ) {
        if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
            return;
        }

        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script(
            'wp-mcp-ai-admin-settings',
            WP_MCP_AI_URL . 'assets/js/admin-settings.js',
            array( 'wp-color-picker', 'jquery' ),
            WP_MCP_AI_VERSION,
            true
        );

        $inline_styles = '.wp-mcp-ai-chat-colors__group{margin-bottom:1.5rem;padding:1rem;background:#fff;border:1px solid #dcdcde;border-radius:4px;}'
            . '.wp-mcp-ai-chat-colors__group legend{font-weight:600;margin-bottom:0.5rem;}'
            . '.wp-mcp-ai-chat-colors__field{margin-bottom:1rem;}'
            . '.wp-mcp-ai-chat-colors__field label{display:block;font-weight:600;margin-bottom:0.25rem;}';

        wp_add_inline_style( 'wp-color-picker', $inline_styles );
    }

    /**
     * Render the settings page contents.
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = self::get_settings();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'WP MCP AI Settings', 'wp-mcp-ai' ); ?></h1>
            <form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post">
                <?php
                settings_fields( self::SETTINGS_GROUP );
                do_settings_sections( self::PAGE_SLUG );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render the description for the chat colors section.
     */
    public function render_chat_colors_section_description() {
        echo '<p>' . esc_html__( 'Customize the palette used by the front-end chat interface. Leave a field empty to keep its default color.', 'wp-mcp-ai' ) . '</p>';
    }

    /**
     * Render the chat color controls.
     */
    public function render_chat_colors_field() {
        $settings    = self::get_settings();
        $colors      = isset( $settings['chat_colors'] ) && is_array( $settings['chat_colors'] ) ? $settings['chat_colors'] : self::get_default_chat_colors();
        $definitions = self::get_chat_color_definitions();
        $groups      = self::get_chat_color_groups();

        echo '<div class="wp-mcp-ai-chat-colors">';

        foreach ( $groups as $group_key => $group_label ) {
            $group_colors = array();

            foreach ( $definitions as $color_key => $definition ) {
                if ( isset( $definition['group'] ) && $group_key === $definition['group'] ) {
                    $group_colors[ $color_key ] = $definition;
                }
            }

            if ( empty( $group_colors ) ) {
                continue;
            }

            echo '<fieldset class="wp-mcp-ai-chat-colors__group">';
            echo '<legend>' . esc_html( $group_label ) . '</legend>';

            foreach ( $group_colors as $color_key => $definition ) {
                $input_id     = 'wp-mcp-ai-color-' . sanitize_html_class( $color_key );
                $value        = isset( $colors[ $color_key ] ) ? $colors[ $color_key ] : $definition['default'];
                $format       = isset( $definition['format'] ) ? strtolower( $definition['format'] ) : 'hex';
                $descriptions = array();

                if ( ! empty( $definition['description'] ) ) {
                    $descriptions[] = $definition['description'];
                }

                if ( 'rgba' === $format ) {
                    $descriptions[] = __( 'Enter a value in rgba(R, G, B, A) format.', 'wp-mcp-ai' );
                }

                echo '<div class="wp-mcp-ai-chat-colors__field">';
                echo '<label for="' . esc_attr( $input_id ) . '">' . esc_html( $definition['label'] ) . '</label>';
                echo '<input type="text" id="' . esc_attr( $input_id ) . '" class="regular-text wp-mcp-ai-color-field" name="' . esc_attr( self::OPTION_NAME ) . '[chat_colors][' . esc_attr( $color_key ) . ']" value="' . esc_attr( $value ) . '" data-format="' . esc_attr( $format ) . '" data-default-color="' . esc_attr( $definition['default'] ) . '" />';

                foreach ( $descriptions as $text ) {
                    echo '<p class="description">' . esc_html( $text ) . '</p>';
                }

                echo '</div>';
            }

            echo '</fieldset>';
        }

        echo '</div>';
    }

    /**
     * Render the Auth0 domain field.
     */
    public function render_auth0_domain_field() {
        $settings = self::get_settings();
        ?>
        <input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[auth0_domain]" value="<?php echo esc_attr( $settings['auth0_domain'] ); ?>" class="regular-text" placeholder="example.us.auth0.com" />
        <p class="description"><?php esc_html_e( 'The Auth0 tenant domain that issues access tokens for remote MCP assistants.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the Auth0 audience field.
     */
    public function render_auth0_audience_field() {
        $settings = self::get_settings();
        ?>
        <input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[auth0_audience]" value="<?php echo esc_attr( $settings['auth0_audience'] ); ?>" class="regular-text" placeholder="https://api.example.com/" />
        <p class="description"><?php esc_html_e( 'Optional. When provided, bearer tokens must include this audience (or API Identifier) claim.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the Auth0 scope field.
     */
    public function render_auth0_scope_field() {
        $settings = self::get_settings();
        ?>
        <input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[auth0_required_scope]" value="<?php echo esc_attr( $settings['auth0_required_scope'] ); ?>" class="regular-text" placeholder="mcp:invoke" />
        <p class="description"><?php esc_html_e( 'Optional space-delimited scope that must be present on remote bearer tokens.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the delete on uninstall checkbox.
     */
    public function render_delete_on_uninstall_field() {
        $settings = self::get_settings();
        ?>
        <label for="wp-mcp-ai-delete-on-uninstall">
            <input id="wp-mcp-ai-delete-on-uninstall" type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[delete_on_uninstall]" value="1" <?php checked( $settings['delete_on_uninstall'] ); ?> />
            <?php esc_html_e( 'When uninstalling the plugin, remove assistants, settings, and other stored data.', 'wp-mcp-ai' ); ?>
        </label>
        <p class="description"><?php esc_html_e( 'Leave unchecked to preserve plugin data for future installations.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the OpenAI API key field.
     */
    public function render_api_key_field() {
        $settings = self::get_settings();
        ?>
        <input type="password" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_api_key]" value="<?php echo esc_attr( $settings['openai_api_key'] ); ?>" class="regular-text" autocomplete="off" />
        <p class="description"><?php esc_html_e( 'Enter the OpenAI secret key with access to the Chat Completions API.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the Gemini API key field.
     */
    public function render_gemini_api_key_field() {
        $settings = self::get_settings();
        ?>
        <input type="password" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[gemini_api_key]" value="<?php echo esc_attr( $settings['gemini_api_key'] ); ?>" class="regular-text" autocomplete="off" />
        <p class="description"><?php esc_html_e( 'Enter the Gemini API key with access to the Generative Language API.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the Crawl4AI base URL field.
     */
    public function render_crawl4ai_base_url_field() {
        $settings = self::get_settings();
        ?>
        <input type="url" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[crawl4ai_base_url]" value="<?php echo esc_attr( $settings['crawl4ai_base_url'] ); ?>" class="regular-text" placeholder="https://example.com/" />
        <p class="description"><?php esc_html_e( 'Base URL for the Crawl4AI API (for example, https://localhost:11235/).', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the QuickBooks section description.
     */
    public function render_quickbooks_section_description() {
        ?>
        <p><?php esc_html_e( 'Configure the credentials used by the QuickBooks Online reporting tool.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the QuickBooks company ID field.
     */
    public function render_quickbooks_company_id_field() {
        $settings = self::get_settings();
        ?>
        <input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[quickbooks_company_id]" value="<?php echo esc_attr( $settings['quickbooks_company_id'] ); ?>" class="regular-text" autocomplete="off" />
        <p class="description"><?php esc_html_e( 'Enter the QuickBooks Online company ID that should be used for report requests.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the QuickBooks API key field.
     */
    public function render_quickbooks_api_key_field() {
        $settings = self::get_settings();
        ?>
        <input type="password" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[quickbooks_api_key]" value="<?php echo esc_attr( $settings['quickbooks_api_key'] ); ?>" class="regular-text" autocomplete="off" />
        <p class="description"><?php esc_html_e( 'Provide a bearer token or API key that authorises access to the QuickBooks Online reports API.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the tools section description.
     */
    public function render_tools_section_description() {
        ?>
        <p><?php esc_html_e( 'Configure the optional MCP tools exposed to assistants.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the OpenAI image model field.
     */
    public function render_openai_image_model_field() {
        $settings = self::get_settings();
        $models   = $this->get_openai_image_model_choices();
        $current  = isset( $settings['openai_image_model'] ) ? sanitize_text_field( $settings['openai_image_model'] ) : 'gpt-image-1';
        ?>
        <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_image_model]" class="regular-text">
            <?php foreach ( $models as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e( 'Default OpenAI model used by the Generate OpenAI Image tool.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the OpenAI image size field.
     */
    public function render_openai_image_size_field() {
        $settings = self::get_settings();
        $sizes    = $this->get_openai_image_size_choices();
        $current  = isset( $settings['openai_image_size'] ) ? sanitize_text_field( $settings['openai_image_size'] ) : '1024x1024';

        if ( ! isset( $sizes[ $current ] ) ) {
            $first_key = function_exists( 'array_key_first' ) ? array_key_first( $sizes ) : '1024x1024';
            $current   = $first_key ? $first_key : '1024x1024';
        }
        ?>
        <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_image_size]" class="regular-text">
            <?php foreach ( $sizes as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e( 'Image dimensions requested from OpenAI when size is not supplied explicitly.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the OpenAI image quality field.
     */
    public function render_openai_image_quality_field() {
        $settings  = self::get_settings();
        $qualities = $this->get_openai_image_quality_choices();
        $current   = isset( $settings['openai_image_quality'] ) ? sanitize_key( $settings['openai_image_quality'] ) : 'standard';
        ?>
        <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_image_quality]" class="regular-text">
            <?php foreach ( $qualities as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e( 'Quality hint passed to OpenAI when generating new images.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the OpenAI image response format field.
     */
    public function render_openai_image_response_format_field() {
        $settings          = self::get_settings();
        $response_formats  = $this->get_openai_image_response_format_choices();
        $current           = isset( $settings['openai_image_response_format'] ) ? sanitize_key( $settings['openai_image_response_format'] ) : 'b64_json';
        $model             = isset( $settings['openai_image_model'] ) ? sanitize_text_field( $settings['openai_image_model'] ) : 'gpt-image-1';
        $supports_response_format = true;

        if ( class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
            $supports_response_format = WP_MCP_AI_OpenAI_Client::image_model_supports_response_format( $model );
        }

        if ( ! $supports_response_format && isset( $response_formats['b64_json'] ) ) {
            $response_formats = array( 'b64_json' => $response_formats['b64_json'] );
            $current          = 'b64_json';
        }
        ?>
        <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_image_response_format]" class="regular-text" <?php disabled( ! $supports_response_format ); ?>>
            <?php foreach ( $response_formats as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ( ! $supports_response_format ) : ?>
            <input type="hidden" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_image_response_format]" value="b64_json" />
            <p class="description"><?php esc_html_e( 'The selected image model currently returns base64 data only.', 'wp-mcp-ai' ); ?></p>
        <?php else : ?>
            <p class="description"><?php esc_html_e( 'Choose whether OpenAI should return base64 data or a downloadable URL when generating images.', 'wp-mcp-ai' ); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * Render the OpenAI speech model field.
     */
    public function render_openai_speech_model_field() {
        $settings = self::get_settings();
        $current  = isset( $settings['openai_speech_model'] ) ? sanitize_text_field( $settings['openai_speech_model'] ) : 'gpt-4o-mini-tts';
        ?>
        <input
            type="text"
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_speech_model]"
            value="<?php echo esc_attr( $current ); ?>"
            class="regular-text"
            placeholder="gpt-4o-mini-tts"
        />
        <p class="description"><?php esc_html_e( 'Default text-to-speech model used by the Generate OpenAI Speech tool.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the OpenAI speech voice field.
     */
    public function render_openai_speech_voice_field() {
        $settings = self::get_settings();
        $current  = isset( $settings['openai_speech_voice'] ) ? sanitize_key( $settings['openai_speech_voice'] ) : 'alloy';
        ?>
        <input
            type="text"
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_speech_voice]"
            value="<?php echo esc_attr( $current ); ?>"
            class="regular-text"
            placeholder="alloy"
        />
        <p class="description"><?php esc_html_e( 'Default OpenAI voice requested for speech responses (for example, alloy, verse, or shimmer).', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the OpenAI speech format field.
     */
    public function render_openai_speech_format_field() {
        $settings = self::get_settings();
        $formats  = $this->get_openai_speech_format_choices();
        $current  = isset( $settings['openai_speech_format'] ) ? sanitize_key( $settings['openai_speech_format'] ) : 'mp3';
        ?>
        <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[openai_speech_format]" class="regular-text">
            <?php foreach ( $formats as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e( 'Preferred audio container when assistants omit the format.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the Crawl4AI API key field.
     */
    public function render_crawl4ai_api_key_field() {
        $settings = self::get_settings();
        ?>
        <input type="password" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[crawl4ai_api_key]" value="<?php echo esc_attr( $settings['crawl4ai_api_key'] ); ?>" class="regular-text" autocomplete="off" />
        <p class="description"><?php esc_html_e( 'Optional bearer token that will be sent with Crawl4AI requests.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the Cloudflare zone ID field.
     */
    public function render_cloudflare_zone_id_field() {
        $settings = self::get_settings();
        ?>
        <input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[cloudflare_zone_id]" value="<?php echo esc_attr( $settings['cloudflare_zone_id'] ); ?>" class="regular-text" autocomplete="off" />
        <p class="description"><?php esc_html_e( 'Cloudflare zone identifier (a 32 character string) for the site you wish to purge.', 'wp-mcp-ai' ); ?></p>
     * Render the Mailjet API key field.
     */
    public function render_mailjet_api_key_field() {
        $settings = self::get_settings();
        ?>
        <input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[mailjet_api_key]" value="<?php echo esc_attr( $settings['mailjet_api_key'] ); ?>" class="regular-text" autocomplete="off" />
        <p class="description"><?php esc_html_e( 'Public Mailjet API key used to authenticate requests.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the Cloudflare API token field.
     */
    public function render_cloudflare_api_token_field() {
        $settings = self::get_settings();
        ?>
        <input type="password" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[cloudflare_api_token]" value="<?php echo esc_attr( $settings['cloudflare_api_token'] ); ?>" class="regular-text" autocomplete="off" />
        <p class="description"><?php esc_html_e( 'Cloudflare API token with permission to purge cache for the configured zone.', 'wp-mcp-ai' ); ?></p>
     * Render the Mailjet API secret field.
     */
    public function render_mailjet_api_secret_field() {
        $settings = self::get_settings();
        ?>
        <input type="password" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[mailjet_api_secret]" value="<?php echo esc_attr( $settings['mailjet_api_secret'] ); ?>" class="regular-text" autocomplete="off" />
        <p class="description"><?php esc_html_e( 'Private Mailjet API secret paired with the API key.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the Mailjet from email field.
     */
    public function render_mailjet_from_email_field() {
        $settings = self::get_settings();
        ?>
        <input type="email" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[mailjet_from_email]" value="<?php echo esc_attr( $settings['mailjet_from_email'] ); ?>" class="regular-text" placeholder="sender@example.com" />
        <p class="description"><?php esc_html_e( 'Default sender email used when assistants omit a from address.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the Mailjet from name field.
     */
    public function render_mailjet_from_name_field() {
        $settings = self::get_settings();
        ?>
        <input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[mailjet_from_name]" value="<?php echo esc_attr( $settings['mailjet_from_name'] ); ?>" class="regular-text" placeholder="WP MCP AI" />
        <p class="description"><?php esc_html_e( 'Optional default sender name presented to recipients.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the group email capability field.
     */
    public function render_group_email_capability_field() {
        $settings    = self::get_settings();
        $capability  = isset( $settings['group_email_capability'] ) ? sanitize_key( $settings['group_email_capability'] ) : '';
        $choices     = $this->get_group_email_capability_choices();

        if ( '' !== $capability && ! in_array( $capability, $choices, true ) ) {
            $choices[] = $capability;
        }
        ?>
        <select
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[group_email_capability]"
            class="regular-text"
        >
            <option value="" <?php selected( '', $capability ); ?>>
                <?php esc_html_e( 'Any logged-in user (no capability required)', 'wp-mcp-ai' ); ?>
            </option>
            <?php foreach ( $choices as $choice ) : ?>
                <?php $label = $this->get_group_email_capability_label( $choice ); ?>
                <option value="<?php echo esc_attr( $choice ); ?>" <?php selected( $capability, $choice ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php esc_html_e( 'Select the capability required to use the Send Group Email tool. Choose "Any logged-in user" to allow any logged-in user that passes attachment checks.', 'wp-mcp-ai' ); ?>
        </p>
        <?php
    }

    /**
     * Retrieve the available capability choices for the group email tool.
     *
     * @return string[] List of capability slugs.
     */
    protected function get_group_email_capability_choices() {
        $choices = array();

        if ( function_exists( 'wp_roles' ) ) {
            $wp_roles = wp_roles();

            if ( $wp_roles && is_a( $wp_roles, 'WP_Roles' ) ) {
                foreach ( $wp_roles->roles as $role ) {
                    if ( empty( $role['capabilities'] ) || ! is_array( $role['capabilities'] ) ) {
                        continue;
                    }

                    foreach ( $role['capabilities'] as $capability => $granted ) {
                        if ( empty( $granted ) ) {
                            continue;
                        }

                        $capability = sanitize_key( $capability );

                        if ( '' !== $capability ) {
                            $choices[ $capability ] = $capability;
                        }
                    }
                }
            }
        }

        if ( ! isset( $choices['publish_posts'] ) ) {
            $choices['publish_posts'] = 'publish_posts';
        }

        $choices = array_values( $choices );
        sort( $choices, SORT_NATURAL | SORT_FLAG_CASE );

        /**
         * Filter the capability options shown in the group email settings field.
         *
         * @param string[] $choices Capability slugs available for selection.
         */
        $choices = apply_filters( 'wp_mcp_ai_group_email_capability_choices', $choices );

        if ( ! is_array( $choices ) ) {
            return array();
        }

        $sanitized = array();

        foreach ( $choices as $choice ) {
            $choice = sanitize_key( $choice );

            if ( '' === $choice ) {
                continue;
            }

            $sanitized[ $choice ] = $choice;
        }

        return array_values( $sanitized );
    }

    /**
     * Convert a capability slug into a human-friendly label.
     *
     * @param string $capability Capability slug.
     * @return string
     */
    protected function get_group_email_capability_label( $capability ) {
        $readable = trim( preg_replace( '/[\-_]+/', ' ', (string) $capability ) );
        $readable = preg_replace( '/\s+/', ' ', $readable );

        if ( '' === $readable ) {
            return $capability;
        }

        $readable = ucwords( $readable );

        if ( strtolower( $readable ) === strtolower( $capability ) ) {
            return $readable;
        }

        return sprintf( '%1$s (%2$s)', $readable, $capability );
    }

    /**
     * Render the group email max recipients field.
     */
    public function render_group_email_max_recipients_field() {
        $settings        = self::get_settings();
        $max_recipients  = isset( $settings['group_email_max_recipients'] ) ? (int) $settings['group_email_max_recipients'] : 0;
        ?>
        <input
            type="number"
            min="0"
            step="1"
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[group_email_max_recipients]"
            value="<?php echo esc_attr( $max_recipients ); ?>"
            class="small-text"
        />
        <p class="description">
            <?php esc_html_e( 'Maximum number of recipients allowed per Send Group Email request. Set to 0 to disable the limit.', 'wp-mcp-ai' ); ?>
        </p>
        <?php
    }

    /**
     * Render the default Gemini model field.
     */
    public function render_default_gemini_model_field() {
        $settings = self::get_settings();
        ?>
        <input type="text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_gemini_model]" value="<?php echo esc_attr( $settings['default_gemini_model'] ); ?>" class="regular-text" />
        <?php
    }

    /**
     * Render the default provider dropdown field.
     */
    public function render_default_provider_field() {
        $settings = self::get_settings();
        $current  = isset( $settings['default_provider'] ) ? sanitize_key( $settings['default_provider'] ) : 'openai';
        $choices  = apply_filters( 'wp_mcp_ai_allowed_providers', array( 'openai', 'gemini' ) );

        if ( ! is_array( $choices ) ) {
            $choices = array( 'openai', 'gemini' );
        }
        ?>
        <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_provider]" id="wp-mcp-ai-default-provider" class="regular-text">
            <?php
            foreach ( $choices as $choice ) {
                $choice = sanitize_key( $choice );
                if ( '' === $choice ) {
                    continue;
                }

                $label = 'openai' === $choice ? __( 'OpenAI', 'wp-mcp-ai' ) : __( 'Gemini', 'wp-mcp-ai' );
                ?>
                <option value="<?php echo esc_attr( $choice ); ?>" <?php selected( $current, $choice ); ?>><?php echo esc_html( $label ); ?></option>
                <?php
            }
            ?>
        </select>
        <p class="description"><?php esc_html_e( 'Select which provider new assistants should use when no override is set.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render the default assistant dropdown field.
     */
    public function render_default_assistant_field() {
        $settings   = self::get_settings();
        $assistants = $this->get_assistant_posts();
        ?>
        <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_assistant]" class="regular-text">
            <option value="0" <?php selected( 0, $settings['default_assistant'] ); ?>><?php esc_html_e( 'None', 'wp-mcp-ai' ); ?></option>
            <?php foreach ( $assistants as $assistant ) : ?>
                <option value="<?php echo esc_attr( $assistant->ID ); ?>" <?php selected( $assistant->ID, $settings['default_assistant'] ); ?>><?php echo esc_html( $assistant->post_title ); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e( 'The assistant used by default in REST interactions when one is not provided explicitly.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Render logging checkbox.
     */
    public function render_logging_field() {
        $settings = self::get_settings();
        ?>
        <label for="wp-mcp-ai-enable-logging">
            <input id="wp-mcp-ai-enable-logging" type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enable_logging]" value="1" <?php checked( $settings['enable_logging'] ); ?> />
            <?php esc_html_e( 'Write OpenAI request and response details to the debug log.', 'wp-mcp-ai' ); ?>
        </label>
        <?php if ( ! empty( $settings['enable_logging'] ) ) :
            $entries = WP_MCP_AI_Logger::get_recent_error_messages();
            ?>
            <p class="description"><?php esc_html_e( 'Recent error and warning messages (most recent first). Expand an entry to view additional context.', 'wp-mcp-ai' ); ?></p>
            <?php if ( empty( $entries ) ) : ?>
                <p class="description"><?php esc_html_e( 'No error or warning messages have been recorded yet.', 'wp-mcp-ai' ); ?></p>
            <?php else : ?>
                <ul class="wp-mcp-ai-log-preview">
                    <?php foreach ( $entries as $entry ) :
                        $timestamp = '';

                        if ( ! empty( $entry['timestamp'] ) ) {
                            $timestamp = get_date_from_gmt(
                                $entry['timestamp'],
                                get_option( 'date_format' ) . ' ' . get_option( 'time_format' )
                            );
                        }

                        $type_label    = strtoupper( $entry['type'] );
                        $message_label = $entry['message'];
                        $context_label = '';

                        if ( isset( $entry['context'] ) && ! empty( $entry['context'] ) ) {
                            $options = 0;

                            if ( defined( 'JSON_PRETTY_PRINT' ) ) {
                                $options |= JSON_PRETTY_PRINT;
                            }

                            if ( defined( 'JSON_UNESCAPED_SLASHES' ) ) {
                                $options |= JSON_UNESCAPED_SLASHES;
                            }

                            $context_json = wp_json_encode( $entry['context'], $options );

                            if ( false !== $context_json ) {
                                $context_label = $context_json;
                            }
                        }
                        ?>
                        <li>
                            <?php if ( ! empty( $timestamp ) ) : ?>
                                <span class="wp-mcp-ai-log-preview__time"><?php echo esc_html( $timestamp ); ?></span>
                                &mdash;
                            <?php endif; ?>
                            <span class="wp-mcp-ai-log-preview__type"><?php echo esc_html( $type_label ); ?></span>:
                            <span class="wp-mcp-ai-log-preview__message"><?php echo esc_html( $message_label ); ?></span>
                            <?php if ( '' !== $context_label ) : ?>
                                <details class="wp-mcp-ai-log-preview__context">
                                    <summary><?php esc_html_e( 'Context details', 'wp-mcp-ai' ); ?></summary>
                                    <pre><?php echo esc_html( $context_label ); ?></pre>
                                </details>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
        <?php
    }

    /**
     * Render the default model field.
     */
    public function render_default_model_field() {
        $settings    = self::get_settings();
        $current     = isset( $settings['default_model'] ) ? sanitize_text_field( $settings['default_model'] ) : '';
        $choices     = $this->get_openai_default_model_choices();
        $datalist_id = 'wp-mcp-ai-default-openai-models';
        ?>
        <input
            type="text"
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[default_model]"
            value="<?php echo esc_attr( $current ); ?>"
            class="regular-text"
            list="<?php echo esc_attr( $datalist_id ); ?>"
        />
        <?php if ( ! empty( $choices ) ) : ?>
            <datalist id="<?php echo esc_attr( $datalist_id ); ?>">
                <?php foreach ( $choices as $value => $label ) : ?>
                    <option value="<?php echo esc_attr( $value ); ?>" label="<?php echo esc_attr( $label ); ?>"><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </datalist>
        <?php endif; ?>
        <p class="description"><?php esc_html_e( 'The Chat Completions model to use when assistants do not specify one.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Retrieve suggested OpenAI chat completion models for the default model field.
     *
     * @return array<string, string> Associative array of model slugs mapped to display labels.
     */
    protected function get_openai_default_model_choices() {
        $choices = array(
            'gpt-4o'                   => __( 'GPT-4o', 'wp-mcp-ai' ),
            'gpt-4o-mini'              => __( 'GPT-4o mini', 'wp-mcp-ai' ),
            'gpt-4.1'                  => __( 'GPT-4.1', 'wp-mcp-ai' ),
            'gpt-4.1-mini'             => __( 'GPT-4.1 mini', 'wp-mcp-ai' ),
            'o4-mini'                  => __( 'o4 mini', 'wp-mcp-ai' ),
            'gpt-4o-audio-preview'     => __( 'GPT-4o audio preview', 'wp-mcp-ai' ),
            'gpt-4o-realtime-preview'  => __( 'GPT-4o realtime preview', 'wp-mcp-ai' ),
        );

        /**
         * Filter the default OpenAI model choices displayed in the settings UI.
         *
         * @param array<string, string> $choices Associative array of model values mapped to human-readable labels.
         */
        $choices = apply_filters( 'wp_mcp_ai_default_openai_model_choices', $choices );

        if ( ! is_array( $choices ) ) {
            return array();
        }

        $sanitized = array();

        foreach ( $choices as $value => $label ) {
            $value = sanitize_text_field( (string) $value );

            if ( '' === $value ) {
                continue;
            }

            if ( is_object( $label ) && method_exists( $label, '__toString' ) ) {
                $label = (string) $label;
            } elseif ( is_scalar( $label ) ) {
                $label = (string) $label;
            } else {
                $label = $value;
            }

            $label = wp_strip_all_tags( $label );

            if ( '' === $label ) {
                $label = $value;
            }

            $sanitized[ $value ] = $label;
        }

        return $sanitized;
    }

    /**
     * Render the timeout field.
     */
    public function render_timeout_field() {
        $settings = self::get_settings();
        ?>
        <input type="number" min="5" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[request_timeout]" value="<?php echo esc_attr( $settings['request_timeout'] ); ?>" class="small-text" />
        <p class="description"><?php esc_html_e( 'How long to wait for OpenAI responses before aborting the request.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Retrieve published assistant posts.
     *
     * @return WP_Post[]
     */
    protected function get_assistant_posts() {
        $args = array(
            'post_type'      => WP_MCP_AI_Assistant_CPT::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => 'all',
        );

        $posts = get_posts( $args );

        if ( ! $posts ) {
            return array();
        }

        return $posts;
    }

    /**
     * Render the allowed image MIME types field.
     */
    public function render_allowed_image_mimes_field() {
        $settings = self::get_settings();
        $value    = $this->format_mime_list_for_display( $settings['allowed_image_mimes'] );
        ?>
        <textarea
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[allowed_image_mimes]"
            rows="5"
            cols="40"
            class="large-text code"
        ><?php echo esc_textarea( $value ); ?></textarea>
        <p class="description">
            <?php
            printf(
                '%s %s',
                esc_html__(
                    'Optional. Enter one MIME type per line to replace the default allowed image types.',
                    'wp-mcp-ai'
                ),
                esc_html__(
                    'Leave blank to use the plugin defaults.',
                    'wp-mcp-ai'
                )
            );
            ?>
        </p>
        <?php
    }

    /**
     * Render the allowed file MIME types field.
     */
    public function render_allowed_file_mimes_field() {
        $settings = self::get_settings();
        $value    = $this->format_mime_list_for_display( $settings['allowed_file_mimes'] );
        ?>
        <textarea
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[allowed_file_mimes]"
            rows="6"
            cols="40"
            class="large-text code"
        ><?php echo esc_textarea( $value ); ?></textarea>
        <p class="description">
            <?php
            printf(
                '%s %s',
                esc_html__(
                    'Optional. Enter one MIME type per line to replace the default allowed file types.',
                    'wp-mcp-ai'
                ),
                esc_html__(
                    'Leave blank to use the plugin defaults.',
                    'wp-mcp-ai'
                )
            );
            ?>
        </p>
        <?php
    }

    /**
     * Render the memory file size limit field.
     */
    public function render_memory_max_file_bytes_field() {
        $settings = self::get_settings();
        $choices  = $this->get_memory_max_file_size_choices();
        $current  = isset( $settings['memory_max_file_bytes'] ) ? absint( $settings['memory_max_file_bytes'] ) : self::DEFAULT_MEMORY_MAX_FILE_BYTES;
        ?>
        <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[memory_max_file_bytes]" class="regular-text">
            <?php foreach ( $choices as $bytes => $label ) : ?>
                <option value="<?php echo esc_attr( $bytes ); ?>" <?php selected( $current, $bytes ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e( 'Largest attachment size that can be processed as assistant memory.', 'wp-mcp-ai' ); ?></p>
        <?php
    }

    /**
     * Retrieve the selectable memory file size limits.
     *
     * @return array
     */
    protected function get_memory_max_file_size_choices() {
        $choices = array(
            5 * MB_IN_BYTES   => __( '5 MB (default)', 'wp-mcp-ai' ),
            10 * MB_IN_BYTES  => __( '10 MB', 'wp-mcp-ai' ),
            25 * MB_IN_BYTES  => __( '25 MB', 'wp-mcp-ai' ),
            50 * MB_IN_BYTES  => __( '50 MB', 'wp-mcp-ai' ),
            100 * MB_IN_BYTES => __( '100 MB', 'wp-mcp-ai' ),
        );

        /**
         * Filters the selectable memory file size limits shown in the admin.
         *
         * @param array $choices Associative array mapping byte sizes to labels.
         */
        $choices = apply_filters( 'wp_mcp_ai_memory_max_file_size_choices', $choices );

        if ( ! is_array( $choices ) || empty( $choices ) ) {
            return array( self::DEFAULT_MEMORY_MAX_FILE_BYTES => __( '5 MB (default)', 'wp-mcp-ai' ) );
        }

        $sanitized = array();

        foreach ( $choices as $bytes => $label ) {
            $bytes = absint( $bytes );

            if ( $bytes <= 0 ) {
                continue;
            }

            $sanitized[ $bytes ] = $label;
        }

        if ( empty( $sanitized ) ) {
            $sanitized[ self::DEFAULT_MEMORY_MAX_FILE_BYTES ] = __( '5 MB (default)', 'wp-mcp-ai' );
        }

        return $sanitized;
    }

    /**
     * Retrieve the list of available OpenAI image models.
     *
     * @return array
     */
    protected function get_openai_image_model_choices() {
        $models = array(
            'gpt-image-1' => __( 'GPT-Image-1', 'wp-mcp-ai' ),
            'dall-e-3'    => __( 'DALL·E 3', 'wp-mcp-ai' ),
            'dall-e-2'    => __( 'DALL·E 2', 'wp-mcp-ai' ),
        );

        $models = apply_filters( 'wp_mcp_ai_openai_image_models', $models );

        if ( ! is_array( $models ) || empty( $models ) ) {
            $models = array(
                'gpt-image-1' => __( 'GPT-Image-1', 'wp-mcp-ai' ),
                'dall-e-3'    => __( 'DALL·E 3', 'wp-mcp-ai' ),
                'dall-e-2'    => __( 'DALL·E 2', 'wp-mcp-ai' ),
            );
        }

        return $models;
    }

    /**
     * Retrieve the list of available OpenAI image sizes.
     *
     * @return array
     */
    protected function get_openai_image_size_choices() {
        $sizes = array(
            '1024x1024' => __( '1024 × 1024 (square)', 'wp-mcp-ai' ),
            '1024x1792' => __( '1024 × 1792 (portrait)', 'wp-mcp-ai' ),
            '1792x1024' => __( '1792 × 1024 (landscape)', 'wp-mcp-ai' ),
            'auto'      => __( 'Auto (let OpenAI decide)', 'wp-mcp-ai' ),
        );

        $sizes = apply_filters( 'wp_mcp_ai_openai_image_sizes', $sizes );

        if ( ! is_array( $sizes ) || empty( $sizes ) ) {
            $sizes = array(
                '1024x1024' => __( '1024 × 1024 (square)', 'wp-mcp-ai' ),
                '1024x1792' => __( '1024 × 1792 (portrait)', 'wp-mcp-ai' ),
                '1792x1024' => __( '1792 × 1024 (landscape)', 'wp-mcp-ai' ),
                'auto'      => __( 'Auto (let OpenAI decide)', 'wp-mcp-ai' ),
            );
        }

        return $sizes;
    }

    /**
     * Retrieve the list of available OpenAI image quality options.
     *
     * @return array
     */
    protected function get_openai_image_quality_choices() {
        $qualities = array(
            'standard' => __( 'Standard', 'wp-mcp-ai' ),
            'hd'       => __( 'HD (High Definition)', 'wp-mcp-ai' ),
        );

        $qualities = apply_filters( 'wp_mcp_ai_openai_image_qualities', $qualities );

        if ( ! is_array( $qualities ) || empty( $qualities ) ) {
            $qualities = array(
                'standard' => __( 'Standard', 'wp-mcp-ai' ),
                'hd'       => __( 'HD (High Definition)', 'wp-mcp-ai' ),
            );
        }

        return $qualities;
    }

    /**
     * Retrieve the list of available OpenAI image response formats.
     *
     * @return array
     */
    protected function get_openai_image_response_format_choices() {
        $formats = array(
            'b64_json' => __( 'Base64 JSON (download immediately)', 'wp-mcp-ai' ),
            'url'      => __( 'Hosted URL (download from OpenAI)', 'wp-mcp-ai' ),
        );

        $formats = apply_filters( 'wp_mcp_ai_openai_image_response_formats', $formats );

        if ( ! is_array( $formats ) || empty( $formats ) ) {
            $formats = array(
                'b64_json' => __( 'Base64 JSON (download immediately)', 'wp-mcp-ai' ),
                'url'      => __( 'Hosted URL (download from OpenAI)', 'wp-mcp-ai' ),
            );
        }

        return $formats;
    }

    /**
     * Retrieve the allowed OpenAI speech formats.
     *
     * @return array
     */
    protected function get_openai_speech_format_choices() {
        $formats = array(
            'mp3'  => __( 'MP3', 'wp-mcp-ai' ),
            'aac'  => __( 'AAC', 'wp-mcp-ai' ),
            'flac' => __( 'FLAC', 'wp-mcp-ai' ),
            'ogg'  => __( 'OGG', 'wp-mcp-ai' ),
            'opus' => __( 'Opus', 'wp-mcp-ai' ),
            'wav'  => __( 'WAV', 'wp-mcp-ai' ),
        );

        /**
         * Filter the audio format options available in the admin settings.
         *
         * @param array $formats Associative array of format slugs to labels.
         */
        $formats = apply_filters( 'wp_mcp_ai_openai_speech_formats', $formats );

        if ( ! is_array( $formats ) || empty( $formats ) ) {
            return array(
                'mp3' => __( 'MP3', 'wp-mcp-ai' ),
            );
        }

        $sanitized = array();

        foreach ( $formats as $key => $label ) {
            $key = sanitize_key( $key );

            if ( '' === $key ) {
                continue;
            }

            $sanitized[ $key ] = $label;
        }

        if ( empty( $sanitized ) ) {
            return array(
                'mp3' => __( 'MP3', 'wp-mcp-ai' ),
            );
        }

        return $sanitized;
    }

    /**
     * Parse an arbitrary value into a list of MIME types.
     *
     * @param mixed $value Raw submitted value.
     * @return array
     */
    protected function parse_mime_list( $value ) {
        $items = array();

        if ( is_string( $value ) ) {
            $items = preg_split( '/[\r\n]+/', $value );
        } elseif ( is_array( $value ) ) {
            $items = $value;
        }

        if ( ! is_array( $items ) ) {
            $items = array();
        }

        $sanitized = array();

        foreach ( $items as $item ) {
            $item = trim( (string) $item );

            if ( '' === $item ) {
                continue;
            }

            $item = sanitize_text_field( $item );

            if ( '' !== $item ) {
                $sanitized[] = $item;
            }
        }

        return array_values( array_unique( $sanitized ) );
    }

    /**
     * Convert an array of MIME types to display text.
     *
     * @param mixed $value Stored value.
     * @return string
     */
    protected function format_mime_list_for_display( $value ) {
        if ( ! is_array( $value ) ) {
            return '';
        }

        return implode( "\n", array_map( 'trim', array_filter( $value ) ) );
    }

    /**
     * Override the memory file size limit with the admin setting.
     *
     * @param int $max_bytes Default maximum bytes allowed.
     * @param int $attachment_id Attachment ID being evaluated.
     * @return int
     */
    public function filter_memory_max_file_bytes( $max_bytes, $attachment_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
        $settings = self::get_settings();
        $limit    = isset( $settings['memory_max_file_bytes'] ) ? absint( $settings['memory_max_file_bytes'] ) : 0;

        if ( $limit > 0 ) {
            return $limit;
        }

        return $max_bytes;
    }
}
