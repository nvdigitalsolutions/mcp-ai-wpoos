<?php
/**
 * Tool for generating fantasy football team logos using AI.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
exit;
}

/**
 * Generates team logos for fantasy football teams using AI image generation.
 */
class WP_MCP_AI_Tool_FF_Generate_Team_Logo implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
use WP_MCP_AI_Tool_Chat_Response;

/**
 * {@inheritdoc}
 */
public function get_slug() {
return 'ff_generate_team_logo';
}

/**
 * {@inheritdoc}
 */
public function get_name() {
return __( 'Fantasy Football - Generate Team Logo', 'mcp-ai-wpoos' );
}

/**
 * {@inheritdoc}
 */
public function get_description() {
return __( 'Generates a custom team logo for a fantasy football team using AI image generation. Creates professional, sports-themed logos based on team name and preferences.', 'mcp-ai-wpoos' );
}

/**
 * {@inheritdoc}
 */
public function get_parameters_schema() {
return array(
'type'                 => 'object',
'properties'           => array(
'team_name'      => array(
'type'        => 'string',
'description' => __( 'Fantasy team name (required).', 'mcp-ai-wpoos' ),
),
'style'          => array(
'type'        => 'string',
'description' => __( 'Logo style: "modern", "classic", "minimalist", "mascot", "emblem".', 'mcp-ai-wpoos' ),
'enum'        => array( 'modern', 'classic', 'minimalist', 'mascot', 'emblem' ),
'default'     => 'modern',
),
'colors'         => array(
'type'        => 'string',
'description' => __( 'Preferred color scheme (e.g., "blue and gold", "red and black").', 'mcp-ai-wpoos' ),
),
'theme'          => array(
'type'        => 'string',
'description' => __( 'Theme or motif (e.g., "lions", "warriors", "champions", "rockets").', 'mcp-ai-wpoos' ),
),
'provider'       => array(
'type'        => 'string',
'description' => __( 'AI provider: "openai" or "gemini". Defaults to OpenAI.', 'mcp-ai-wpoos' ),
'enum'        => array( 'openai', 'gemini' ),
'default'     => 'openai',
),
'size'           => array(
'type'        => 'string',
'description' => __( 'Image size: "1024x1024" (square), "1024x1792" (portrait), "1792x1024" (landscape).', 'mcp-ai-wpoos' ),
'enum'        => array( '1024x1024', '1024x1792', '1792x1024' ),
'default'     => '1024x1024',
),
'save_to_team'   => array(
'type'        => 'boolean',
'description' => __( 'Save logo to a fantasy team post (requires team_post_id).', 'mcp-ai-wpoos' ),
'default'     => false,
),
'team_post_id'   => array(
'type'        => 'integer',
'description' => __( 'Fantasy Team post ID to save logo to.', 'mcp-ai-wpoos' ),
),
),
'required'             => array( 'team_name' ),
'additionalProperties' => false,
);
}

/**
 * Execute the tool.
 *
 * @param array $arguments Tool arguments.
 * @param array $context   Execution context including user_id.
 * @return array|WP_Error Tool results or error.
 */
public function execute( array $arguments = array(), array $context = array() ) {
$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

if ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) {
return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate images.', 'mcp-ai-wpoos' ) );
}

// Validate required parameters.
if ( empty( $arguments['team_name'] ) ) {
return new WP_Error( 'wp_mcp_ai_missing_team_name', __( 'Team name is required.', 'mcp-ai-wpoos' ) );
}

$team_name      = sanitize_text_field( $arguments['team_name'] );
$style          = isset( $arguments['style'] ) ? sanitize_text_field( $arguments['style'] ) : 'modern';
$colors         = isset( $arguments['colors'] ) ? sanitize_text_field( $arguments['colors'] ) : '';
$theme          = isset( $arguments['theme'] ) ? sanitize_text_field( $arguments['theme'] ) : '';
$provider       = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : 'openai';
$size           = isset( $arguments['size'] ) ? sanitize_text_field( $arguments['size'] ) : '1024x1024';
$save_to_team   = isset( $arguments['save_to_team'] ) ? (bool) $arguments['save_to_team'] : false;
$team_post_id   = isset( $arguments['team_post_id'] ) ? absint( $arguments['team_post_id'] ) : 0;

// Build the AI prompt.
$prompt = $this->build_logo_prompt( $team_name, $style, $colors, $theme );

// Generate the logo using selected provider.
$image_result = $this->generate_logo_image( $prompt, $provider, $size, $user_id );

if ( is_wp_error( $image_result ) ) {
return $image_result;
}

$result = array(
'team_name'   => $team_name,
'style'       => $style,
'provider'    => $provider,
'image_url'   => $image_result['url'],
'prompt_used' => $prompt,
);

