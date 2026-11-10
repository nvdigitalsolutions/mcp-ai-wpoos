#!/usr/bin/env node
// check-generated.mjs — the generated-artifact drift gate (Proposal 126
// §drift) with the handoff lock (Proposal 141).
//
// Forge-owned project: the scaffold bakes nvoos.generated.json (sha256 of every
// generated file) beside the files it writes; this script re-hashes them and
// fails on any drift — a hand-edited generated file or a stale scaffold.
// Chained into `npm run check` so CI gates it on every build.
//
// Locked project (forge.lock.json present): ownership has been handed to a
// developer, so drift is EXPECTED. The gate then reports what changed since
// handoff instead of failing — the codegen convention is that generated-only
// files are gated, user-owned files are not. It still fails for a *stale*
// scaffold when the lock is missing the expected key (a re-forge happened
// without re-locking), and `--strict-lock` restores the hard gate on request.
import { createHash } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const manifestPath = path.join(root, 'nvoos.generated.json');
const lockPath = path.join(root, 'forge.lock.json');
const strictLock = process.argv.includes('--strict-lock');

const sha256 = (text) => createHash('sha256').update(text).digest('hex');

const lockRaw = existsSync(lockPath) ? await readFile(lockPath, 'utf8').catch(() => null) : null;
if (lockRaw) {
	let lock;
	try {
		lock = JSON.parse(lockRaw);
	} catch {
		console.error('forge.lock.json is unreadable — the project is LOCKED; fix or remove the lock (`forge unlock`).');
		process.exit(1);
	}
	const changed = [];
	const removed = [];
	for (const [file, expected] of Object.entries(lock.fingerprint ?? {})) {
		const actual = await readFile(path.join(root, file), 'utf8').catch(() => null);
		if (!actual) {
			removed.push(file);
		} else if (sha256(actual) !== expected) {
			changed.push(file);
		}
	}
	const who = lock.lockedBy ?? 'an operator';
	const why = lock.reason ? ` — ${lock.reason}` : '';
	console.log(`LOCKED for hand-editing since ${lock.lockedAt} by ${who}${why}.`);
	console.log(`  ${changed.length} file(s) edited, ${removed.length} removed since handoff.`);
	for (const file of changed.slice(0, 8)) {
		console.log(`  ~ ${file}`);
	}
	for (const file of removed.slice(0, 8)) {
		console.log(`  - ${file}`);
	}
	if (strictLock && (changed.length > 0 || removed.length > 0)) {
		console.error('--strict-lock: drift in hand-edited territory is a failure for this run.');
		process.exit(1);
	}
	console.log('Drift is expected after a handoff — the lock is the ownership record, not a fence. Use --strict-lock to fail on it.');
	process.exit(0);
}

const manifestRaw = await readFile(manifestPath, 'utf8').catch(() => null);
if (!manifestRaw) {
	console.error('nvoos.generated.json is missing — re-scaffold the site (the manifest ships with the generated files).');
	process.exit(1);
}
const manifest = JSON.parse(manifestRaw);
const failures = [];
for (const [file, expected] of Object.entries(manifest.files ?? {})) {
	const actual = await readFile(path.join(root, file), 'utf8').catch(() => null);
	if (!actual) {
		failures.push(`${file}: missing`);
	} else if (sha256(actual) !== expected) {
		failures.push(`${file}: drifted (hand-edited or stale) — re-scaffold to regenerate`);
	}
}
if (failures.length > 0) {
	console.error(`Generated artifacts drifted:\n  ${failures.join('\n  ')}`);
	process.exit(1);
}
console.log('Generated artifacts match the scaffold manifest.');
