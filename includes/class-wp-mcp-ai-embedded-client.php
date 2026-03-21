<?php
/**
 * Server-side Embedded LLM client.
 *
 * Manages GGUF model files stored in wp-content/uploads/mcp-ai-wpoos/models/
 * and runs inference via the llama.cpp llama-cli binary.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
exit;
}

if ( ! class_exists( 'WP_MCP_AI_Embedded_Client' ) ) {
/**
 * Server-side embedded LLM client using GGUF models and llama.cpp.
 *
 * Provides model management (download, delete, list) and server-side
 * chat completion via the llama-cli binary.
 */
class WP_MCP_AI_Embedded_Client {

/**
 * Minimum fraction of the expected model size that a downloaded file must
 * reach before it is considered complete.  0.5 = at least 50% of the
 * expected size.  GGUF files are binary, so any file smaller than half the
 * documented size is almost certainly incomplete or corrupt.
 */
const MIN_DOWNLOAD_RATIO = 0.5;

/**
 * Pre-configured GGUF models available for download.
 *
 * Each entry contains:
 *  - name          Human-readable display name.
 *  - filename      Local filename on disk (under models directory).
 *  - download_url  Direct GGUF download URL from Hugging Face.
 *  - size_mb       Approximate file size in megabytes.
 *  - ram_gb        Minimum RAM (GB) recommended on the server.
 *  - description   Short description shown in the admin UI.
 *
 * @var array
 */
protected static $available_models = array(
'qwen2-0.5b-instruct-q4_k_m' => array(
'name'         => 'Qwen2 0.5B Instruct (Q4_K_M)',
'filename'     => 'qwen2-0.5b-instruct-q4_k_m.gguf',
'download_url' => 'https://huggingface.co/Qwen/Qwen2-0.5B-Instruct-GGUF/resolve/main/qwen2-0.5b-instruct-q4_k_m.gguf',
'size_mb'      => 352,
'ram_gb'       => 2,
'description'  => 'Ultra-fast, minimal RAM. Good for simple tasks.',
),
'granite-3.1-2b-instruct-q4_k_m' => array(
'name'         => 'IBM Granite 3.1 2B Instruct (Q4_K_M)',
'filename'     => 'granite-3.1-2b-instruct-q4_k_m.gguf',
'download_url' => 'https://huggingface.co/ibm-granite/granite-3.1-2b-instruct-GGUF/resolve/main/granite-3.1-2b-instruct.Q4_K_M.gguf',
'size_mb'      => 1240,
'ram_gb'       => 4,
'description'  => 'Recommended. Best balance of speed and quality.',
),
'phi-3-mini-4k-instruct-q4' => array(
'name'         => 'Microsoft Phi-3 Mini 4K Instruct (Q4)',
'filename'     => 'phi-3-mini-4k-instruct-q4.gguf',
'download_url' => 'https://huggingface.co/microsoft/Phi-3-mini-4k-instruct-gguf/resolve/main/Phi-3-mini-4k-instruct-q4.gguf',
'size_mb'      => 2300,
'ram_gb'       => 6,
'description'  => 'Higher quality responses. Requires more RAM.',
),
);

// -------------------------------------------------------------------------
// Public API – model management
// -------------------------------------------------------------------------

/**
 * Get the absolute path to the models directory.
 *
 * Creates the directory (with an .htaccess guard) on first call.
 *
 * @return string Absolute path with trailing slash.
 */
public function get_models_directory() {
$upload_dir = wp_upload_dir();
$models_dir = trailingslashit( $upload_dir['basedir'] ) . 'mcp-ai-wpoos/models/';

if ( ! is_dir( $models_dir ) ) {
wp_mkdir_p( $models_dir );

// Deny direct HTTP access to raw model files.
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
$wrote = file_put_contents( $models_dir . '.htaccess', "Options -Indexes\nDeny from all\n" );
if ( false === $wrote ) {
// Log but don't halt – directory was created; .htaccess is a best-effort guard.
if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
WP_MCP_AI_Logger::log_event(
'embedded_htaccess_failed',
'Could not write .htaccess to models directory.',
array( 'dir' => $models_dir )
);
}
}
}

return $models_dir;
}

