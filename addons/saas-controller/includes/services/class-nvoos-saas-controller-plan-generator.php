<?php
/**
 * Reconcile-plan generator for the NV oOS SaaS Controller.
 *
 * Diffs the operator's *desired* Cloudflare topology (stored in
 * {@see NVOOS_SaaS_Controller_Deployment_Config}) against the *live*
 * topology returned by the Cloudflare API and produces a structured plan:
 *
 *   [
 *     'creates' => [ ['kind'=>…,'detail'=>…], … ], // present in desired, missing live
 *     'updates' => [ … ],                           // present in both but differing
 *     'noops'   => [ … ],                           // already in sync
 *     'orphans' => [ … ],                           // present live, not in desired (informational)
 *     'errors'  => [ ['kind'=>…,'message'=>…], … ], // partial failures (Cloudflare 4xx/5xx)
 *   ]
 *
 * The generator is **read-only**. It never mutates Cloudflare state — that
 * is the Phase 5 Apply step, which is separately gated on HITL approval.
 *
 * Matching rules:
 *   • D1 databases are matched by `name`.
 *   • KV namespaces are matched by `title`.
 *   • Workers are matched by `id` (i.e. the Worker name).
 *   • AI Gateways are matched by `slug`.
 *
 * Bindings (`binding` field on D1/KV) are *not* sourced from the live
 * Cloudflare API in this phase — Cloudflare's binding metadata lives on the
 * Worker script settings endpoint, which is fetched lazily only when a
 * Worker with the desired name already exists. This keeps the plan run
 * cheap (4 list calls) for the common "first deployment" flow.
 *
 * @package NV_oOS_SaaS_Controller
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plan generator.
 *
 * @since 0.1.0
 */
class NVOOS_SaaS_Controller_Plan_Generator {

	/**
	 * Cloudflare client.
	 *
	 * @var NVOOS_SaaS_Controller_Cloudflare_Client
	 */
	protected $client;

	/**
	 * Optional Stripe client (Phase 6). When null, the Stripe section of
	 * the plan is silently skipped — the operator hasn't opted in.
	 *
	 * @var NVOOS_SaaS_Controller_Stripe_Client|null
	 */
	protected $stripe;

	/**
	 * Optional OpenRouter client (Phase 6). When null, the OpenRouter
	 * section of the plan is silently skipped.
	 *
	 * @var NVOOS_SaaS_Controller_OpenRouter_Client|null
	 */
	protected $openrouter;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param NVOOS_SaaS_Controller_Cloudflare_Client      $client     Cloudflare client.
	 * @param NVOOS_SaaS_Controller_Stripe_Client|null     $stripe     Optional Stripe client.
	 * @param NVOOS_SaaS_Controller_OpenRouter_Client|null $openrouter Optional OpenRouter client.
	 */
	public function __construct(
		NVOOS_SaaS_Controller_Cloudflare_Client $client,
		$stripe = null,
		$openrouter = null
	) {
		$this->client     = $client;
		$this->stripe     = ( $stripe instanceof NVOOS_SaaS_Controller_Stripe_Client ) ? $stripe : null;
		$this->openrouter = ( $openrouter instanceof NVOOS_SaaS_Controller_OpenRouter_Client ) ? $openrouter : null;
	}

	/**
	 * Build a plan from the supplied desired config.
	 *
	 * @since 0.1.0
	 *
	 * @param array $desired Desired config (already sanitised).
	 * @return array Plan shape (creates/updates/noops/orphans/errors).
	 */
	public function generate( array $desired ) {
		$plan = array(
			'creates' => array(),
			'updates' => array(),
			'noops'   => array(),
			'orphans' => array(),
			'errors'  => array(),
		);

		$plan = $this->plan_d1( $desired, $plan );
		$plan = $this->plan_kv( $desired, $plan );
		$plan = $this->plan_workers( $desired, $plan );
		$plan = $this->plan_ai_gateway( $desired, $plan );
		$plan = $this->plan_stripe_products( $desired, $plan );
		$plan = $this->plan_stripe_prices( $desired, $plan );
		$plan = $this->plan_openrouter_keys( $desired, $plan );

		$plan['summary'] = array(
			'creates' => count( $plan['creates'] ),
			'updates' => count( $plan['updates'] ),
			'noops'   => count( $plan['noops'] ),
			'orphans' => count( $plan['orphans'] ),
			'errors'  => count( $plan['errors'] ),
		);

		return $plan;
	}

