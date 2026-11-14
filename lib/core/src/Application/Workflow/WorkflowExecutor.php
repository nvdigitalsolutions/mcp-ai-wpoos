<?php
/**
 * Deterministic workflow executor.
 *
 * WordPress's Engine V2 does not walk the graph: it renders the nodes into a
 * description and hands the whole thing to an LLM-planned `execute_workflow`
 * tool. The Laravel runtime executes the saved graph instead — Kahn's
 * topological order, one node at a time, with each node's output available to
 * the nodes downstream. That is a deliberate divergence (090-h H3): a
 * deterministic run is testable, its cost is bounded, and a failure names the
 * node that failed instead of surfacing as a planner message.
 *
 * Node types the catalogue uses: `tool`, `input`, `output`, `condition`.
 * `delay` and `agent` are supported for authored graphs; `agent` needs a
 * runner callable because the chat orchestrator lives in the adapter layer.
 * Any other type is a pass-through no-op, so a graph authored for WordPress
 * still runs its tool nodes here.
 *
 * Safety: tool calls go through {@see ToolRegistry::execute()}, so each tool's
 * `required_capability` and the pre-execution listener (destructive-ops gate)
 * apply exactly as they do for `POST /tools`. The caller's identity is carried
 * in the context and never elevated.
 *
 * @package NV_oOS
 * @since   2.5.0
 * @license MIT
 * SPDX-License-Identifier: MIT
 */

declare( strict_types=1 );

namespace Nvoos\Core\Application\Workflow;

use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;

/**
 * Executes a saved ReactFlow graph.
 */
final class WorkflowExecutor {

	/** Hard ceiling on nodes visited in one run. */
	public const DEFAULT_MAX_NODES = 200;

	/** Hard ceiling on tool invocations in one run. */
	public const DEFAULT_MAX_TOOL_CALLS = 50;

	/** Longest delay a `delay` node may request. */
	public const DEFAULT_DELAY_CAP = 10;

	/**
	 * @param ToolRegistry          $tools  Tool registry.
	 * @param ErrorFactoryInterface $errors Error factory.
	 */
	public function __construct(
		private readonly ToolRegistry $tools,
		private readonly ErrorFactoryInterface $errors,
	) {}

	/**
	 * Execute a graph.
	 *
	 * Options:
	 *   user_id         int                 Caller identity for capability checks.
	 *   auth_provider   AuthProviderInterface|null
	 *   max_nodes       int
	 *   max_tool_calls  int
	 *   delay_cap       int                 Seconds.
	 *   agent_runner    callable|null       fn(string $agentId, string $prompt, array $context): mixed
	 *
	 * @param array<string, mixed> $graph   Graph (`nodes`, `edges`).
	 * @param array<string, mixed> $input   Run input.
	 * @param array<string, mixed> $options Execution options.
	 * @return array{
	 *   success: bool,
	 *   status: string,
	 *   nodes: array<string, array<string, mixed>>,
	 *   tool_calls: int,
	 *   error: string|null,
	 *   message: string
	 * }
	 */
	public function execute( array $graph, array $input = array(), array $options = array() ): array {
		$nodes = isset( $graph['nodes'] ) && is_array( $graph['nodes'] ) ? array_values( $graph['nodes'] ) : array();
		$edges = isset( $graph['edges'] ) && is_array( $graph['edges'] ) ? array_values( $graph['edges'] ) : array();

		$max_nodes      = (int) ( $options['max_nodes'] ?? self::DEFAULT_MAX_NODES );
		$max_tool_calls = (int) ( $options['max_tool_calls'] ?? self::DEFAULT_MAX_TOOL_CALLS );
		$delay_cap      = (int) ( $options['delay_cap'] ?? self::DEFAULT_DELAY_CAP );

		$by_id = array();

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$id = isset( $node['id'] ) && is_scalar( $node['id'] ) ? (string) $node['id'] : '';

			if ( '' === $id ) {
				return $this->failed( 'A graph node has no id.' );
			}

			if ( isset( $by_id[ $id ] ) ) {
				return $this->failed( "Duplicate node id \"{$id}\"." );
			}

			$by_id[ $id ] = $node;
		}

		[ $indegree, $outgoing ] = $this->adjacency( $by_id, $edges );

		if ( null === $indegree ) {
			return $this->failed( 'A graph edge references a node that does not exist.' );
		}

		$queue = array();

		foreach ( $indegree as $id => $degree ) {
			if ( 0 === $degree ) {
				$queue[] = $id;
			}
		}

		$context = array(
			'input' => $input,
			'nodes' => array(),
		);

		$results    = array();
		$tool_calls = 0;
		$visited    = 0;
		$status     = 'completed';
		$error      = null;