/**
 * Return the catalogue of pre-configured GGUF models.
 *
 * @return array Keyed by model slug.
 */
public function get_available_models() {
return static::$available_models;
}

/**
 * Return only the models that are already downloaded to disk.
 *
 * @return array Keyed by model slug; values include an extra 'file_size' key.
 */
public function get_downloaded_models() {
$models_dir = $this->get_models_directory();
$downloaded = array();

foreach ( static::$available_models as $slug => $model ) {
$path = $models_dir . $model['filename'];
if ( file_exists( $path ) ) {
$downloaded[ $slug ]              = $model;
$downloaded[ $slug ]['file_size'] = filesize( $path );
$downloaded[ $slug ]['modified']  = filemtime( $path );
}
}

return $downloaded;
}

/**
 * Check whether a specific model has been downloaded.
 *
 * @param string $slug Model slug.
 * @return bool
 */
public function is_model_downloaded( $slug ) {
if ( ! isset( static::$available_models[ $slug ] ) ) {
return false;
}

$models_dir = $this->get_models_directory();
$filename   = static::$available_models[ $slug ]['filename'];
$path       = $models_dir . $filename;

return file_exists( $path ) && filesize( $path ) > 0;
}

/**
 * Download a model from Hugging Face to the models directory.
 *
 * Uses wp_remote_get with a generous timeout because model files are
 * large (350 MB – 2.3 GB). Progress is not streamed; the download
 * blocks until complete or until the timeout is reached.
 *
 * @param string $slug Model slug from {@see get_available_models()}.
 * @return array|WP_Error Success array or WP_Error on failure.
 */