	/**
	 * D1 plan section — match desired databases against live by name.
	 *
	 * @param array $desired Desired config.
	 * @param array $plan    Plan accumulator.
	 * @return array
	 */
	protected function plan_d1( array $desired, array $plan ) {
		$desired_dbs = isset( $desired['d1_databases'] ) ? (array) $desired['d1_databases'] : array();
		$live        = $this->client->list_d1_databases();
		if ( is_wp_error( $live ) ) {
			$plan['errors'][] = array(
				'kind'    => 'd1',
				'message' => $live->get_error_message(),
			);
			return $plan;
		}

		$by_name = array();
		foreach ( $live as $row ) {
			$by_name[ $row['name'] ] = $row;
		}

		$desired_names = array();
		foreach ( $desired_dbs as $row ) {
			$name = isset( $row['name'] ) ? (string) $row['name'] : '';
			if ( '' === $name ) {
				continue;
			}
			$desired_names[ $name ] = true;
			if ( isset( $by_name[ $name ] ) ) {
				$plan['noops'][] = array(
					'kind'    => 'd1',
					'name'    => $name,
					'binding' => $row['binding'],
					'uuid'    => $by_name[ $name ]['uuid'],
				);
			} else {
				$plan['creates'][] = array(
					'kind'    => 'd1',
					'name'    => $name,
					'binding' => $row['binding'],
				);
			}
		}

		foreach ( $by_name as $name => $row ) {
			if ( ! isset( $desired_names[ $name ] ) ) {
				$plan['orphans'][] = array(
					'kind' => 'd1',
					'name' => $name,
					'uuid' => $row['uuid'],
				);
			}
		}

		return $plan;
	}

	/**
	 * KV plan section — match desired namespaces against live by title.
	 *
	 * @param array $desired Desired config.
	 * @param array $plan    Plan accumulator.
	 * @return array
	 */
	protected function plan_kv( array $desired, array $plan ) {
		$desired_ns = isset( $desired['kv_namespaces'] ) ? (array) $desired['kv_namespaces'] : array();
		$live       = $this->client->list_kv_namespaces();
		if ( is_wp_error( $live ) ) {
			$plan['errors'][] = array(
				'kind'    => 'kv',
				'message' => $live->get_error_message(),
			);
			return $plan;
		}

		$by_title = array();
		foreach ( $live as $row ) {
			$by_title[ $row['title'] ] = $row;
		}

		$desired_titles = array();
		foreach ( $desired_ns as $row ) {
			$title = isset( $row['title'] ) ? (string) $row['title'] : '';
			if ( '' === $title ) {
				continue;
			}
			$desired_titles[ $title ] = true;
			if ( isset( $by_title[ $title ] ) ) {
				$plan['noops'][] = array(
					'kind'    => 'kv',
					'title'   => $title,
					'binding' => $row['binding'],
					'id'      => $by_title[ $title ]['id'],
				);
			} else {
				$plan['creates'][] = array(
					'kind'    => 'kv',
					'title'   => $title,
					'binding' => $row['binding'],
				);
			}
		}

		foreach ( $by_title as $title => $row ) {
			if ( ! isset( $desired_titles[ $title ] ) ) {
				$plan['orphans'][] = array(
					'kind'  => 'kv',
					'title' => $title,
					'id'    => $row['id'],
				);
			}
		}

		return $plan;
	}

	/**
	 * Worker plan section — match desired Worker name against live by id.
	 *
	 * Only the Worker named in the desired config is examined. Other live
	 * Workers are *not* listed as orphans, because the operator may run
	 * unrelated Workers in the same account.
	 *
	 * @param array $desired Desired config.
	 * @param array $plan    Plan accumulator.
	 * @return array
	 */
	protected function plan_workers( array $desired, array $plan ) {
		$desired_name = isset( $desired['worker_name'] ) ? (string) $desired['worker_name'] : '';
		if ( '' === $desired_name ) {
			return $plan;
		}

		$live = $this->client->list_workers();
		if ( is_wp_error( $live ) ) {
			$plan['errors'][] = array(
				'kind'    => 'worker',
				'message' => $live->get_error_message(),
			);
			return $plan;
		}

		$found = false;
		foreach ( $live as $row ) {
			if ( $row['id'] === $desired_name ) {
				$found             = true;
				$plan['updates'][] = array(
					'kind'        => 'worker',
					'name'        => $desired_name,
					'reason'      => __( 'Worker exists; would re-upload script + bindings on Apply.', 'nvoos-saas-controller' ),
					'modified_on' => $row['modified_on'],
				);
				break;
			}
		}
		if ( ! $found ) {
			$plan['creates'][] = array(
				'kind' => 'worker',
				'name' => $desired_name,
			);
		}

		return $plan;
	}

