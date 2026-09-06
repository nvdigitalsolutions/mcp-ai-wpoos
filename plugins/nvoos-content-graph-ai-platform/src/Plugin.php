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

	private function __clone() {}
}
