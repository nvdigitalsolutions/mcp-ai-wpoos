# Queue Worker CLI Fatals & Empty-Queue Null Guard — Fix Details

## Problem Description

Two standalone-CLI defects in `bin/queue-worker.php`:

1. **Fatal errors in standalone CLI mode (PR #5882)** — the worker parsed
   CLI options with `absint()` and `apply_filters()` *before* bootstrapping
   WordPress, so running `php bin/queue-worker.php` directly called undefined
   functions and died. Additional defects in the same file: `self::` calls in
   procedural scope (`self::parse_memory_limit()`, `self::format_bytes()`)
   and `--batch-size` never reaching the RabbitMQ batch path.
2. **Fatal `getBody()` on `null` (PR #5883)** — some php-amqp builds return
   `null` instead of `false` from `AMQPQueue::get()` when the queue is empty,
   and the worker called `getBody()` on it.

## Root Cause

- The CLI-argument parsing block sits above `require_once wp-load.php` — the
  one point of the script where WordPress helpers cannot exist.
- The empty-queue check compared strictly against `false`.

## Solution Implemented

File: `bin/queue-worker.php`

1. **Parse before bootstrap** — CLI values are cast directly (`(int)`,
   `(string)`) in the pre-bootstrap block; the
   `wp_mcp_ai_queue_worker_batch_size` filter (default 3) is applied only
   after `wp-load.php`, with `0` meaning "flag omitted".
2. **Procedural calls** — `self::parse_memory_limit()` /
   `self::format_bytes()` replaced with direct procedural calls.
3. **Batch-size passthrough** — `--batch-size` is forwarded to
   `process_rabbitmq_queue( $should_exit, $batch_size )`.
4. **Null-safe dequeue** — the RabbitMQ path now treats anything that isn't
   an `AMQPEnvelope` (`! $envelope instanceof AMQPEnvelope`) as "no messages".
5. phpcs annotations added for the CLI-only console writes and the
   `--memory-limit` ini-set.

## Test Coverage

CLI scripts are not covered by PHPUnit; verified manually against a
standalone WordPress install (one-shot and daemon modes) and an empty
RabbitMQ queue.

## Related

- [PR #5882](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5882)
- [PR #5883](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5883)
- [`bin/README.md`](../../../bin/README.md) — Queue Worker section
- [`docs/operations/queue-worker-systemd.md`](../../operations/queue-worker-systemd.md)
