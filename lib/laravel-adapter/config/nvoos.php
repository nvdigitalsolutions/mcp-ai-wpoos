<?php
/**
 * oOS Laravel configuration.
 *
 * Publish this file with: php artisan vendor:publish --tag=oos-config
 * Environment variables with the NVOOS_ prefix override these values.
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
	'content_model' => env( 'NVOOS_CONTENT_MODEL', \Nvoos\Laravel\Models\NvoosPost::class ),

	/*
	|--------------------------------------------------------------------------
	| Content schema
	|--------------------------------------------------------------------------
	|
	| Declarative equivalent of WordPress's register_post_type() and
	| register_taxonomy(). SchemaStoreInterface reads these keys so core tools
	| can introspect the content model the same way on both runtimes.
	|
	| The shape deliberately mirrors WordPress's post type arguments, and the
	| `supports` list mirrors post_type_supports(), so a ported tool cannot tell
	| which runtime answered.
	|
	*/
	'content_types'    => array(
		'post' => array(
			'label'        => 'Posts',
			'description'  => 'Default blog content type.',
			'public'       => true,
			'hierarchical' => false,
			'has_archive'  => true,
			'show_in_rest' => true,
			'rest_base'    => 'posts',
			'labels'       => array(
				'name'          => 'Posts',
				'singular_name' => 'Post',
				'add_new_item'  => 'Add New Post',
				'edit_item'     => 'Edit Post',
				'view_item'     => 'View Post',
				'search_items'  => 'Search Posts',
				'not_found'     => 'No posts found.',
				'all_items'     => 'All Posts',
			),
			'capabilities' => array(
				'edit_post'     => 'edit_post',
				'read_post'     => 'read_post',
				'delete_post'   => 'delete_post',
				'edit_posts'    => 'edit_posts',
				'publish_posts' => 'publish_posts',
			),
			'supports'     => array( 'title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments', 'revisions', 'custom-fields' ),
			'statuses'     => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'meta_fields'  => array(),
		),
		'page' => array(
			'label'        => 'Pages',
			'description'  => 'Hierarchical static content.',
			'public'       => true,
			'hierarchical' => true,
			'has_archive'  => false,
			'show_in_rest' => true,
			'rest_base'    => 'pages',
			'labels'       => array(
				'name'          => 'Pages',
				'singular_name' => 'Page',
				'add_new_item'  => 'Add New Page',
				'edit_item'     => 'Edit Page',
				'view_item'     => 'View Page',
				'search_items'  => 'Search Pages',
				'not_found'     => 'No pages found.',
				'all_items'     => 'All Pages',
			),
			'capabilities' => array(
				'edit_page'     => 'edit_page',
				'read_page'     => 'read_page',
				'delete_page'   => 'delete_page',
				'edit_pages'    => 'edit_pages',
				'publish_pages' => 'publish_pages',
			),
			'supports'     => array( 'title', 'editor', 'author', 'thumbnail', 'page-attributes', 'custom-fields', 'revisions' ),
			'statuses'     => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'meta_fields'  => array(),
		),
		/*
		 * Project management family (090-e §7 #1). Mirrors the WordPress
		 * mcp_ai_task / mcp_ai_project CPTs: same slugs, labels, supports and
		 * meta keys, so list_tasks / list_projects return the same rows and
		 * fields on both runtimes.
		 */
		'mcp_ai_task'    => array(
			'label'        => 'Tasks',
			'description'  => 'Project management tasks.',
			'public'       => false,
			'hierarchical' => false,
			'has_archive'  => false,
			'show_in_rest' => true,
			'rest_base'    => 'tasks',
			'labels'       => array(
				'name'          => 'Tasks',
				'singular_name' => 'Task',
				'add_new_item'  => 'Add New Task',
				'edit_item'     => 'Edit Task',
				'view_item'     => 'View Task',
				'search_items'  => 'Search Tasks',
				'not_found'     => 'No tasks found.',
				'all_items'     => 'All Tasks',
			),
			'capabilities' => array(
				'edit_post'     => 'edit_post',
				'read_post'     => 'read_post',
				'delete_post'   => 'delete_post',
				'edit_posts'    => 'edit_posts',
				'publish_posts' => 'publish_posts',
			),
			'supports'     => array( 'title', 'editor', 'author' ),
			'statuses'     => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'meta_fields'  => array(
				'_task_status'           => array(
					'label' => 'Task Status',
					'type'  => 'string',
					'enum'  => array( 'todo', 'in-progress', 'review', 'completed', 'cancelled' ),
				),
				'_task_priority'         => array(
					'label' => 'Priority',
					'type'  => 'string',
					'enum'  => array( 'low', 'medium', 'high', 'urgent' ),
				),
				'_task_category'         => array(
					'label' => 'Category',
					'type'  => 'string',
					'enum'  => array( 'general', 'bug', 'feature', 'maintenance', 'research', 'documentation', 'design', 'testing' ),
				),
				'_task_project_id'       => array(
					'label' => 'Project ID',
					'type'  => 'integer',
				),
				'_task_due_date'         => array(
					'label'  => 'Due Date',
					'type'   => 'string',
					'format' => 'date',
				),
				'_task_assigned_to'      => array(
					'label' => 'Assigned To',
					'type'  => 'integer',
				),
				'_task_tags'             => array(
					'label' => 'Tags',
					'type'  => 'string',
				),
				'_task_estimated_effort' => array(
					'label' => 'Estimated Effort (hours)',
					'type'  => 'number',
				),
				'_task_actual_effort'    => array(
					'label' => 'Actual Effort (hours)',
					'type'  => 'number',
				),
				'_task_depends_on'       => array(
					'label' => 'Depends On',
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'_task_blocks'           => array(
					'label' => 'Blocks',
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
			),
		),
		'mcp_ai_project' => array(
			'label'        => 'Projects',
			'description'  => 'Project management projects.',
			'public'       => false,
			'hierarchical' => false,
			'has_archive'  => false,
			'show_in_rest' => true,
			'rest_base'    => 'projects',
			'labels'       => array(
				'name'          => 'Projects',
				'singular_name' => 'Project',
				'add_new_item'  => 'Add New Project',
				'edit_item'     => 'Edit Project',
				'view_item'     => 'View Project',
				'search_items'  => 'Search Projects',
				'not_found'     => 'No projects found.',
				'all_items'     => 'All Projects',
			),
			'capabilities' => array(
				'edit_post'     => 'edit_post',
				'read_post'     => 'read_post',
				'delete_post'   => 'delete_post',
				'edit_posts'    => 'edit_posts',
				'publish_posts' => 'publish_posts',
			),
			'supports'     => array( 'title', 'editor', 'author', 'thumbnail' ),
			'statuses'     => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'meta_fields'  => array(
				'_project_status'      => array(
					'label' => 'Project Status',
					'type'  => 'string',
					'enum'  => array( 'planning', 'active', 'on-hold', 'completed', 'cancelled' ),
				),
				'_project_start_date'  => array(
					'label'  => 'Start Date',
					'type'   => 'string',
					'format' => 'date',
				),
				'_project_end_date'    => array(
					'label'  => 'End Date',
					'type'   => 'string',
					'format' => 'date',
				),
				'_project_assigned_to' => array(
					'label'       => 'Assigned To',
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					// String IDs keep membership matching portable: the filter
					// is LIKE '%"<id>"%', which matches a JSON array element
					// and a PHP-serialized string element alike.
					'description' => 'Array of user IDs stored as strings.',
				),
			),
		),
	),

	'content_statuses' => array(
		'publish' => 'Published',
		'future'  => 'Scheduled',
		'draft'   => 'Draft',
		'pending' => 'Pending Review',
		'private' => 'Private',
	),

	'taxonomies'       => array(
		'category' => array(
			'label'        => 'Categories',
			'hierarchical' => true,
			'public'       => true,
			'description'  => '',
			'post_types'   => array( 'post' ),
		),
		'post_tag' => array(
			'label'        => 'Tags',
			'hierarchical' => false,
			'public'       => true,
			'description'  => '',
			'post_types'   => array( 'post' ),
		),
	),

	/*
	|--------------------------------------------------------------------------
	| Profession catalogue
	|--------------------------------------------------------------------------
	|
	| Professions are reference data: code-owned, versioned and read-only.
	| Point this at a licensed catalogue directory containing JSON documents
	| with a `professions` array — the same shape the WordPress plugin seeds
	| its profession CPT from. The shipped starter catalogue is MIT-licensed;
	| a deployment can point NVOOS_PROFESSIONS_PATH at a fuller one.
	|
	*/
	'professions'      => array(
		'path' => env( 'NVOOS_PROFESSIONS_PATH', __DIR__ . '/../resources/professions' ),
	),

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
	| Override via environment: NVOOS_DEFAULT_PROVIDER, NVOOS_DEFAULT_MODEL.
	|
	*/
	'default_provider'     => env( 'NVOOS_DEFAULT_PROVIDER', 'openai' ),
	'default_model'        => env( 'NVOOS_DEFAULT_MODEL', 'gpt-4o-mini' ),
	'default_gemini_model' => env( 'NVOOS_DEFAULT_GEMINI_MODEL', 'gemini-2.0-flash' ),

	/*
	|--------------------------------------------------------------------------
	| HTTP Request Configuration
	|--------------------------------------------------------------------------
	|
	| Timeout and retry settings for calls to AI provider APIs.
	|
	*/
	'request_timeout' => (int) env( 'NVOOS_REQUEST_TIMEOUT', 60 ),

	/*
	|--------------------------------------------------------------------------
	| Rate Limiting
	|--------------------------------------------------------------------------
	*/
	'enable_rate_limiting' => (bool) env( 'NVOOS_ENABLE_RATE_LIMITING', false ),
	'rate_limit_requests'  => (int) env( 'NVOOS_RATE_LIMIT_REQUESTS', 100 ),
	'rate_limit_window'    => (int) env( 'NVOOS_RATE_LIMIT_WINDOW', 3600 ),

	/*
	|--------------------------------------------------------------------------
	| Console rate limit
	|--------------------------------------------------------------------------
	|
	| The console issues many small reads per page. Tool execution keeps its
	| own tighter limiter (`throttle:tools`); this budget covers admin CRUD.
	|
	*/
	'console_rate_limit'   => (int) env( 'NVOOS_CONSOLE_RATE_LIMIT', 600 ),

	/*
	|--------------------------------------------------------------------------
	| Media uploads
	|--------------------------------------------------------------------------
	|
	| Uploads live on their own disk because the media library must serve
	| public URLs; the general storage disk may be private (S3, local).
	|
	*/
	'media_disk'           => env( 'NVOOS_MEDIA_DISK', 'public' ),
	'upload_max_bytes'     => (int) env( 'NVOOS_UPLOAD_MAX_BYTES', 10485760 ),

	/*
	|--------------------------------------------------------------------------
	| Pro toolkit manifests
	|--------------------------------------------------------------------------
	|
	| Directory of JSON toolkit manifests. Empty falls back to
	| resources/pro-toolkits in the app.
	|
	*/
	'toolkit_manifests_path' => env( 'NVOOS_TOOLKIT_MANIFESTS', '' ),

	/*
	|--------------------------------------------------------------------------
	| Feature Flags
	|--------------------------------------------------------------------------
	*/
	'enable_high_token_model_switch' => (bool) env( 'NVOOS_HIGH_TOKEN_MODEL_SWITCH', true ),
	'enable_multi_agent_teams'       => (bool) env( 'NVOOS_MULTI_AGENT_TEAMS', true ),
	'enable_acp_server'              => (bool) env( 'NVOOS_ACP_SERVER', false ),
	'enable_a2a_server'              => (bool) env( 'NVOOS_A2A_SERVER', false ),
	'enable_chat_memory'             => (bool) env( 'NVOOS_CHAT_MEMORY', true ),

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
	'cache_store'      => env( 'NVOOS_CACHE_STORE', env( 'CACHE_STORE', 'redis' ) ),
	'queue_connection' => env( 'NVOOS_QUEUE_CONNECTION', env( 'OOS_QUEUE_CONNECTION', env( 'QUEUE_CONNECTION', 'database' ) ) ),
	'storage_disk'     => env( 'NVOOS_STORAGE_DISK', env( 'FILESYSTEM_DISK', 'public' ) ),

	/*
	|--------------------------------------------------------------------------
	| Settings Database Table
	|--------------------------------------------------------------------------
	|
	| Table name for runtime-overridden settings. The SettingsStore adapter
	| reads from config first, then this table for admin-saved overrides.
	|
	*/
	'settings_table' => env( 'NVOOS_SETTINGS_TABLE', 'nvoos_settings' ),

	/*
	|--------------------------------------------------------------------------
	| Pro Entitlement
	|--------------------------------------------------------------------------
	|
	| The WordPress licence record does not exist outside WordPress. Choose a
	| driver explicitly: allow (development/CI), deny (base-only), or config
	| (only the features listed below). Unknown drivers fail closed. The
	| commercial source is wired behind this same contract later.
	|
	*/
	'pro_entitlement' => array(
		'driver'   => env( 'NVOOS_PRO_ENTITLEMENT_DRIVER', 'allow' ),
		'plan'     => env( 'NVOOS_PRO_PLAN', 'compliance' ),
		'features' => array(),
	),

	/*
	|--------------------------------------------------------------------------
	| Console Feature Map
	|--------------------------------------------------------------------------
	|
	| Keys the Complete Console's nav reads (GET /api/v1/features). WordPress
	| addon surfaces are disabled on a pure Laravel runtime until their
	| endpoints are ported (Proposal 090-B, Phase 4).
	|
	*/
	'features' => array(
		'crm'                => false,
		'project_management' => false,
		'calendar_booking'   => false,
		'fnb'                => false,
		'ecommerce'          => false,
		'ezuite'             => false,
		'shopify_sync'       => false,
		'flowhub'            => false,
		'content_records'    => false,
		'knowledge_graph'    => false,
		'docs_hub'           => false,
		'media_studio'       => false,
		'document_editor'    => false,
		'okf'                => false,
	),

	/*
	|--------------------------------------------------------------------------
	| Mesh API Key
	|--------------------------------------------------------------------------
	|
	| Shared secret for mesh-network authentication. Mesh-authenticated
	| requests have full administrative access.
	|
	*/
	'mesh_api_key' => env( 'NVOOS_MESH_API_KEY', '' ),

);
