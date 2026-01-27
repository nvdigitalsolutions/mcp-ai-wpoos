
/**
 * Register a tool with execution context metadata.
 *
 * Enhanced registration that stores additional metadata about
 * tool execution capabilities and requirements.
 *
 * @since 1.2.0
 *
 * @param string|WP_MCP_AI_Tool_Interface $tool     Tool class name or instance.
 * @param array                           $contexts Execution contexts (e.g., 'client', 'server', 'worker').
 * @return bool Whether the tool was registered.
 */
public function register_tool_with_context( $tool, $contexts = array( 'server' ) ) {
if ( is_string( $tool ) ) {
if ( ! class_exists( $tool ) ) {
return false;
}

$tool = new $tool();
}

if ( ! $tool instanceof WP_MCP_AI_Tool_Interface ) {
return false;
}

$slug = sanitize_key( $tool->get_slug() );

if ( empty( $slug ) ) {
return false;
}

// For now, use legacy registration - enhanced metadata will be added later.
$this->tools[ $slug ] = $tool;

return true;
}

/**
 * Get tools that can execute in a specific context.
 *
 * @since 1.2.0
 *
 * @param string $context Execution context ('client', 'server', 'worker').
 * @return array Array of tools that can execute in the specified context.
 */
public function get_tools_by_context( $context ) {
$this->init();

// For now, return all tools for server context, client tools filtered by name.
if ( 'client' === $context ) {
return $this->get_client_executable_tools();
}

return $this->tools;
}

/**
 * Get client-executable tools.
 *
 * Returns tools that are safe and capable of running client-side.
 *
 * @since 1.2.0
 *
 * @return array Array of client-executable tools.
 */
public function get_client_executable_tools() {
$this->init();

$client_safe_names = array(
'client_summarize',
'client_sentiment',
'client_translate',
'client_embed',
'client_describe_image',
'client_detect_objects',
'client_transcribe_audio',
'generate_chart',
'generate_mermaid',
);

$client_tools = array();

foreach ( $this->tools as $slug => $tool ) {
if ( in_array( $slug, $client_safe_names, true ) ) {
$client_tools[ $slug ] = $tool;
}
}

return $client_tools;
}

/**
 * Analyze tool complexity.
 *
 * Estimates computational complexity of a tool to inform
 * execution strategy decisions.
 *
 * @since 1.2.0
 *
 * @param WP_MCP_AI_Tool_Interface $tool Tool instance.
 * @return string Complexity level ('low', 'medium', 'high').
 */
protected function analyze_complexity( $tool ) {
/**
 * Filter the complexity analysis for a tool.
 *
 * @since 1.2.0
 *
 * @param string                   $complexity Complexity level.
 * @param WP_MCP_AI_Tool_Interface $tool       Tool instance.
 */
return apply_filters( 'wp_mcp_ai_tool_complexity', 'medium', $tool );
}

/**
 * Check if a tool's results are cacheable.
 *
 * Determines if a tool produces deterministic results that
 * can be cached for improved performance.
 *
 * @since 1.2.0
 *
 * @param WP_MCP_AI_Tool_Interface $tool Tool instance.
 * @return bool True if cacheable, false otherwise.
 */
protected function is_cacheable( $tool ) {
/**
 * Filter whether a tool's results are cacheable.
 *
 * @since 1.2.0
 *
 * @param bool                     $cacheable Whether tool results are cacheable.
 * @param WP_MCP_AI_Tool_Interface $tool      Tool instance.
 */
return apply_filters( 'wp_mcp_ai_tool_cacheable', false, $tool );
}

/**
 * Check if a tool can execute in parallel.
 *
 * Determines if a tool can safely execute in parallel with
 * other tools without conflicts.
 *
 * @since 1.2.0
 *
 * @param WP_MCP_AI_Tool_Interface $tool Tool instance.
 * @return bool True if can execute in parallel, false otherwise.
 */
protected function can_parallel_execute( $tool ) {
/**
 * Filter whether a tool can execute in parallel.
 *
 * @since 1.2.0
 *
 * @param bool                     $parallel Whether tool can execute in parallel.
 * @param WP_MCP_AI_Tool_Interface $tool     Tool instance.
 */
return apply_filters( 'wp_mcp_ai_tool_can_parallel', true, $tool );
}

/**
 * Get tool metadata.
 *
 * Returns enhanced metadata for a tool if available.
 *
 * @since 1.2.0
 *
 * @param string $slug Tool slug.
 * @return array|null Tool metadata or null if not found.
 */
public function get_tool_metadata( $slug ) {
$this->init();

$slug = sanitize_key( $slug );

if ( ! isset( $this->tools[ $slug ] ) ) {
return null;
}

// Return default metadata for now.
return array(
'contexts'   => array( 'server' ),
'complexity' => 'medium',
'cacheable'  => false,
'parallel'   => true,
);
}
