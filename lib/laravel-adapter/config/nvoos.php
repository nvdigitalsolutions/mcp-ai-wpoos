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
	| Media worker
	|--------------------------------------------------------------------------
	|
	| The media worker is the same sidecar the WordPress plugin uses
	| (`addons/media-worker`): `GET /api/health`, `POST /api/image/optimize`.
	| Reusing it means the Laravel runtime gets Sharp-based processing without
	| a PHP image pipeline of its own.
	|
	| `url` empty means the media command centre is reported unavailable —
	| the console hides Media Ops rather than opening a dead page.
	|
	| `token` is sent as `X-Site-Token`; the worker accepts it in single-tenant
	| mode (`WORKER_API_TOKEN`) or maps it to a site in multi-tenant mode
	| (`SITE_TOKENS`). An empty token is only valid against a lenient
	| development worker.
	|
	*/
	'media_worker' => array(
		'url'     => env( 'NVOOS_MEDIA_WORKER_URL', '' ),
		'token'   => env( 'NVOOS_MEDIA_WORKER_TOKEN', '' ),
		'timeout' => (int) env( 'NVOOS_MEDIA_WORKER_TIMEOUT', 3 ),
		// Per-image optimisation is a real Sharp pipeline, not a probe.
		'optimize_timeout' => (int) env( 'NVOOS_MEDIA_WORKER_OPTIMIZE_TIMEOUT', 60 ),
	),

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
		/*
		 * Multi-agent teams (090-g Phase 4 parity). Mirrors the WordPress
		 * mcp_ai_team CPT and the meta keys its REST controller reads, so the
		 * shared console TeamsPage gets the same contract on both runtimes.
		 * Members are profession catalogue slugs rather than post IDs: the
		 * catalogue is reference data here.
		 */
		'mcp_ai_team'    => array(
			'label'        => 'Teams',
			'description'  => 'Multi-agent teams composed of profession members.',
			'public'       => false,
			'hierarchical' => false,
			'has_archive'  => false,
			'show_in_rest' => true,
			'rest_base'    => 'teams',
			'labels'       => array(
				'name'          => 'Teams',
				'singular_name' => 'Team',
				'add_new_item'  => 'Add New Team',
				'edit_item'     => 'Edit Team',
				'view_item'     => 'View Team',
				'search_items'  => 'Search Teams',
				'not_found'     => 'No teams found.',
				'all_items'     => 'All Teams',
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
				'_wp_mcp_ai_team_members'             => array(
					'label'       => 'Profession Members',
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Array of profession catalogue slugs.',
				),
				'_wp_mcp_ai_team_orchestration_mode'  => array(
					'label' => 'Orchestration Mode',
					'type'  => 'string',
				),
				'_wp_mcp_ai_team_result_aggregation'  => array(
					'label' => 'Result Aggregation',
					'type'  => 'string',
				),
				'_wp_mcp_ai_team_driver_assistant'    => array(
					'label' => 'Driver Assistant',
					'type'  => 'integer',
				),
			),
		),
		/*
		 * Classroom family (090-d cluster 3), local-mirror scope: the content
		 * types the ECA tools read, without the Google Classroom sync that
		 * populates them on WordPress. Google/ISAMS-backed fields are mirrored
		 * as strings so rows keep their shape and stay empty on this runtime.
		 */
		'mcp_ai_eca'     => array(
			'label'        => 'ECAs',
			'description'  => 'Extra-curricular activities (courses).',
			'public'       => false,
			'hierarchical' => false,
			'has_archive'  => false,
			'show_in_rest' => true,
			'rest_base'    => 'ecas',
			'labels'       => array(
				'name'          => 'ECAs',
				'singular_name' => 'ECA',
				'add_new_item'  => 'Add New ECA',
				'edit_item'     => 'Edit ECA',
				'view_item'     => 'View ECA',
				'search_items'  => 'Search ECAs',
				'not_found'     => 'No ECAs found.',
				'all_items'     => 'All ECAs',
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
				'_eca_status'               => array(
					'label' => 'Status',
					'type'  => 'string',
				),
				'_eca_type'                 => array(
					'label' => 'Type',
					'type'  => 'string',
				),
				'_eca_code'                 => array(
					'label' => 'Code',
					'type'  => 'string',
				),
				'_eca_venue'                => array(
					'label' => 'Venue',
					'type'  => 'string',
				),
				'_eca_day'                  => array(
					'label' => 'Day',
					'type'  => 'string',
				),
				'_eca_start_time'           => array(
					'label' => 'Start Time',
					'type'  => 'string',
				),
				'_eca_end_time'             => array(
					'label' => 'End Time',
					'type'  => 'string',
				),
				'_eca_booking_type'         => array(
					'label' => 'Booking Type',
					'type'  => 'string',
				),
				'_eca_cost'                 => array(
					'label' => 'Cost',
					'type'  => 'number',
				),
				'_eca_cost_period'          => array(
					'label' => 'Cost Period',
					'type'  => 'string',
				),
				'_eca_is_paid'              => array(
					'label' => 'Paid',
					// WordPress stores the checkbox encoding, not a boolean
					// (`get_post_meta(...) === 'yes'` in the ECA tools).
					'type'  => 'string',
					'enum'  => array( 'yes', 'no' ),
				),
				'_eca_requires_audition'    => array(
					'label' => 'Requires Audition',
					'type'  => 'string',
					'enum'  => array( 'yes', 'no' ),
				),
				'_eca_max_students'         => array(
					'label' => 'Maximum Students',
					'type'  => 'integer',
				),
				'_eca_current_enrollment'   => array(
					'label' => 'Current Enrolment',
					'type'  => 'integer',
				),
				'_eca_teachers'             => array(
					'label' => 'Teachers',
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'_eca_year_groups'          => array(
					'label' => 'Year Groups',
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'_eca_student_enrollments'  => array(
					'label'                => 'Student Enrolments',
					// Id-keyed map of enrolment records, not a list of ids:
					// every consumer reads `$enrolments[ $student_id ]`.
					'type'                 => 'object',
					'additionalProperties' => array(
						'type'       => 'object',
						'properties' => array(
							'enrollment_type' => array( 'type' => 'string' ),
							'enrollment_date' => array( 'type' => 'string' ),
							'payment_status'  => array( 'type' => 'string' ),
							'amount_due'      => array( 'type' => 'number' ),
							'notes'           => array( 'type' => 'string' ),
							'enrolled_by'     => array( 'type' => 'integer' ),
						),
					),
				),
				// Per-enrolment records. Nothing in the ECA family writes this
				// key — enrolment tools maintain `_eca_student_enrollments` —
				// but the WordPress detail tool reads it and a synced site may
				// have it populated, so the port accepts it too.
				'_eca_enrollments'          => array(
					'label' => 'Enrolment Records',
					'type'  => 'array',
				),
				'_eca_currency'             => array(
					'label' => 'Currency',
					'type'  => 'string',
				),
				'_eca_term'                 => array(
					'label' => 'Term',
					'type'  => 'string',
				),
				'_eca_sessions'             => array(
					'label' => 'Sessions',
					'type'  => 'array',
				),
				'_eca_attendance_log'       => array(
					'label'                => 'Attendance Log',
					// Date-keyed map of session records: `[ '2026-11-14' => … ]`.
					// Distinct from the enrolment maps, which are id-keyed.
					'type'                 => 'object',
					'additionalProperties' => array(
						'type'       => 'object',
						'properties' => array(
							'date'          => array( 'type' => 'string' ),
							'recorded_by'   => array( 'type' => 'integer' ),
							'recorded_at'   => array( 'type' => 'string' ),
							'session_notes' => array( 'type' => 'string' ),
							'attendees'     => array( 'type' => 'array' ),
							'present_count' => array( 'type' => 'integer' ),
							'absent_count'  => array( 'type' => 'integer' ),
							'late_count'    => array( 'type' => 'integer' ),
							'excused_count' => array( 'type' => 'integer' ),
						),
					),
				),
				'_eca_change_history'       => array(
					'label' => 'Change History',
					// Plain list of audit records (withdrawals today), appended to.
					'type'  => 'array',
				),
				// Populated by the WordPress-only Google Classroom sync.
				'_eca_google_course_id'     => array(
					'label' => 'Google Course ID',
					'type'  => 'string',
				),
				'_eca_classroom_sync'       => array(
					'label' => 'Classroom Sync State',
					'type'  => 'string',
				),
				'_eca_isams_sync_id'        => array(
					'label' => 'ISAMS Sync ID',
					'type'  => 'string',
				),
			),
		),
		'mcp_ai_student' => array(
			'label'        => 'Students',
			'description'  => 'Students enrolled in extra-curricular activities.',
			'public'       => false,
			'hierarchical' => false,
			'has_archive'  => false,
			'show_in_rest' => true,
			'rest_base'    => 'students',
			'labels'       => array(
				'name'          => 'Students',
				'singular_name' => 'Student',
				'add_new_item'  => 'Add New Student',
				'edit_item'     => 'Edit Student',
				'view_item'     => 'View Student',
				'search_items'  => 'Search Students',
				'not_found'     => 'No students found.',
				'all_items'     => 'All Students',
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
				'_student_first_name'     => array(
					'label' => 'First Name',
					'type'  => 'string',
				),
				'_student_last_name'      => array(
					'label' => 'Last Name',
					'type'  => 'string',
				),
				'_student_email'          => array(
					'label' => 'Email',
					'type'  => 'string',
				),
				'_student_year_group'     => array(
					'label' => 'Year Group',
					'type'  => 'string',
				),
				'_student_house'          => array(
					'label' => 'House',
					'type'  => 'string',
				),
				'_student_eca_enrollments' => array(
					'label'                => 'ECA Enrolments',
					// Id-keyed map of enrolment records, mirroring
					// `_eca_student_enrollments` on the activity side.
					'type'                 => 'object',
					'additionalProperties' => array(
						'type'       => 'object',
						'properties' => array(
							'enrollment_type' => array( 'type' => 'string' ),
							'enrollment_date' => array( 'type' => 'string' ),
							'payment_status'  => array( 'type' => 'string' ),
							'amount_due'      => array( 'type' => 'number' ),
							'notes'           => array( 'type' => 'string' ),
							'enrolled_by'     => array( 'type' => 'integer' ),
						),
					),
				),
				// Populated by the WordPress-only Classroom / ISAMS sync.
				'_student_google_id'      => array(
					'label' => 'Google User ID',
					'type'  => 'string',
				),
				'_student_isams_id'       => array(
					'label' => 'ISAMS ID',
					'type'  => 'string',
				),
				'_student_isams_synced'   => array(
					'label' => 'ISAMS Synced',
					'type'  => 'string',
					'enum'  => array( 'yes', 'no' ),
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
		'mcp_ai_eca_category' => array(
			'label'        => 'ECA Categories',
			'hierarchical' => true,
			'public'       => true,
			'description'  => 'Categories used to group extra-curricular activities.',
			'post_types'   => array( 'mcp_ai_eca' ),
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

		/*
		| Catalogue reads are cached under a key that embeds the manifest's
		| `catalogue_version`. Bumping the version invalidates every entry without
		| a purge; entries from the previous generation expire on their own TTL.
		| Set to 0 to disable caching (every read goes to disk).
		*/
		'cache_ttl' => (int) env( 'NVOOS_PROFESSIONS_CACHE_TTL', 86400 ),
	),

	/*
	|--------------------------------------------------------------------------
	| Skill catalogue
	|--------------------------------------------------------------------------
	|
	| Installed Agent Skills: one directory per skill, each holding a SKILL.md
	| whose YAML frontmatter declares `name` and `description`, optionally
	| `license` and `compatibility`. The console's assistant editor lists this
	| catalogue to populate its skill picker.
	|
	| A missing directory is an empty catalogue, not an error — a fresh install
	| has no skills. Installing or editing a skill is not available on this
	| runtime yet (the WordPress side ships a full manager).
	|
	*/
	'skills'           => array(
		'path' => env( 'NVOOS_SKILLS_PATH', storage_path( 'app/skills' ) ),
	),

	/*
	|--------------------------------------------------------------------------
	| OKF knowledge bundles
	|--------------------------------------------------------------------------
	|
	| One directory per bundle, each containing Markdown concepts with YAML
	| frontmatter (the OKF format). The console's chat drawer browses and
	| searches these through `GET /api/v1/okf/*`.
	|
	| WordPress keeps bundles in `uploads/mcp-ai-wpoos/knowledge`; this runtime
	| reads its own storage. A missing root is an empty bundle list, not an
	| error.
	|
	*/
	'okf'              => array(
		'path' => env( 'NVOOS_OKF_PATH', storage_path( 'app/knowledge' ) ),
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
	| Contract and schema versions
	|--------------------------------------------------------------------------
	|
	| `contract_version` is the HTTP contract the console speaks; it asserts
	| `mcp-ai/v1` + version 1 on boot and refuses to run against anything else.
	| `schema_version` is written into `nvoos_meta` so `nvoos:doctor` can tell an
	| operator to migrate instead of failing on a missing column.
	|
	*/
	'contract_version'     => (int) env( 'NVOOS_CONTRACT_VERSION', 1 ),
	'schema_version'       => (int) env( 'NVOOS_SCHEMA_VERSION', 1 ),

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
		/*
		 * Driver: allow | deny | config | license.
		 *
		 * `license` resolves against the commercial licence server — the same
		 * endpoint the WordPress plugin uses — keyed on `instance_id` instead
		 * of a site URL. The configuration drivers cover development, CI and
		 * fail-closed deployments. Unknown drivers fail closed.
		 */
		'driver'      => env( 'NVOOS_PRO_ENTITLEMENT_DRIVER', 'allow' ),

		// Plan label reported by the configuration drivers.
		'plan'        => env( 'NVOOS_PRO_PLAN', 'compliance' ),

		// Operator overrides, applied last in every driver. An explicit
		// entry wins in both directions.
		'features'    => array(),

		// --- `license` driver -------------------------------------------
		'license_key' => env( 'NVOOS_PRO_LICENSE_KEY', '' ),
		'server_url'  => env( 'NVOOS_PRO_LICENSE_SERVER', 'https://nvdigitalsolutions.com/api/licenses' ),

		// Stable identity for this installation. Defaults to the app URL
		// when null, which is what the licence server would see anyway.
		'instance_id' => env( 'NVOOS_PRO_LICENSE_INSTANCE_ID' ),
		'product'     => env( 'NVOOS_PRO_LICENSE_PRODUCT', 'mcp-ai-wpoos-pro' ),

		// Seconds a valid answer stays fresh, a negative answer stays
		// cached, and a stale answer may survive refresh failures.
		'ttl'         => (int) env( 'NVOOS_PRO_LICENSE_TTL', 43200 ),
		'denied_ttl'  => (int) env( 'NVOOS_PRO_LICENSE_DENIED_TTL', 300 ),
		'grace_ttl'   => (int) env( 'NVOOS_PRO_LICENSE_GRACE_TTL', 604800 ),
		'timeout'     => (int) env( 'NVOOS_PRO_LICENSE_TIMEOUT', 10 ),
	),

	/*
	|--------------------------------------------------------------------------
	| Read-only deployment profile
	|--------------------------------------------------------------------------
	|
	| Blocks state-changing tools at the last pre-dispatch point. Intended for
	| staging mirrors, demos and audit-only access. Tools that declare no
	| capability flags are blocked by default — "I could not tell whether this
	| writes" is not a reason to let it write. Allowlist the slugs you trust.
	|
	*/
	'read_only' => array(
		'enabled'          => (bool) env( 'NVOOS_READ_ONLY', false ),
		'gated_flags'      => array( 'write', 'state_changing', 'mutating', 'destructive', 'external_side_effect' ),
		'allowed_slugs'    => array(),
		'block_undeclared' => (bool) env( 'NVOOS_READ_ONLY_BLOCK_UNDECLARED', true ),
	),

	/*
	|--------------------------------------------------------------------------
	| Paper Store root
	|--------------------------------------------------------------------------
	|
	| Flat-file Paper Store collections live here: one JSON file per record at
	| `{root}/{collection}/{id}.json`, the same layout WordPress uses. Null means
	| `storage_path('paper-store')`.
	|
	*/
	'paper_store_root' => env( 'NVOOS_PAPER_STORE_ROOT' ),

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
		'project_management' => true,
		'calendar_booking'   => false,
		'fnb'                => false,
		'ecommerce'          => false,
		'ezuite'             => false,
		'shopify_sync'       => false,
		'flowhub'            => false,
		// The teams catalogue is readable, but the multi-agent orchestration
		// engine is not ported, so the runtime cannot execute a team. Flip
		// this only alongside a real orchestration implementation.
		'multi_agent_teams'  => false,
		'content_records'    => false,
		'knowledge_graph'    => false,
		'docs_hub'           => false,
		'media_studio'       => false,
		'document_editor'    => false,
		// The OKF knowledge drawer reads bundles from storage; browse, concept
		// and search are served. Grant-backed /okf/skills is honest-empty.
		'okf'                => true,
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
