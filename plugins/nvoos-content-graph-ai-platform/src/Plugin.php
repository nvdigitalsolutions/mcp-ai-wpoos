<?php
declare(strict_types=1);

namespace NvoosContentGraphAiPlatform;

final class Plugin {

	private static ?self $instance = null;

	private function __construct() {}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		// Loud degradation notice when subsystems have no implementation
		// (base plugin absent, or greenfield subsystems not yet built).
		RuntimeMode::register();

		// Post types register on init — must be hooked before admin_menu fires.
		$this->registerPostTypes();

		if ( is_admin() ) {
			$this->registerAdmin();
		}

		$this->registerAgents();
		$this->registerSkills();
		$this->registerSlashCommands();
		$this->registerHarness();
		$this->registerMeasurement();
		$this->registerProfessions();
		$this->registerTeams();
		$this->registerA2A();
		$this->registerACP();
		$this->registerFederation();
		$this->registerBlueprints();
		$this->registerQueues();
		$this->registerQueueManager();
		$this->registerDeadLetterQueue();
		$this->registerOutboundWebhook();
		$this->registerCronManager();
		$this->registerApprovals();
		$this->registerJobNotifier();
		$this->registerJobNotifierRest();
		$this->registerA2aRest();
		$this->registerWorkflowCpts();
		$this->registerTenant();
		$this->registerIntegrations();
		$this->registerGoogleCalendar();
		$this->registerContentAssistant();
	}

	private function registerAdmin(): void {
		// 1. Own top-level "NV Platform" menu + tabbed dashboard.
		if ( class_exists( __NAMESPACE__ . '\Admin\PlatformDashboard' ) ) {
			( new \NvoosContentGraphAiPlatform\Admin\PlatformDashboard() )->register();
		}

		// 2. Built-in dashboard sections (Overview + General).
		$this->registerBuiltinSections();

		// 3. (Optional) Keep Content Graph tab-injection as courtesy.
		if ( class_exists( __NAMESPACE__ . '\Admin\PlatformSettings' ) ) {
			( new \NvoosContentGraphAiPlatform\Admin\PlatformSettings() )->register();
		}
	}

	/**
	 * Register built-in dashboard sections.
	 *
	 * Overview and General are core sections that always render.
	 * Subsystem sections self-register via the
	 * `nvoos_content_graph_ai_platform_admin_register_sections` action.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function registerBuiltinSections(): void {
		add_action(
			'nvoos_content_graph_ai_platform_admin_register_sections',
			static function (): void {
				if ( class_exists( 'NvoosContentGraphAiPlatform\Admin\Sections\OverviewSection' ) ) {
					\NvoosContentGraphAiPlatform\Admin\PlatformSettingsRegistry::register_section(
						new \NvoosContentGraphAiPlatform\Admin\Sections\OverviewSection()
					);
				}
				if ( class_exists( 'NvoosContentGraphAiPlatform\Admin\Sections\GeneralSection' ) ) {
					\NvoosContentGraphAiPlatform\Admin\PlatformSettingsRegistry::register_section(
						new \NvoosContentGraphAiPlatform\Admin\Sections\GeneralSection()
					);
				}
			}
		);
	}

	/**
	 * Register custom post types owned by the Platform addon.
	 *
	 * All CPTs appear under the "NV Platform" admin menu.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function registerPostTypes(): void {
		if ( class_exists( __NAMESPACE__ . '\PostTypes\ProjectCpt' ) ) {
			( new \NvoosContentGraphAiPlatform\PostTypes\ProjectCpt() )->register();
		}
		if ( class_exists( __NAMESPACE__ . '\PostTypes\ResourceCpt' ) ) {
			( new \NvoosContentGraphAiPlatform\PostTypes\ResourceCpt() )->register();
		}
		if ( class_exists( __NAMESPACE__ . '\PostTypes\TemplateCpt' ) ) {
			( new \NvoosContentGraphAiPlatform\PostTypes\TemplateCpt() )->register();
		}
	}

	private function registerAgents(): void {
		if ( class_exists( __NAMESPACE__ . '\Agents\Agents' ) ) {
			\NvoosContentGraphAiPlatform\Agents\Agents::instance()->register();
		}
	}

	private function registerSkills(): void {
		if ( class_exists( __NAMESPACE__ . '\Skills\SkillService' ) ) {
			\NvoosContentGraphAiPlatform\Skills\SkillService::instance()->register();
		}
	}

	private function registerSlashCommands(): void {
		if ( class_exists( __NAMESPACE__ . '\SlashCommands\SlashCommandService' ) ) {
			\NvoosContentGraphAiPlatform\SlashCommands\SlashCommandService::instance()->register();
		}
	}

	private function registerHarness(): void {
		if ( class_exists( __NAMESPACE__ . '\Harness\HarnessService' ) ) {
			\NvoosContentGraphAiPlatform\Harness\HarnessService::instance()->register();
		}
	}

	private function registerMeasurement(): void {
		if ( class_exists( __NAMESPACE__ . '\Measurement\MeasurementService' ) ) {
			\NvoosContentGraphAiPlatform\Measurement\MeasurementService::instance()->register();
		}
	}

	private function registerProfessions(): void {
		if ( class_exists( __NAMESPACE__ . '\Professions\ProfessionService' ) ) {
			\NvoosContentGraphAiPlatform\Professions\ProfessionService::instance()->register();
		}
	}

	private function registerTeams(): void {
		if ( class_exists( __NAMESPACE__ . '\Teams\TeamsService' ) ) {
			\NvoosContentGraphAiPlatform\Teams\TeamsService::instance()->register();
		}
	}

	private function registerA2A(): void {
		if ( class_exists( __NAMESPACE__ . '\A2A\A2AService' ) ) {
			\NvoosContentGraphAiPlatform\A2A\A2AService::instance()->register();
		}
	}

	private function registerACP(): void {
		if ( class_exists( __NAMESPACE__ . '\ACP\ACPService' ) ) {
			\NvoosContentGraphAiPlatform\ACP\ACPService::instance()->register();
		}
	}

	private function registerFederation(): void {
		if ( class_exists( __NAMESPACE__ . '\Federation\FederationService' ) ) {
			\NvoosContentGraphAiPlatform\Federation\FederationService::instance()->register();
		}
	}

	private function registerBlueprints(): void {
		if ( class_exists( __NAMESPACE__ . '\Blueprints\BlueprintService' ) ) {
			\NvoosContentGraphAiPlatform\Blueprints\BlueprintService::instance()->register();
		}
	}

	/**
	 * Register the async job queue (Wave E2).
	 *
	 * Standalone-only: the base plugin owns the same table and cron hooks
	 * in monolith installs; double registration would double-consume jobs.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	private function registerQueues(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		if ( class_exists( __NAMESPACE__ . '\Queues\AsyncJobQueue' ) ) {
			\NvoosContentGraphAiPlatform\Queues\AsyncJobQueue::init();
		}
	}

	/**
	 * Register the tool-execution queue manager (Wave E2).
	 *
	 * Standalone-only: the base plugin owns the same
	 * `wp_mcp_ai_before_tool_execute` filter and queue-status AJAX action
	 * in monolith installs; double registration would double-queue tool
	 * executions. Hooks only register when RabbitMQ is explicitly enabled
	 * (byte-identical feature-flag contract).
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	private function registerQueueManager(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		if ( class_exists( __NAMESPACE__ . '\Queues\QueueManager' ) ) {
			\NvoosContentGraphAiPlatform\Queues\QueueManager::get_instance();
		}
	}

	/**
	 * Register the dead letter queue (Wave E2).
	 *
	 * Standalone-only: the base plugin owns the same table and cron hooks
	 * in monolith installs; double registration would double-schedule the
	 * weekly cleanup.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	private function registerDeadLetterQueue(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		if ( ! class_exists( __NAMESPACE__ . '\Queues\DeadLetterQueue' ) ) {
			return;
		}

		\NvoosContentGraphAiPlatform\Queues\DeadLetterQueue::create_table();

		add_action( 'init', array( \NvoosContentGraphAiPlatform\Queues\DeadLetterQueue::class, 'schedule_cleanup' ) );
		add_action( 'wp_mcp_ai_dlq_cleanup', array( \NvoosContentGraphAiPlatform\Queues\DeadLetterQueue::class, 'cleanup' ) );
	}

	/**
	 * Register the outbound webhook manager (Wave E2).
	 *
	 * Standalone-only: the base plugin's loader owns the same listener
	 * registration in monolith installs; double registration would
	 * double-dispatch every workflow/approval event.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	private function registerOutboundWebhook(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		if ( class_exists( __NAMESPACE__ . '\Queues\OutboundWebhook' ) ) {
			\NvoosContentGraphAiPlatform\Queues\OutboundWebhook::get_instance();
		}
	}

	/**
	 * Register the cron manager (Wave E2).
	 *
	 * Standalone-only: the base plugin owns the same `init` prune hook in
	 * monolith installs; double registration would double-prune the shared
	 * `wp_mcp_ai_cron_jobs` option.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	private function registerCronManager(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		if ( class_exists( __NAMESPACE__ . '\Queues\CronManager' ) ) {
			\NvoosContentGraphAiPlatform\Queues\CronManager::init();
		}
	}

	/**
	 * Register the HITL approval queue (Wave E3).
	 *
	 * Standalone-only: the base bootstrap loader owns the same `init`
	 * registration (CPT priority 11, cleanup cron priority 1) in monolith
	 * installs; double registration would collide on the shared
	 * `mcp_ai_approval` CPT slug.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	private function registerApprovals(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		if ( class_exists( __NAMESPACE__ . '\Approvals\ApprovalQueue' ) ) {
			add_action( 'init', array( \NvoosContentGraphAiPlatform\Approvals\ApprovalQueue::class, 'register_cpt' ), 11 );
			add_action( 'init', array( \NvoosContentGraphAiPlatform\Approvals\ApprovalQueue::class, 'register_cron' ), 1 );
		}
	}

	/**
	 * Register the job notifier (Wave E2).
	 *
	 * Standalone-only: the base plugin's job-notifier-init.php owns the
	 * same lifecycle-hook + cleanup-cron registration in monolith installs;
	 * double registration would double-cache every job event.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	private function registerJobNotifier(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		if ( class_exists( __NAMESPACE__ . '\Queues\JobNotifier' ) ) {
			\NvoosContentGraphAiPlatform\Queues\JobNotifier::init();
		}
	}

	/**
	 * Register the job notifier REST routes (Wave E2).
	 *
	 * Standalone-only: the base plugin's job-notifier-init.php owns the
	 * same `mcp-ai/v1` jobs routes in monolith installs; double
	 * registration would collide on the shared namespace.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	private function registerJobNotifierRest(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		if ( class_exists( __NAMESPACE__ . '\Rest\JobNotifierRestController' ) ) {
			\NvoosContentGraphAiPlatform\Rest\JobNotifierRestController::init();
		}
	}

	/**
	 * Register the A2A REST receive routes (Wave E5).
	 *
	 * Standalone-only: the base loader owns the same `mcp-ai/v1/a2a`
	 * routes in monolith installs (boot-gated on `enable_a2a_server`);
	 * double registration would collide on the shared namespace. The
	 * request-level `a2a_disabled` gate is enforced per-request in both
	 * modes.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	private function registerA2aRest(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		if ( class_exists( __NAMESPACE__ . '\Rest\A2aRestController' ) ) {
			\NvoosContentGraphAiPlatform\Rest\A2aRestController::init();
		}
	}

	/**
	 * Register the workflow/run/trigger CPTs (Wave E1, sub-cluster 1).
	 *
	 * Standalone-only: the base bootstrap loader owns the same `init`
	 * registration in monolith installs (workflow at priority 12, run at
	 * 13, trigger at 14 + trigger hooking at 20); double registration
	 * would collide on the shared CPT slugs.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	private function registerWorkflowCpts(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		if ( class_exists( __NAMESPACE__ . '\Workflows\WorkflowCpt' ) ) {
			add_action( 'init', array( \NvoosContentGraphAiPlatform\Workflows\WorkflowCpt::class, 'register_cpt' ), 12 );
			add_action( 'init', array( \NvoosContentGraphAiPlatform\Workflows\WorkflowCpt::class, 'register_meta' ), 12 );
		}

		if ( class_exists( __NAMESPACE__ . '\Workflows\WorkflowRunCpt' ) ) {
			add_action( 'init', array( \NvoosContentGraphAiPlatform\Workflows\WorkflowRunCpt::class, 'register_cpt' ), 13 );
			add_action( 'init', array( \NvoosContentGraphAiPlatform\Workflows\WorkflowRunCpt::class, 'register_meta' ), 13 );
		}

		if ( class_exists( __NAMESPACE__ . '\Workflows\WorkflowTriggerCpt' ) ) {
			add_action( 'init', array( \NvoosContentGraphAiPlatform\Workflows\WorkflowTriggerCpt::class, 'register_cpt' ), 14 );
			add_action( 'init', array( \NvoosContentGraphAiPlatform\Workflows\WorkflowTriggerCpt::class, 'register_meta' ), 14 );
			add_action( 'init', array( \NvoosContentGraphAiPlatform\Workflows\WorkflowTriggerCpt::class, 'register_all_triggers' ), 20 );
		}
	}

	/**
	 * Register the tenant isolation subsystem (Wave E4, sub-cluster 1).
	 *
	 * Standalone-only: the base plugin's tenant init.php owns the same
	 * admin_init/wp_mcp_ai_activate table hooks, rest_api_init meta
	 * registration, pre_get_posts filters, save_post stamping, and the
	 * wp_mcp_ai_after_plugin_upgrade migration hook in monolith installs;
	 * double registration would double-create the shared tenant tables and
	 * double-stamp tenant meta.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	private function registerTenant(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		if ( class_exists( __NAMESPACE__ . '\Tenant\TenantBootstrap' ) ) {
			\NvoosContentGraphAiPlatform\Tenant\TenantBootstrap::register();
		}
	}

	/**
	 * Register the OAuth integrations (Wave E4, sub-cluster 2).
	 *
	 * Standalone-only: the base plugin owns the same `admin_post_*`
	 * wiring (admin-settings component for the Gmail/Drive/Calendar
	 * flows; github/meta/quickbooks integration init files for the
	 * provider handlers) in monolith installs; double registration
	 * would double-handle every OAuth action.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	private function registerIntegrations(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		if ( class_exists( __NAMESPACE__ . '\Integrations\OAuthManager' ) ) {
			$oauth = new \NvoosContentGraphAiPlatform\Integrations\OAuthManager();
			add_action( 'admin_post_wp_mcp_ai_gmail_oauth_start', array( $oauth, 'handle_gmail_oauth_start' ) );
			add_action( 'admin_post_wp_mcp_ai_gmail_disconnect', array( $oauth, 'handle_gmail_disconnect' ) );
			add_action( 'admin_post_wp_mcp_ai_google_drive_oauth_start', array( $oauth, 'handle_google_drive_oauth_start' ) );
			add_action( 'admin_post_wp_mcp_ai_google_drive_disconnect', array( $oauth, 'handle_google_drive_disconnect' ) );
			add_action( 'admin_post_wp_mcp_ai_google_calendar_oauth_start', array( $oauth, 'handle_google_calendar_oauth_start' ) );
			add_action( 'admin_post_wp_mcp_ai_google_calendar_disconnect', array( $oauth, 'handle_google_calendar_disconnect' ) );
		}

		if ( class_exists( __NAMESPACE__ . '\Integrations\GithubOAuthHandler' ) ) {
			$github = new \NvoosContentGraphAiPlatform\Integrations\GithubOAuthHandler();
			add_action( 'admin_post_wp_mcp_ai_github_oauth_start', array( $github, 'handle_github_oauth_start' ) );
			add_action( 'admin_post_wp_mcp_ai_github_oauth_callback', array( $github, 'handle_github_oauth_callback' ) );
			add_filter( 'allowed_redirect_hosts', array( $github, 'allow_github_oauth_redirect_host' ), 10, 2 );
		}

		if ( class_exists( __NAMESPACE__ . '\Integrations\MetaOAuthHandler' ) ) {
			$meta = new \NvoosContentGraphAiPlatform\Integrations\MetaOAuthHandler();
			add_action( 'admin_post_wp_mcp_ai_meta_oauth_start', array( $meta, 'handle_meta_oauth_start' ) );
			add_action( 'admin_post_wp_mcp_ai_meta_oauth_callback', array( $meta, 'handle_meta_oauth_callback' ) );
			add_action( 'admin_post_wp_mcp_ai_meta_disconnect', array( $meta, 'handle_meta_disconnect' ) );
			add_filter( 'allowed_redirect_hosts', array( $meta, 'allow_meta_oauth_redirect_host' ), 10, 2 );
		}

		if ( class_exists( __NAMESPACE__ . '\Integrations\QuickbooksOAuthHandler' ) ) {
			$quickbooks = new \NvoosContentGraphAiPlatform\Integrations\QuickbooksOAuthHandler();
			add_action( 'admin_post_wp_mcp_ai_quickbooks_oauth_start', array( $quickbooks, 'handle_quickbooks_oauth_start' ) );
			add_action( 'admin_post_wp_mcp_ai_quickbooks_oauth_callback', array( $quickbooks, 'handle_quickbooks_oauth_callback' ) );
			add_action( 'admin_post_wp_mcp_ai_quickbooks_disconnect', array( $quickbooks, 'handle_quickbooks_disconnect' ) );
			add_action(
				'admin_notices',
				static function (): void {
					$notice = get_transient( 'wp_mcp_ai_quickbooks_oauth_notice' );

					if ( ! $notice || ! is_array( $notice ) ) {
						return;
					}

					delete_transient( 'wp_mcp_ai_quickbooks_oauth_notice' );

					$type    = isset( $notice['type'] ) ? sanitize_key( $notice['type'] ) : 'info';
					$message = isset( $notice['message'] ) ? $notice['message'] : '';

					if ( empty( $message ) ) {
						return;
					}

					$class = 'notice notice-' . $type . ' is-dismissible';
					printf(
						'<div class="%s"><p>%s</p></div>',
						esc_attr( $class ),
						wp_kses_post( $message )
					);
				}
			);
		}

		// Mailjet: byte-identical to the base — its integration init wires
		// only the webhook handler; the OAuth handler is a static token
		// utility with no hooks of its own.
	}

	/**
	 * Register the Google Calendar subsystem (Wave E4, sub-cluster 3).
	 *
	 * Standalone-only: the base loader's `google-calendar-init.php` owns
	 * the same `cron_schedules` filter, sync/renew cron actions, push
	 * receiver, and connection-gated scheduling in monolith installs;
	 * double registration would double-schedule the safety-net sync and
	 * double-register the webhook REST route.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	private function registerGoogleCalendar(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		if ( class_exists( __NAMESPACE__ . '\Google\GoogleCalendarBootstrap' ) ) {
			add_action( 'init', array( \NvoosContentGraphAiPlatform\Google\GoogleCalendarBootstrap::class, 'register' ) );
		}
	}

	/**
	 * Register the AI Content Assistant metabox (Wave E4, sub-cluster 4).
	 *
	 * Standalone-only: the base loader's `content-assistant-init.php` owns
	 * the same `admin_init` wiring in monolith installs; double
	 * registration would double-add the metabox to every post edit
	 * screen.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	private function registerContentAssistant(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		if ( class_exists( __NAMESPACE__ . '\ContentAssistant\ContentAssistantBootstrap' ) ) {
			add_action( 'admin_init', array( \NvoosContentGraphAiPlatform\ContentAssistant\ContentAssistantBootstrap::class, 'register' ) );
		}
	}

	private function __clone() {}
}