	/**
	 * AI Gateway plan section — match desired slug against live by slug.
	 *
	 * @param array $desired Desired config.
	 * @param array $plan    Plan accumulator.
	 * @return array
	 */
	protected function plan_ai_gateway( array $desired, array $plan ) {
		$slug = isset( $desired['ai_gateway_slug'] ) ? (string) $desired['ai_gateway_slug'] : '';
		if ( '' === $slug ) {
			return $plan;
		}

		$live = $this->client->list_ai_gateways();
		if ( is_wp_error( $live ) ) {
			$plan['errors'][] = array(
				'kind'    => 'ai_gateway',
				'message' => $live->get_error_message(),
			);
			return $plan;
		}

		foreach ( $live as $row ) {
			if ( ( $row['slug'] ?? '' ) === $slug ) {
				$plan['noops'][] = array(
					'kind' => 'ai_gateway',
					'slug' => $slug,
					'id'   => $row['id'],
				);
				return $plan;
			}
		}

		$plan['creates'][] = array(
			'kind' => 'ai_gateway',
			'slug' => $slug,
		);
		return $plan;
	}

	/**
	 * Stripe products plan section — match desired by id (Phase 6).
	 *
	 * Skipped silently when no Stripe client is configured (operator has
	 * not opted in to the Stripe surface). Live API failures are recorded
	 * in `errors[]` rather than thrown, mirroring the Cloudflare sections.
	 *
	 * @param array $desired Desired config.
	 * @param array $plan    Plan accumulator.
	 * @return array
	 */
	protected function plan_stripe_products( array $desired, array $plan ) {
		$desired_products = isset( $desired['stripe_products'] ) ? (array) $desired['stripe_products'] : array();
		if ( empty( $desired_products ) ) {
			return $plan;
		}
		if ( null === $this->stripe ) {
			$plan['errors'][] = array(
				'kind'    => 'stripe_product',
				'message' => __( 'Stripe products are configured but no Stripe secret key is set; configure the Stripe credential to plan/apply this section.', 'nvoos-saas-controller' ),
			);
			return $plan;
		}

		$ids = array();
		foreach ( $desired_products as $row ) {
			if ( is_array( $row ) && ! empty( $row['id'] ) ) {
				$ids[] = (string) $row['id'];
			}
		}
		$live = $this->stripe->list_products( $ids );
		if ( is_wp_error( $live ) ) {
			$plan['errors'][] = array(
				'kind'    => 'stripe_product',
				'message' => $live->get_error_message(),
			);
			return $plan;
		}

		foreach ( $desired_products as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}
			$id   = (string) $row['id'];
			$name = isset( $row['name'] ) ? (string) $row['name'] : '';
			if ( isset( $live[ $id ] ) ) {
				$plan['noops'][] = array(
					'kind'      => 'stripe_product',
					'id'        => $id,
					'name'      => $name,
					'live_name' => isset( $live[ $id ]['name'] ) ? (string) $live[ $id ]['name'] : '',
				);
			} else {
				$entry = array(
					'kind' => 'stripe_product',
					'id'   => $id,
					'name' => $name,
				);
				if ( ! empty( $row['description'] ) ) {
					$entry['description'] = (string) $row['description'];
				}
				$plan['creates'][] = $entry;
			}
		}