		while ( array() !== $queue ) {
			if ( ++$visited > $max_nodes ) {
				$status = 'failed';
				$error  = "The run exceeded the {$max_nodes}-node ceiling.";
				break;
			}

			$id   = array_shift( $queue );
			$node = $by_id[ $id ];

			$record = $this->runNode(
				$id,
				$node,
				$context,
				$options,
				$tool_calls,
				$max_tool_calls,
				$delay_cap,
			);

			$results[ $id ] = $record;

			if ( 'completed' !== $record['status'] ) {
				$status = 'failed';
				$error  = (string) $record['error'];
				break;
			}

			$context['nodes'][ $id ] = $record['output'];

			foreach ( $outgoing[ $id ] as $target ) {
				--$indegree[ $target ];

				if ( 0 === $indegree[ $target ] ) {
					$queue[] = $target;
				}
			}
		}

		// A saved graph cannot cycle (save-time validation rejects them), but a
		// graph edited outside the console can. Say so instead of reporting a
		// partial success.
		if ( 'completed' === $status && $visited < count( $by_id ) ) {
			$status = 'failed';
			$error  = 'The graph contains a cycle.';
		}

		if ( 'failed' === $status ) {
			foreach ( $by_id as $id => $_node ) {
				if ( ! isset( $results[ $id ] ) ) {
					$results[ $id ] = array(
						'status' => 'skipped',
						'output' => null,
						'error' => null,
						'duration_ms' => 0.0,
					);
				}
			}
		}