public function download_model( $slug ) {
if ( ! isset( static::$available_models[ $slug ] ) ) {
return new WP_Error(
'wp_mcp_ai_invalid_model',
sprintf(
/* translators: %s: model slug */
__( 'Invalid model slug: %s', 'mcp-ai-wpoos' ),
$slug
)
);
}

$model      = static::$available_models[ $slug ];
$models_dir = $this->get_models_directory();
$dest_path  = $models_dir . $model['filename'];

// Skip if already fully downloaded.
if ( file_exists( $dest_path ) && filesize( $dest_path ) > 0 ) {
return array(
'success'   => true,
'message'   => __( 'Model already downloaded.', 'mcp-ai-wpoos' ),
'file_size' => filesize( $dest_path ),
);
}

// Stream to a temp file first, then rename (atomic-ish move).
$tmp_path = $dest_path . '.tmp';

// Allow larger values to be set by the caller via filter.
$timeout = (int) apply_filters( 'wp_mcp_ai_embedded_download_timeout', 600 );

$response = wp_remote_get(
$model['download_url'],
array(
'timeout'  => $timeout,
'stream'   => true,
'filename' => $tmp_path,
'headers'  => array(
'User-Agent' => 'WP-MCP-AI/' . WP_MCP_AI_VERSION,
),
)
);

if ( is_wp_error( $response ) ) {
@unlink( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,Generic.PHP.NoSilencedErrors.Discouraged
return new WP_Error(
'wp_mcp_ai_download_failed',
sprintf(
/* translators: %s: error message */
__( 'Model download failed: %s', 'mcp-ai-wpoos' ),
$response->get_error_message()
)
);
}

$code = wp_remote_retrieve_response_code( $response );
if ( $code < 200 || $code >= 300 ) {
@unlink( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,Generic.PHP.NoSilencedErrors.Discouraged
return new WP_Error(
'wp_mcp_ai_download_failed',
sprintf(
/* translators: %d: HTTP status code */
__( 'Model download failed with HTTP status %d.', 'mcp-ai-wpoos' ),
$code
)
);
}

// Validate that the downloaded file has a reasonable size (at least
// MIN_DOWNLOAD_RATIO of the documented size).
$min_expected = (int) ( $model['size_mb'] * self::MIN_DOWNLOAD_RATIO * 1024 * 1024 );
if ( ! file_exists( $tmp_path ) || filesize( $tmp_path ) < $min_expected ) {
@unlink( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,Generic.PHP.NoSilencedErrors.Discouraged
return new WP_Error(
'wp_mcp_ai_download_incomplete',
__( 'Downloaded file appears incomplete or corrupt.', 'mcp-ai-wpoos' )
);
}

// Atomic rename.
if ( ! rename( $tmp_path, $dest_path ) ) {
@unlink( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,Generic.PHP.NoSilencedErrors.Discouraged
return new WP_Error(
'wp_mcp_ai_download_failed',
__( 'Could not save model file to disk.', 'mcp-ai-wpoos' )
);
}

return array(
'success'   => true,
'message'   => __( 'Model downloaded successfully.', 'mcp-ai-wpoos' ),
'file_size' => filesize( $dest_path ),
);
}

/**
 * Delete a downloaded model from the models directory.
 *
 * @param string $slug Model slug.
 * @return array|WP_Error
 */
public function delete_model( $slug ) {
if ( ! isset( static::$available_models[ $slug ] ) ) {
return new WP_Error(
'wp_mcp_ai_invalid_model',
sprintf(
/* translators: %s: model slug */
__( 'Invalid model slug: %s', 'mcp-ai-wpoos' ),
$slug
)
);
}

$models_dir = $this->get_models_directory();
$path       = $models_dir . static::$available_models[ $slug ]['filename'];

if ( ! file_exists( $path ) ) {
return new WP_Error(
'wp_mcp_ai_model_not_found',
__( 'Model file not found.', 'mcp-ai-wpoos' )
);
}

if ( ! unlink( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
return new WP_Error(
'wp_mcp_ai_delete_failed',
__( 'Could not delete model file.', 'mcp-ai-wpoos' )
);
}

return array(
'success' => true,
'message' => __( 'Model deleted successfully.', 'mcp-ai-wpoos' ),
);
}

// -------------------------------------------------------------------------
// Public API – inference
// -------------------------------------------------------------------------

/**
 * Test that the llama.cpp binary is reachable and executable.
 *
 * @return array|WP_Error
 */
public function test_connection() {
$binary = $this->get_inference_binary();

if ( is_wp_error( $binary ) ) {
return $binary;
}

// Run with --version flag; safe and quick.
$output = $this->run_binary( $binary, array( '--version' ) );

if ( is_wp_error( $output ) ) {
return $output;
}

if ( '' === $output ) {
return new WP_Error(
'wp_mcp_ai_binary_error',
__( 'llama-cli binary returned no output. Please verify the binary is executable.', 'mcp-ai-wpoos' )
);
}

return array(
'success' => true,
'message' => __( 'llama-cli binary is working.', 'mcp-ai-wpoos' ),
'version' => trim( $output ),
);
}

/**
 * Run a chat completion request via the llama.cpp binary.
 *
 * @param array $messages Array of message objects (role/content pairs).
 * @param array $options  Optional inference parameters:
 *                        - model        (string) Model slug to use.
 *                        - max_tokens   (int)    Maximum new tokens to generate.
 *                        - temperature  (float)  Sampling temperature.
 *                        - top_p        (float)  Top-p nucleus sampling.
 *                        - context_size (int)    Context window size.
 * @return array|WP_Error Array with 'choices' key or WP_Error.
 */
public function create_chat_completion( array $messages, array $options = array() ) {
// Resolve model slug.
$settings   = WP_MCP_AI_Admin_Settings::get_settings();
$model_slug = isset( $options['model'] ) ? sanitize_key( $options['model'] ) : '';
if ( empty( $model_slug ) ) {
$model_slug = isset( $settings['embedded_server_model'] )
? sanitize_key( $settings['embedded_server_model'] )
: '';
}

// Fall back to first downloaded model.
if ( empty( $model_slug ) || ! isset( static::$available_models[ $model_slug ] ) ) {
$downloaded = $this->get_downloaded_models();
if ( empty( $downloaded ) ) {
return new WP_Error(
'wp_mcp_ai_no_embedded_model',
__( 'No embedded GGUF model is downloaded. Please download a model in Settings → NV oOS → Providers → Embedded LLM (Pro).', 'mcp-ai-wpoos' )
);
}
reset( $downloaded );
$model_slug = key( $downloaded );
}

if ( ! $this->is_model_downloaded( $model_slug ) ) {
return new WP_Error(
'wp_mcp_ai_model_not_downloaded',
sprintf(
/* translators: %s: model name */
__( 'Embedded model "%s" is not downloaded. Please download it in Settings → NV oOS → Providers → Embedded LLM (Pro).', 'mcp-ai-wpoos' ),
static::$available_models[ $model_slug ]['name']
)
);
}

$binary = $this->get_inference_binary();
if ( is_wp_error( $binary ) ) {
return $binary;
}

$models_dir = $this->get_models_directory();
$model_path = $models_dir . static::$available_models[ $model_slug ]['filename'];

// Build inference parameters with clamped values.
$max_tokens   = max( 1, min( isset( $options['max_tokens'] ) ? (int) $options['max_tokens'] : 512, 4096 ) );
$temperature  = max( 0.0, min( isset( $options['temperature'] ) ? (float) $options['temperature'] : 0.7, 2.0 ) );
$top_p        = max( 0.0, min( isset( $options['top_p'] ) ? (float) $options['top_p'] : 0.9, 1.0 ) );
$context_size = max( 128, min( isset( $options['context_size'] ) ? (int) $options['context_size'] : 2048, 8192 ) );

$prompt = $this->build_prompt( $messages );

// Build argument array (no shell expansion – each element is a distinct argument).
$args = array(
'-m', $model_path,
'-p', $prompt,
'-n', (string) $max_tokens,
'--temp', number_format( $temperature, 2, '.', '' ),
'--top-p', number_format( $top_p, 2, '.', '' ),
'-c', (string) $context_size,
'--no-display-prompt',
);

$output = $this->run_binary( $binary, $args );

if ( is_wp_error( $output ) ) {
return $output;
}

if ( '' === $output ) {
return new WP_Error(
'wp_mcp_ai_inference_failed',
__( 'Embedded LLM inference produced no output. Please verify the binary and model file are valid.', 'mcp-ai-wpoos' )
);
}

$content = trim( $output );

// Log the event.
if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
WP_MCP_AI_Logger::log_event(
'embedded_inference_complete',
'Server-side embedded LLM inference complete',
array(
'model'         => $model_slug,
'prompt_length' => strlen( $prompt ),
'output_length' => strlen( $content ),
)
);
}

// Return in the same shape as the other clients so the router can
// treat it uniformly.
return array(
'choices' => array(
array(
'message'       => array(
'role'    => 'assistant',
'content' => $content,
),
'finish_reason' => 'stop',
),
),
'model'   => $model_slug,
'usage'   => array(
'prompt_tokens'     => 0,
'completion_tokens' => 0,
'total_tokens'      => 0,
),
);
}

// -------------------------------------------------------------------------
// Private helpers
// -------------------------------------------------------------------------

/**
 * Execute the llama-cli binary with the supplied arguments via proc_open.
 *
 * Using proc_open with a command array avoids shell-expansion and prevents
 * command injection regardless of the content of the arguments.
 *
 * @param string $binary Absolute path to the llama-cli binary.
 * @param array  $args   Argument tokens (each a separate element).
 * @return string|WP_Error Trimmed stdout/stderr output, or WP_Error on failure.
 */
private function run_binary( $binary, array $args ) {
$cmd = array_merge( array( $binary ), $args );

$descriptors = array(
0 => array( 'pipe', 'r' ),  // stdin.
1 => array( 'pipe', 'w' ),  // stdout.
2 => array( 'pipe', 'w' ),  // stderr.
);

// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open
$process = proc_open( $cmd, $descriptors, $pipes );

if ( ! is_resource( $process ) ) {
return new WP_Error(
'wp_mcp_ai_binary_exec_failed',
__( 'Could not start llama-cli process.', 'mcp-ai-wpoos' )
);
}

// Close stdin immediately (no input needed).
fclose( $pipes[0] );

$stdout = stream_get_contents( $pipes[1] );
$stderr = stream_get_contents( $pipes[2] );

fclose( $pipes[1] );
fclose( $pipes[2] );

$exit_code = proc_close( $process );

// Non-zero exit codes indicate a binary-level error.
if ( 0 !== $exit_code ) {
$error_detail = ! empty( $stderr ) ? trim( $stderr ) : 'exit code ' . $exit_code;
return new WP_Error(
'wp_mcp_ai_binary_error',
sprintf(
/* translators: %s: error detail from binary */
__( 'llama-cli exited with error: %s', 'mcp-ai-wpoos' ),
$error_detail
)
);
}

return (string) $stdout;
}

/**
 * Detect the current platform.
 *
 * @return array Array with 'os' (linux|darwin|windows) and 'arch' (x64|arm64|unknown) keys.
 */
private function detect_platform() {
$uname = php_uname( 's' );
$arch  = php_uname( 'm' );

if ( stripos( $uname, 'linux' ) !== false ) {
$os = 'linux';
} elseif ( stripos( $uname, 'darwin' ) !== false ) {
$os = 'darwin';
} elseif ( stripos( $uname, 'windows' ) !== false ) {
$os = 'windows';
} else {
$os = 'unknown';
}

if ( stripos( $arch, 'x86_64' ) !== false || stripos( $arch, 'amd64' ) !== false ) {
$cpu_arch = 'x64';
} elseif ( stripos( $arch, 'aarch64' ) !== false || stripos( $arch, 'arm64' ) !== false ) {
$cpu_arch = 'arm64';
} else {
$cpu_arch = 'unknown';
}

return array(
'os'   => $os,
'arch' => $cpu_arch,
'raw'  => $uname . ' ' . $arch,
);
}

/**
 * Check if the current server is hosted on Cloudways.
 *
 * @return bool
 */
private function is_cloudways_hosting() {
if ( defined( 'CLOUDWAYS_DEPLOYMENT' ) ) {
return true;
}

// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_getenv
if ( getenv( 'CLOUDWAYS_DEPLOYMENT' ) ) {
return true;
}

if ( file_exists( '/cloudways.yml' ) ) {
return true;
}

$hostname = gethostname();
if ( false !== $hostname && stripos( $hostname, 'cloudways' ) !== false ) {
return true;
}

return false;
}

/**
 * Find and return the path to the llama-cli binary.
 *
 * Searches in:
 *  1. Plugin bin directory (platform-specific sub-folder).
 *  2. Plugin bin directory (flat).
 *  3. System PATH (/usr/local/bin, /usr/bin).
 *  4. Configured path from settings (must be within allowed directories or
 *     must be named 'llama-cli' / 'llama-cli.exe' to prevent misuse).
 *
 * @return string|WP_Error Absolute path to the binary, or WP_Error if not found.
 */
private function get_inference_binary() {
$platform = $this->detect_platform();
$bin_name = 'windows' === $platform['os'] ? 'llama-cli.exe' : 'llama-cli';

// 1. Plugin bin directory – platform-specific sub-folder.
$plugin_dir      = WP_MCP_AI_PATH;
$platform_subdir = $plugin_dir . 'bin/llama.cpp/' . $platform['os'] . '-' . $platform['arch'] . '/' . $bin_name;
if ( is_executable( $platform_subdir ) ) {
return $platform_subdir;
}

// 2. Plugin bin directory – flat.
$plugin_flat = $plugin_dir . 'bin/llama.cpp/' . $bin_name;
if ( is_executable( $plugin_flat ) ) {
return $plugin_flat;
}

// 3. System PATH locations.
$system_paths = array(
'/usr/local/bin/' . $bin_name,
'/usr/bin/' . $bin_name,
'/opt/homebrew/bin/' . $bin_name,
);

foreach ( $system_paths as $sys_path ) {
if ( is_executable( $sys_path ) ) {
return $sys_path;
}
}

// 4. Settings-configured custom path.
// Validate that the binary name is exactly 'llama-cli' or 'llama-cli.exe'
// to prevent administrators from accidentally pointing to unrelated executables.
$settings    = WP_MCP_AI_Admin_Settings::get_settings();
$custom_path = isset( $settings['embedded_binary_path'] )
? sanitize_text_field( $settings['embedded_binary_path'] )
: '';

if ( ! empty( $custom_path ) ) {
$custom_basename = basename( $custom_path );
if ( in_array( $custom_basename, array( 'llama-cli', 'llama-cli.exe' ), true )
&& is_executable( $custom_path ) ) {
return $custom_path;
}
}

$instructions = $this->get_binary_installation_instructions( $platform['os'] );

return new WP_Error(
'wp_mcp_ai_binary_not_found',
sprintf(
/* translators: %s: installation instructions */
__( 'llama-cli binary not found. Please install it:\n\n%s', 'mcp-ai-wpoos' ),
$instructions
)
);
}

/**
 * Return human-readable installation instructions for llama.cpp.
 *
 * @param string $os Operating system identifier (linux|darwin|windows|unknown).
 * @return string Plain-text installation guide.
 */
private function get_binary_installation_instructions( $os ) {
$plugin_dir = WP_MCP_AI_PATH;

switch ( $os ) {
case 'linux':
return sprintf(
/* translators: %s: plugin bin directory */
__(
"Install llama-cli on Linux:\n\n" .
"1. Download the binary:\n" .
"   wget -O /tmp/llama-cli https://github.com/ggerganov/llama.cpp/releases/latest/download/llama-cli-ubuntu-x64\n\n" .
"2. Install to plugin directory:\n" .
"   mkdir -p %1\$s\n" .
"   mv /tmp/llama-cli %1\$s/llama-cli\n" .
"   chmod +x %1\$s/llama-cli\n\n" .
"3. Verify:\n" .
"   %1\$s/llama-cli --version",
'mcp-ai-wpoos'
),
$plugin_dir . 'bin/llama.cpp'
);

case 'darwin':
return __(
"Install llama-cli on macOS:\n\n" .
"1. Via Homebrew:\n" .
"   brew install llama.cpp\n\n" .
"2. Verify:\n" .
"   llama-cli --version",
'mcp-ai-wpoos'
);

case 'windows':
return sprintf(
/* translators: %s: plugin directory */
__(
"Install llama-cli on Windows:\n\n" .
"1. Download llama-cli.exe from:\n" .
"   https://github.com/ggerganov/llama.cpp/releases/latest\n\n" .
"2. Place in:\n" .
"   %s\\bin\\llama.cpp\\llama-cli.exe",
'mcp-ai-wpoos'
),
$plugin_dir
);

default:
return __(
"Install llama-cli from:\n" .
"https://github.com/ggerganov/llama.cpp/releases/latest\n\n" .
"Place the binary in the plugin's bin/llama.cpp/ directory.",
'mcp-ai-wpoos'
);
}
}

/**
 * Build a single prompt string from a messages array.
 *
 * Uses a simple but effective ChatML-style format that llama.cpp
 * (llama-cli) understands out of the box.
 *
 * @param array $messages Array of message objects with 'role' and 'content' keys.
 * @return string Formatted prompt.
 */
private function build_prompt( array $messages ) {
$prompt = '';

foreach ( $messages as $message ) {
$role    = isset( $message['role'] ) ? $message['role'] : 'user';
$content = isset( $message['content'] ) ? (string) $message['content'] : '';

switch ( $role ) {
case 'system':
$prompt .= '<|im_start|>system' . "\n" . $content . '<|im_end|>' . "\n";
break;
case 'assistant':
$prompt .= '<|im_start|>assistant' . "\n" . $content . '<|im_end|>' . "\n";
break;
case 'user':
default:
$prompt .= '<|im_start|>user' . "\n" . $content . '<|im_end|>' . "\n";
break;
}
}

// Append the assistant turn start so llama-cli continues from here.
$prompt .= '<|im_start|>assistant' . "\n";

return $prompt;
}
}
}