// Optionally save to team post.
if ( $save_to_team && $team_post_id && get_post_type( $team_post_id ) === 'ff_team' ) {
if ( current_user_can( 'edit_post', $team_post_id ) ) {
update_post_meta( $team_post_id, '_ff_logo_url', $image_result['url'] );
$result['saved_to_team'] = true;
$result['team_post_id']  = $team_post_id;
}
}

return $result;
}

/**
 * Build AI prompt for logo generation.
 *
 * @param string $team_name Team name.
 * @param string $style     Logo style.
 * @param string $colors    Color scheme.
 * @param string $theme     Theme/motif.
 * @return string Generated prompt.
 */
protected function build_logo_prompt( $team_name, $style, $colors, $theme ) {
$prompt = sprintf(
'Create a professional fantasy football team logo for "%s". ',
$team_name
);

// Add style description.
$style_descriptions = array(
'modern'      => 'Use a modern, sleek design with bold lines and contemporary aesthetics.',
'classic'     => 'Use a classic, traditional sports team logo style with vintage elements.',
'minimalist'  => 'Use a minimalist design with clean lines and simple shapes.',
'mascot'      => 'Feature a mascot character as the central element in an action pose.',
'emblem'      => 'Create an emblem or badge style logo with a shield or crest.',
);

if ( isset( $style_descriptions[ $style ] ) ) {
$prompt .= $style_descriptions[ $style ] . ' ';
}

// Add color scheme.
if ( ! empty( $colors ) ) {
$prompt .= sprintf( 'Use colors: %s. ', $colors );
} else {
$prompt .= 'Use bold, vibrant sports team colors. ';
}

// Add theme/motif.
if ( ! empty( $theme ) ) {
$prompt .= sprintf( 'Incorporate %s as the central theme or motif. ', $theme );
}

$prompt .= 'The logo should be suitable for a fantasy football team, conveying strength, competition, and team spirit. ';
$prompt .= 'Design should work well as both a large image and small icon. ';
$prompt .= 'No text or lettering in the logo - pure graphic design only.';

return $prompt;
}

/**
 * Generate logo image using AI provider.
 *
 * @param string $prompt   Image generation prompt.
 * @param string $provider AI provider (openai or gemini).
 * @param string $size     Image size.
 * @param int    $user_id  User ID.
 * @return array|WP_Error Image URL or error.
 */
protected function generate_logo_image( $prompt, $provider, $size, $user_id ) {
// Use the existing image generation tools.
if ( 'gemini' === $provider ) {
// Use Gemini image generation.
$gemini_tool = new WP_MCP_AI_Tool_Generate_Gemini_Image();
$result      = $gemini_tool->execute(
array(
'prompt' => $prompt,
'aspect_ratio' => $this->convert_size_to_aspect_ratio( $size ),
),
array( 'user_id' => $user_id )
);
} else {
// Use OpenAI DALL-E.
$openai_tool = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
$result      = $openai_tool->execute(
array(
'prompt' => $prompt,
'size'   => $size,
'model'  => 'dall-e-3',
'quality' => 'hd',
),
array( 'user_id' => $user_id )
);
}

if ( is_wp_error( $result ) ) {
return $result;
}

// Extract URL from result.
if ( isset( $result['url'] ) ) {
return array( 'url' => $result['url'] );
} elseif ( isset( $result['image_url'] ) ) {
return array( 'url' => $result['image_url'] );
} elseif ( isset( $result['data'][0]['url'] ) ) {
return array( 'url' => $result['data'][0]['url'] );
}

return new WP_Error( 'wp_mcp_ai_no_image_url', __( 'Failed to extract image URL from AI response.', 'mcp-ai-wpoos' ) );
}

/**
 * Convert size format to aspect ratio for Gemini.
 *
 * @param string $size Size string (e.g., "1024x1024").
 * @return string Aspect ratio string.
 */
protected function convert_size_to_aspect_ratio( $size ) {
$ratios = array(
'1024x1024' => '1:1',
'1024x1792' => '9:16',
'1792x1024' => '16:9',
);

return isset( $ratios[ $size ] ) ? $ratios[ $size ] : '1:1';
}

/**
 * Get extended tool definition including toolkit metadata.
 *
 * @return array Tool definition with metadata.
 */
public function get_definition() {
return array(
'name'                  => $this->get_name(),
'description'           => $this->get_description(),
'toolkit'               => 'fantasy_football',
'pattern_compatibility' => array( 'event_driven' ),
'profession_tags'       => array( 'fantasy_sports_manager', 'graphic_designer' ),
'risk_level'            => 'low',
);
}

/**
 * {@inheritdoc}
 */
public function get_capability_flags() {
return array(
'write',
'external-api',
'requires-credentials',
'requires-capability',
'consumes-tokens',
);
}
}