		return $plan;
	}

	/**
	 * Stripe prices plan section — match desired by lookup_key (Phase 6).
	 *
	 * @param array $desired Desired config.
	 * @param array $plan    Plan accumulator.
	 * @return array
	 */
	protected function plan_stripe_prices( array $desired, array $plan ) {
		$desired_prices = isset( $desired['stripe_prices'] ) ? (array) $desired['stripe_prices'] : array();
		if ( empty( $desired_prices ) ) {
			return $plan;
		}
		if ( null === $this->stripe ) {
			$plan['errors'][] = array(
				'kind'    => 'stripe_price',
				'message' => __( 'Stripe prices are configured but no Stripe secret key is set; configure the Stripe credential to plan/apply this section.', 'nvoos-saas-controller' ),
			);
			return $plan;
		}

		$lookup_keys = array();
		foreach ( $desired_prices as $row ) {
			if ( is_array( $row ) && ! empty( $row['lookup_key'] ) ) {
				$lookup_keys[] = (string) $row['lookup_key'];
			}
		}
		$live = $this->stripe->list_prices_by_lookup_keys( $lookup_keys );
		if ( is_wp_error( $live ) ) {
			$plan['errors'][] = array(
				'kind'    => 'stripe_price',
				'message' => $live->get_error_message(),
			);
			return $plan;
		}

		foreach ( $desired_prices as $row ) {
			if ( ! is_array( $row ) || empty( $row['lookup_key'] ) ) {
				continue;
			}
			$lookup_key = (string) $row['lookup_key'];
			if ( isset( $live[ $lookup_key ] ) ) {
				$plan['noops'][] = array(
					'kind'       => 'stripe_price',
					'lookup_key' => $lookup_key,
					'product_id' => isset( $row['product_id'] ) ? (string) $row['product_id'] : '',
					'live_id'    => isset( $live[ $lookup_key ]['id'] ) ? (string) $live[ $lookup_key ]['id'] : '',
				);
			} else {
				$entry = array(
					'kind'        => 'stripe_price',
					'lookup_key'  => $lookup_key,
					'product_id'  => isset( $row['product_id'] ) ? (string) $row['product_id'] : '',
					'currency'    => isset( $row['currency'] ) ? (string) $row['currency'] : '',
					'unit_amount' => isset( $row['unit_amount'] ) ? (int) $row['unit_amount'] : 0,
				);
				if ( ! empty( $row['recurring_interval'] ) ) {
					$entry['recurring_interval'] = (string) $row['recurring_interval'];
				}
				if ( ! empty( $row['nickname'] ) ) {
					$entry['nickname'] = (string) $row['nickname'];
				}
				$plan['creates'][] = $entry;
			}
		}

		return $plan;
	}

	/**
	 * OpenRouter keys plan section — match desired by label (Phase 6).
	 *
	 * @param array $desired Desired config.
	 * @param array $plan    Plan accumulator.
	 * @return array
	 */
	protected function plan_openrouter_keys( array $desired, array $plan ) {
		$desired_keys = isset( $desired['openrouter_keys'] ) ? (array) $desired['openrouter_keys'] : array();
		if ( empty( $desired_keys ) ) {
			return $plan;
		}
		if ( null === $this->openrouter ) {
			$plan['errors'][] = array(
				'kind'    => 'openrouter_key',
				'message' => __( 'OpenRouter keys are configured but no provisioning key is set; configure the OpenRouter provisioning credential to plan/apply this section.', 'nvoos-saas-controller' ),
			);
			return $plan;
		}

		$live = $this->openrouter->list_keys();
		if ( is_wp_error( $live ) ) {
			$plan['errors'][] = array(
				'kind'    => 'openrouter_key',
				'message' => $live->get_error_message(),
			);
			return $plan;
		}

		foreach ( $desired_keys as $row ) {
			if ( ! is_array( $row ) || empty( $row['label'] ) ) {
				continue;
			}
			$label = (string) $row['label'];
			if ( isset( $live[ $label ] ) ) {
				$plan['noops'][] = array(
					'kind'  => 'openrouter_key',
					'label' => $label,
					'hash'  => isset( $live[ $label ]['hash'] ) ? (string) $live[ $label ]['hash'] : '',
				);
			} else {
				$entry = array(
					'kind'  => 'openrouter_key',
					'label' => $label,
				);
				if ( isset( $row['limit_usd'] ) ) {
					$entry['limit_usd'] = (float) $row['limit_usd'];
				}
				$plan['creates'][] = $entry;
			}
		}

		return $plan;
	}
}
