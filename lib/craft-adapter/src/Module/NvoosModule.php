<?php
/**
 * Craft CMS module for oOS Core.
 *
 * Registers all 9 domain interface → adapter bindings as Yii
 * application components. Bootstrap this module in config/app.php
 * to make the ChatOrchestrator available to the Craft application.
 *
 * @package Nvoos\Craft
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Craft\Module;

use Craft;
use yii\base\Module as BaseModule;
use Nvoos\Core\Application\Chat\ChatOrchestrator;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Craft\Adapter\ErrorFactory;
use Nvoos\Craft\Adapter\CacheStore;
use Nvoos\Craft\Adapter\SettingsStore;
use Nvoos\Craft\Adapter\EventDispatcher;
use Nvoos\Craft\Adapter\FileStore;
use Nvoos\Craft\Adapter\QueueClient;
use Nvoos\Craft\Adapter\AuthProvider;
use Nvoos\Craft\Adapter\ContentStore;

class NvoosModule extends BaseModule {

	/**
	 * Initialise the module — register adapter components.
	 */
	public function init(): void {
		parent::init();

		// Defer service registration until Craft is fully initialised.
		Craft::$app->onInit( function () {
			$this->registerComponents();
		} );
	}

	/**
	 * Register all oOS adapters as Yii application components.
	 *
	 * Using setComponents() means these are lazy-instantiated
	 * on first access and share the lifecycle of the application.
	 */
	private function registerComponents(): void {
		$config = Craft::$app->config->getConfigFromFile( 'oos' );
		$isCraft5 = version_compare( Craft::$app->getVersion(), '5.0', '>=' );

		Craft::$app->setComponents( array(
			// Adapter bindings — use a unique key to avoid collisions.
			'oosErrorFactory'   => ErrorFactory::class,
			'oosCacheStore'     => array(
				'class' => CacheStore::class,
				'defaultTtl' => is_array( $config ) ? ( $config['cache_duration'] ?? 3600 ) : 3600,
			),
			'oosSettingsStore'  => SettingsStore::class,
			'oosEventDispatcher' => EventDispatcher::class,
			'oosFileStore'      => array(
				'class' => FileStore::class,
				'volumeHandle' => is_array( $config ) ? ( $config['storage_volume'] ?? '' ) : '',
			),
			'oosQueueClient'    => array(
				'class' => QueueClient::class,
				'defaultTtr' => is_array( $config ) ? ( $config['queue_ttr'] ?? 300 ) : 300,
			),
			'oosAuthProvider'   => AuthProvider::class,
			'oosContentStore'   => array(
				'class' => ContentStore::class,
				'sectionHandle' => is_array( $config ) ? ( $config['content_section'] ?? 'posts' ) : 'posts',
			),
		) );
	}

	/**
	 * Get the fully-wired ChatOrchestrator.
	 *
	 * Lazily constructs the orchestrator with all adapters injected.
	 * Subsequent calls return the same instance (singleton pattern).
	 *
	 * @return ChatOrchestrator
	 */
	public static function getOrchestrator(): ChatOrchestrator {
		static $instance = null;

		if ( null !== $instance ) {
			return $instance;
		}

		$instance = new ChatOrchestrator(
			tools: new ToolRegistry(
				new ErrorFactory(),
			),
			providers: new ProviderRouter(
				new SettingsStore(),
			),
			events: new EventDispatcher(),
			errors: new ErrorFactory(),
		);

		return $instance;
	}
}
