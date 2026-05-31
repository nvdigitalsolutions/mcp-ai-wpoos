<?php
/**
 * oOS Laravel configuration.
 *
 * Publish this file with: php artisan vendor:publish --tag=oos-config
 * Environment variables with the OOS_ prefix override these values.
 */

return array(

	/*
	|--------------------------------------------------------------------------
	| Content Model
	|--------------------------------------------------------------------------
	|
	| The Eloquent model that oOS tools use when reading and writing content.
	| Set this to your application's content model (e.g., App\Models\Post).
	| The model must have at minimum: id, title, content, status, type,
	| author_id, created_at, updated_at columns.
	|
	*/
	'content_model' => env( 'OOS_CONTENT_MODEL', \Oos\Laravel\Models\OosPost::class ),

	/*
	|--------------------------------------------------------------------------
	| AI Provider API Keys
	|--------------------------------------------------------------------------
	|
	| API keys for each AI provider. Prefer the standard Laravel services
	| config (services.openai.key) which is picked up automatically.
	| These values are fallbacks used when services config is absent.
	|
	*/
	'api_keys' => array(
		'openai'       => env( 'OPENAI_API_KEY' ),
		'gemini'       => env( 'GEMINI_API_KEY' ),
		'anthropic'    => env( 'ANTHROPIC_API_KEY' ),
		'deepseek'     => env( 'DEEPSEEK_API_KEY' ),
		'openrouter'   => env( 'OPENROUTER_API_KEY' ),
		'kimi'         => env( 'KIMI_API_KEY' ),
		'digitalocean' => env( 'DIGITALOCEAN_API_KEY' ),
		'nvidia_nim'   => env( 'NVIDIA_NIM_API_KEY' ),
		'cloudflare'   => env( 'CLOUDFLARE_API_KEY' ),
	),

	/*
	|--------------------------------------------------------------------------
	| Default Provider & Model
	|--------------------------------------------------------------------------
	|
	| The provider and model used when none is specified per-request.
	| Override via environment: OOS_DEFAULT_PROVIDER, OOS_DEFAULT_MODEL.
	|
	*/
	'default_provider'     => env( 'OOS_DEFAULT_PROVIDER', 'openai' ),
	'default_model'        => env( 'OOS_DEFAULT_MODEL', 'gpt-4o-mini' ),
	'default_gemini_model' => env( 'OOS_DEFAULT_GEMINI_MODEL', 'gemini-2.0-flash' ),

	/*
	|--------------------------------------------------------------------------
	| HTTP Request Configuration
	|--------------------------------------------------------------------------
	|
	| Timeout and retry settings for calls to AI provider APIs.
	|
	*/
	'request_timeout' => (int) env( 'OOS_REQUEST_TIMEOUT', 60 ),

	/*
	|--------------------------------------------------------------------------
	| Rate Limiting
	|--------------------------------------------------------------------------
	*/
	'enable_rate_limiting' => (bool) env( 'OOS_ENABLE_RATE_LIMITING', false ),
	'rate_limit_requests'  => (int) env( 'OOS_RATE_LIMIT_REQUESTS', 100 ),
	'rate_limit_window'    => (int) env( 'OOS_RATE_LIMIT_WINDOW', 3600 ),

	/*
	|--------------------------------------------------------------------------
	| Feature Flags
	|--------------------------------------------------------------------------
	*/
	'enable_high_token_model_switch' => (bool) env( 'OOS_HIGH_TOKEN_MODEL_SWITCH', true ),
	'enable_multi_agent_teams'       => (bool) env( 'OOS_MULTI_AGENT_TEAMS', true ),
	'enable_acp_server'              => (bool) env( 'OOS_ACP_SERVER', false ),
	'enable_a2a_server'              => (bool) env( 'OOS_A2A_SERVER', false ),
	'enable_chat_memory'             => (bool) env( 'OOS_CHAT_MEMORY', true ),

	/*
	|--------------------------------------------------------------------------
	| REST API Visibility
	|--------------------------------------------------------------------------
	*/
	'rest_enable_assistant_list'   => true,
	'rest_enable_assistant_create' => false,
	'rest_enable_assistant_delete' => false,

	/*
	|--------------------------------------------------------------------------
	| Cache & Queue Configuration
	|--------------------------------------------------------------------------
	*/
	'cache_store'      => env( 'OOS_CACHE_STORE', 'redis' ),
	'queue_connection' => env( 'OOS_QUEUE_CONNECTION', 'database' ),
	'storage_disk'     => env( 'OOS_STORAGE_DISK', 'public' ),

	/*
	|--------------------------------------------------------------------------
	| Settings Database Table
	|--------------------------------------------------------------------------
	|
	| Table name for runtime-overridden settings. The SettingsStore adapter
	| reads from config first, then this table for admin-saved overrides.
	|
	*/
	'settings_table' => env( 'OOS_SETTINGS_TABLE', 'oos_settings' ),

	/*
	|--------------------------------------------------------------------------
	| Mesh API Key
	|--------------------------------------------------------------------------
	|
	| Shared secret for mesh-network authentication. Mesh-authenticated
	| requests have full administrative access.
	|
	*/
	'mesh_api_key' => env( 'OOS_MESH_API_KEY', '' ),

);