		return array(
			'success' => 'completed' === $status,
			'status' => $status,
			'nodes' => $results,
			'tool_calls' => $tool_calls,
			'error' => $error,
			'message' => 'completed' === $status
				? 'Workflow completed.'
				: (string) $error,
		);
	}

	/**
	 * Run one node.
	 *
	 * @param string               $id       Node id.
	 * @param array<string, mixed> $node     Node definition.
	 * @param array<string, mixed> $context  Run context.
	 * @param array<string, mixed> $options  Execution options.
	 * @param int                  $tool_calls     Running tool-call count (by reference semantics via return).
	 * @param int                  $max_tool_calls Tool-call ceiling.
	 * @param int                  $delay_cap      Delay ceiling in seconds.
	 * @return array<string, mixed>
	 */
	private function runNode(
		string $id,
		array $node,
		array $context,
		array $options,
		int &$tool_calls,
		int $max_tool_calls,
		int $delay_cap,
	): array {
		$started = microtime( true );
		$type    = isset( $node['type'] ) && is_scalar( $node['type'] ) ? (string) $node['type'] : 'tool';
		$data    = isset( $node['data'] ) && is_array( $node['data'] ) ? $node['data'] : array();

		try {
			$output = match ( $type ) {
				'input' => $context['input'],
				'output' => null,
				'condition' => $this->condition( $id, $data, $context ),
				'delay' => $this->delay( $data, $delay_cap ),
				'agent' => $this->agent( $id, $data, $context, $options ),
				default => $this->tool( $id, $node, $data, $context, $options, $tool_calls, $max_tool_calls ),
			};
		} catch ( \Throwable $exception ) {
			return array(
				'status' => 'failed',
				'output' => null,
				'error' => $exception->getMessage(),
				'duration_ms' => $this->elapsed( $started ),
				'type' => $type,
			);
		}

		if ( $this->errors->isError( $output ) ) {
			$normalized = $this->errors->normalize( $output );

			return array(
				'status' => 'failed',
				'output' => null,
				'error' => isset( $normalized['message'] ) && is_scalar( $normalized['message'] )
					? (string) $normalized['message']
					: 'The node failed.',
				'duration_ms' => $this->elapsed( $started ),
				'type' => $type,
			);
		}

		return array(
			'status' => 'completed',
			'output' => $output,
			'error' => null,
			'duration_ms' => $this->elapsed( $started ),
			'type' => $type,
		);
	}

	/**
	 * Execute a tool node.
	 *
	 * `data.arguments` is resolved against the run context before the call.
	 *
	 * @param string               $id       Node id.
	 * @param array<string, mixed> $node     Node definition.
	 * @param array<string, mixed> $data     Node data.
	 * @param array<string, mixed> $context  Run context.
	 * @param array<string, mixed> $options  Execution options.
	 * @param int                  $tool_calls     Running count.
	 * @param int                  $max_tool_calls Ceiling.
	 * @return mixed
	 */
	private function tool(
		string $id,
		array $node,
		array $data,
		array $context,
		array $options,
		int &$tool_calls,
		int $max_tool_calls,
	): mixed {
		$slug = isset( $data['toolSlug'] ) && is_scalar( $data['toolSlug'] ) ? (string) $data['toolSlug'] : '';

		// No tool declared: a pass-through placeholder (`input`-adjacent node
		// types authored for WordPress). Nothing to execute, nothing to fail.
		if ( '' === $slug ) {
			return null;
		}

		if ( $tool_calls >= $max_tool_calls ) {
			throw new \RuntimeException( "The run exceeded the {$max_tool_calls}-tool-call ceiling." );
		}

		++$tool_calls;

		$arguments = isset( $data['arguments'] ) && is_array( $data['arguments'] )
			? TemplateResolver::resolve( $data['arguments'], $context )
			: array();

		return $this->tools->execute(
			$slug,
			$arguments,
			array(
				'user_id' => (int) ( $options['user_id'] ?? 0 ),
				'auth_provider' => $options['auth_provider'] ?? null,
				'workflow_id' => isset( $node['workflow_id'] ) ? $node['workflow_id'] : null,
				'node_id' => $id,
			),
		);
	}

	/**
	 * Evaluate a condition node.
	 *
	 * @param string               $id      Node id.
	 * @param array<string, mixed> $data    Node data.
	 * @param array<string, mixed> $context Run context.
	 * @return bool
	 */
	private function condition( string $id, array $data, array $context ): bool {
		$expression = isset( $data['expression'] ) && is_scalar( $data['expression'] )
			? (string) $data['expression']
			: '';

		$result = TemplateResolver::evaluate( $expression, $context );

		if ( null === $result ) {
			throw new \RuntimeException( "Condition node \"{$id}\" has no evaluable expression." );
		}

		return $result;
	}

	/**
	 * Sleep for the requested (capped) duration.
	 *
	 * @param array<string, mixed> $data      Node data.
	 * @param int                  $delay_cap Ceiling in seconds.
	 * @return array{slept: int}
	 */
	private function delay( array $data, int $delay_cap ): array {
		$seconds = isset( $data['seconds'] ) && is_numeric( $data['seconds'] ) ? (int) $data['seconds'] : 0;
		$seconds = max( 0, min( $seconds, max( 0, $delay_cap ) ) );

		if ( $seconds > 0 ) {
			usleep( $seconds * 1_000_000 );
		}

		return array( 'slept' => $seconds );
	}

	/**
	 * Run an agent node through the injected runner.
	 *
	 * @param string               $id      Node id.
	 * @param array<string, mixed> $data    Node data.
	 * @param array<string, mixed> $context Run context.
	 * @param array<string, mixed> $options Execution options.
	 * @return mixed
	 */
	private function agent( string $id, array $data, array $context, array $options ): mixed {
		$runner = $options['agent_runner'] ?? null;

		if ( ! is_callable( $runner ) ) {
			throw new \RuntimeException(
				"Agent node \"{$id}\" needs an agent runner, which this runtime has not bound."
			);
		}

		$prompt = isset( $data['prompt'] ) && is_scalar( $data['prompt'] ) ? (string) $data['prompt'] : '';

		if ( '' === trim( $prompt ) ) {
			throw new \RuntimeException( "Agent node \"{$id}\" has no prompt." );
		}

		$agent_id = isset( $data['agentId'] ) && is_scalar( $data['agentId'] ) ? (string) $data['agentId'] : 'default';

		return $runner( $agent_id, TemplateResolver::resolve( $prompt, $context ), $context );
	}

	/**
	 * Build indegree/outgoing maps, or null when an edge dangles.
	 *
	 * @param array<string, array<string, mixed>> $by_id Node map.
	 * @param list<mixed>                         $edges Edges.
	 * @return array{0: array<string, int>|null, 1: array<string, list<string>>}
	 */
	private function adjacency( array $by_id, array $edges ): array {
		$indegree = array_fill_keys( array_keys( $by_id ), 0 );
		$outgoing = array_fill_keys( array_keys( $by_id ), array() );

		foreach ( $edges as $edge ) {
			if ( ! is_array( $edge ) ) {
				continue;
			}

			$source = isset( $edge['source'] ) && is_scalar( $edge['source'] ) ? (string) $edge['source'] : '';
			$target = isset( $edge['target'] ) && is_scalar( $edge['target'] ) ? (string) $edge['target'] : '';

			if ( ! isset( $by_id[ $source ], $by_id[ $target ] ) ) {
				return array( null, $outgoing );
			}

			++$indegree[ $target ];
			$outgoing[ $source ][] = $target;
		}

		return array( $indegree, $outgoing );
	}

	/**
	 * Failure envelope for pre-flight problems.
	 *
	 * @param string $message Message.
	 * @return array<string, mixed>
	 */
	private function failed( string $message ): array {
		return array(
			'success' => false,
			'status' => 'failed',
			'nodes' => array(),
			'tool_calls' => 0,
			'error' => $message,
			'message' => $message,
		);
	}

	/**
	 * Milliseconds since a microtime mark.
	 *
	 * @param float $started Start mark.
	 */
	private function elapsed( float $started ): float {
		return round( ( microtime( true ) - $started ) * 1000, 2 );
	}
}
