<?php
/**
 * Laravel adapter: commercially licensed Pro entitlement.
 *
 * The Phase 0–4 `ProEntitlement` adapter is configuration-driven because the
 * WordPress licence record does not exist outside WordPress. This class is the
 * commercial source it was waiting for (Proposal 090-B, ADR-7, Phase 5.1):
 * the same licence server the WordPress plugin talks to, called with a Laravel
 * instance ID instead of a site URL.
 *
 * Resolution policy — a licensing check must never take a paid surface offline
 * because of a blip:
 *
 *   1. A fresh cache entry is authoritative; no HTTP call is made.
 *   2. On a cache miss the server is asked once, with a short timeout.
 *   3. A definitive answer (valid or not) is cached — `valid` for `ttl`,
 *      anything else for the shorter `deniedTtl` so a renewal is picked up
 *      quickly.
 *   4. A transient failure (timeout, connection error, 5xx, unparseable body)
 *      re-serves the last known answer from a store entry that lives for
 *      `graceTtl`, and retries the server after `deniedTtl`. With no previous
 *      answer the surface fails closed.
 *
 * No configuration path grants a feature that the licence does not: missing
 * key, missing server, unknown plan and expired `expires` all fail closed.
 * Explicit entries in `oos.pro_entitlement.features` are the one operator
 * override, and they win in both directions.
 *
 * @package Nvoos\Laravel
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Nvoos\Core\Domain\Contract\ProEntitlementInterface;
use Nvoos\Core\Domain\Service\Entitlement\ProPlanCatalog;

class LicensedProEntitlement implements ProEntitlementInterface {

	/**
	 * Cache key prefix. The suffix hashes the identity inputs, so rotating the
	 * key, the instance ID or the server invalidates the entry on its own.
	 */
	private const CACHE_PREFIX = 'nvoos_pro_entitlement_';

	/** Status values the licence server uses for a live licence. */
	private const VALID_STATUSES = array( 'valid', 'active' );

	/**
	 * @param string               $licenseKey    Licence key issued by the store.
	 * @param string               $serverUrl    Licence server base URL.
	 * @param string               $instanceId   Stable identifier for this installation.
	 * @param string               $product      Product slug the key was sold for.
	 * @param array<string, bool>  $featureMap   Operator overrides, applied last.
	 * @param int                  $ttl          Seconds a valid answer stays fresh.
	 * @param int                  $deniedTtl    Seconds a negative answer stays cached.
	 * @param int                  $graceTtl     Seconds a stale answer may survive failures.
	 * @param int                  $timeout      Seconds the licence request may take.
	 */
	public function __construct(
		private readonly string $licenseKey = '',
		private readonly string $serverUrl = '',
		private readonly string $instanceId = '',
		private readonly string $product = 'mcp-ai-wpoos-pro',
		private readonly array $featureMap = array(),
		private readonly int $ttl = 43200,
		private readonly int $deniedTtl = 300,
		private readonly int $graceTtl = 604800,
		private readonly int $timeout = 10,
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function allows( string $feature ): bool {
		$state = $this->state();

		if ( ! $state['entitled'] ) {
			return false;
		}

		if ( array_key_exists( $feature, $this->featureMap ) ) {
			return (bool) $this->featureMap[ $feature ];
		}

		return ProPlanCatalog::allows( $state['plan'], $feature );
	}

	/**
	 * {@inheritDoc}
	 */
	public function plan(): string {
		return $this->state()['plan'];
	}

	/**
	 * {@inheritDoc}
	 */
	public function source(): string {
		return 'license';
	}

	/**
	 * {@inheritDoc}
	 */
	public function features(): array {
		$state = $this->state();

		if ( ! $state['entitled'] ) {
			return array();
		}

		$map = array_fill_keys( ProPlanCatalog::features( $state['plan'] ), true );

		foreach ( $this->featureMap as $feature => $allowed ) {
			$map[ $feature ] = (bool) $allowed;
		}

		return $map;
	}

	/**
	 * Diagnostic view of the last resolved licence state.
	 *
	 * Exposed for the ops overview and for tests; callers that only need the
	 * contract should use `plan()`, `source()` and `allows()`.
	 *
	 * @return array{plan:string,entitled:bool,source:string,stale:bool,checked_at:int|null,error:string|null}
	 */
	public function status(): array {
		$state = $this->state();

		return array(
			'plan'       => $state['plan'],
			'entitled'   => $state['entitled'],
			'source'     => 'license',
			'stale'      => $state['stale'],
			'checked_at' => $state['checked_at'],
			'error'      => $state['error'],
		);
	}

	/**
	 * Resolve the licence state, refreshing from the server when stale.
	 *
	 * @return array{plan:string,entitled:bool,stale:bool,checked_at:int|null,error:string|null}
	 */
	private function state(): array {
		$denied = array(
			'plan'       => '',
			'entitled'   => false,
			'stale'      => false,
			'checked_at' => null,
			'error'      => null,
		);

		if ( '' === trim( $this->licenseKey ) ) {
			return array_merge( $denied, array( 'error' => 'no-license-key' ) );
		}

		if ( '' === trim( $this->serverUrl ) ) {
			return array_merge( $denied, array( 'error' => 'no-license-server' ) );
		}

		$cached = $this->readCache();

		if ( null !== $cached && $cached['fresh_until'] > $this->now() ) {
			return $this->present( $cached, false );
		}

		$refresh = $this->refresh();

		if ( ! $refresh['transient'] ) {
			// A definitive answer — valid or not — replaces any history.
			$life = $refresh['entitled'] ? $this->ttl : $this->deniedTtl;
			$this->writeCache( $refresh, $life );

			return $this->present( $refresh, false );
		}

		if ( null !== $cached ) {
			// Serve the last known answer rather than taking a paid surface
			// offline on a transient failure. Re-check after the short retry
			// window, not the full TTL — the server may come back any minute.
			$staleEntry = array_merge(
				$cached,
				array(
					'error' => $refresh['error'],
					'stale' => true,
				)
			);

			$this->writeCache( $staleEntry, $this->deniedTtl, true );

			return $this->present( $staleEntry, true );
		}

		return array_merge( $denied, array( 'error' => $refresh['error'] ) );
	}

	/**
	 * Ask the licence server about this installation.
	 *
	 * `transient` distinguishes "the server said no" from "the server was not
	 * reachable" — only the latter is allowed to fall back to a cached answer,
	 * and only the former is worth caching as a negative result.
	 *
	 * @return array{plan:string,entitled:bool,checked_at:int,error:string|null,transient:bool}
	 */
	private function refresh(): array {
		$url = rtrim( $this->serverUrl, '/' ) . '/check';

		try {
			$response = Http::timeout( $this->timeout )
				->asForm()
				->post(
					$url,
					array(
						'license_key' => $this->licenseKey,
						'site_url'    => $this->instanceId,
						'product'     => $this->product,
					)
				);
		} catch ( \Throwable $e ) {
			return $this->failure( 'license-unreachable' );
		}

		if ( $response->serverError() || 429 === $response->status() ) {
			return $this->failure( 'license-http-' . $response->status() );
		}

		if ( ! $response->successful() ) {
			// A 4xx is a definitive "no": the key was rejected.
			return $this->denial( 'license-http-' . $response->status() );
		}

		$data = $response->json();

		if ( ! is_array( $data ) ) {
			return $this->failure( 'license-invalid-response' );
		}

		$status = strtolower( (string) ( $data['status'] ?? '' ) );

		if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
			return $this->denial();
		}

		if ( isset( $data['expires'] ) && is_string( $data['expires'] ) ) {
			$expires = strtotime( $data['expires'] );

			if ( false !== $expires && $expires < $this->now() ) {
				return $this->denial();
			}
		}

		$plan = (string) ( $data['plan'] ?? 'compliance' );

		if ( ! ProPlanCatalog::hasPlan( $plan ) ) {
			return $this->denial( 'license-unknown-plan:' . $plan );
		}

		return array(
			'plan'       => $plan,
			'entitled'   => true,
			'checked_at' => $this->now(),
			'error'      => null,
			'transient'  => false,
		);
	}

	/**
	 * A definitive negative answer from the server.
	 *
	 * @return array{plan:string,entitled:bool,checked_at:int,error:string|null,transient:bool}
	 */
	private function denial( ?string $error = null ): array {
		return array(
			'plan'       => '',
			'entitled'   => false,
			'checked_at' => $this->now(),
			'error'      => $error,
			'transient'  => false,
		);
	}

	/**
	 * A transient failure — the answer must not be cached as a denial.
	 *
	 * @return array{plan:string,entitled:bool,checked_at:int,error:string,transient:bool}
	 */
	private function failure( string $error ): array {
		return array(
			'plan'       => '',
			'entitled'   => false,
			'checked_at' => $this->now(),
			'error'      => $error,
			'transient'  => true,
		);
	}

	/**
	 * Shape a stored entry for callers.
	 *
	 * @param array<string,mixed> $entry Cached or freshly resolved entry.
	 * @param bool                $stale Whether the answer predates a failed refresh.
	 *
	 * @return array{plan:string,entitled:bool,stale:bool,checked_at:int|null,error:string|null}
	 */
	private function present( array $entry, bool $stale ): array {
		return array(
			'plan'       => (string) ( $entry['plan'] ?? '' ),
			'entitled'   => (bool) ( $entry['entitled'] ?? false ),
			'stale'      => (bool) ( $entry['stale'] ?? $stale ),
			'checked_at' => isset( $entry['checked_at'] ) ? (int) $entry['checked_at'] : null,
			'error'      => $entry['error'] ?? null,
		);
	}

	/**
	 * Read the cache entry for this installation, if any.
	 *
	 * @return array{plan:string,entitled:bool,checked_at:int,fresh_until:int,error:string|null}|null
	 */
	private function readCache(): ?array {
		$entry = Cache::get( $this->cacheKey() );

		if ( ! is_array( $entry ) || ! isset( $entry['fresh_until'] ) ) {
			return null;
		}

		return $entry;
	}

	/**
	 * Store an entry and mark how long it stays fresh.
	 *
	 * The entry itself lives for `graceTtl` regardless of freshness, because a
	 * cache store drops expired keys — keeping the last known answer outside
	 * its own freshness window is what makes stale-while-error possible. The
	 * store TTL is a backstop, not the freshness signal.
	 *
	 * @param array<string,mixed> $entry    Resolved entry.
	 * @param int                 $freshFor Seconds the answer counts as fresh.
	 * @param bool                $stale    Whether this is a stale re-serve.
	 */
	private function writeCache( array $entry, int $freshFor, bool $stale = false ): void {
		unset( $entry['transient'] );

		$freshFor             = max( 1, $freshFor );
		$entry['fresh_until'] = $this->now() + $freshFor;
		$entry['stale']       = $stale;
		$ttl                  = max( $freshFor, $this->graceTtl );

		Cache::put( $this->cacheKey(), $entry, $ttl );
	}

	/**
	 * Cache key for this installation's identity.
	 */
	private function cacheKey(): string {
		$identity = implode( '|', array( $this->serverUrl, $this->instanceId, $this->product, $this->licenseKey ) );

		return self::CACHE_PREFIX . hash( 'sha256', $identity );
	}

	/**
	 * Current unix timestamp on the application clock.
	 *
	 * Deliberately not `time()`: the cache store expires entries against
	 * Carbon's clock, so mixing the two would let an entry be "fresh" to this
	 * class while the store has already dropped it (and vice versa).
	 */
	private function now(): int {
		return now()->getTimestamp();
	}
}
