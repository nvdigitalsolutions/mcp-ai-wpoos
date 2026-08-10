/**
 * Design Stack Media Worker — Tests
 *
 * Run with: node --test src/queue.test.js
 *
 * Tests the job queue module (in-memory mode when Redis is unavailable).
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { JobQueue, getQueue } from './queue.js';

describe('JobQueue', () => {
  it('should create a queue with a name', () => {
    const queue = new JobQueue('test-queue');
    assert.equal(queue.name, 'test-queue');
  });

  it('should get singleton queue instances', () => {
    const q1 = getQueue('unique-test');
    const q2 = getQueue('unique-test');
    assert.strictEqual(q1, q2);
  });

  it('should add jobs and return job object', async () => {
    const queue = new JobQueue('add-test');
    const job = await queue.add('test-type', { foo: 'bar' });

    assert.ok(job.id);
    assert.equal(job.type, 'test-type');
    assert.deepEqual(job.data, { foo: 'bar' });
    assert.ok(job.createdAt);
    assert.equal(job.attempts, 0);
  });

  it('should process jobs with registered handlers', async () => {
    const queue = new JobQueue('process-test');
    const results = [];

    queue.process('log', async (job) => {
      results.push(job.data.value);
    });

    // Small delay to let processor start
    await new Promise((r) => setTimeout(r, 100));

    await queue.add('log', { value: 'first' });
    await queue.add('log', { value: 'second' });

    // Allow time for in-memory processing
    await new Promise((r) => setTimeout(r, 500));

    assert.deepEqual(results.sort(), ['first', 'second']);

    queue.stop();
  });

  it('should retry failed jobs', { timeout: 15000 }, async () => {
    const queue = new JobQueue('retry-test');
    let attempts = 0;

    queue.process('flaky', async (_job) => {
      attempts++;
      if (attempts < 3) throw new Error('Flaky failure');
      return 'success';
    });

    await new Promise((r) => setTimeout(r, 100));

    await queue.add('flaky', { id: 1 });

    // Wait for retries (exponential backoff: 2s, 4s, etc.)
    await new Promise((r) => setTimeout(r, 8000));

    assert.ok(attempts >= 2);

    queue.stop();
  });

  it('should report queue stats', async () => {
    const queue = new JobQueue('stats-test');
    await queue.add('type-a', { x: 1 });
    await queue.add('type-b', { x: 2 });

    const stats = await queue.getStats();
    assert.ok(stats.waiting >= 0);
    assert.ok(typeof stats.inMemory === 'boolean');
  });

  it('should emit events', async () => {
    const queue = new JobQueue('events-test');
    const events = [];

    queue.on('added', (job) => events.push({ event: 'added', type: job.type }));
    queue.on('completed', (job) => events.push({ event: 'completed', type: job.type }));

    queue.process('event-type', async () => 'done');

    await new Promise((r) => setTimeout(r, 100));
    await queue.add('event-type', { test: true });

    await new Promise((r) => setTimeout(r, 500));

    const addedEvents = events.filter((e) => e.event === 'added');
    assert.ok(addedEvents.length >= 1);

    queue.stop();
  });
});
