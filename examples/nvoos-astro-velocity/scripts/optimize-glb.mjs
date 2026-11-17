#!/usr/bin/env node
// ---------------------------------------------------------------------------
// optimize-glb.mjs — the 3D asset pipeline AND the byte-budget gate.
//
// Pipeline order follows the industry guidance for web 3D: TEXTURES first,
// then draw calls, then triangle count — the reverse of the order most briefs
// assume. `gltf-transform optimize` does texture compression + dedup/prune/
// resample before geometry compression runs.
//
// The CLI is an OPTIONAL devDependency. This script never runs in the build or
// deploy path, so a missing binary degrades to a report-only run instead of
// failing the build:
//
//   npm i -D @gltf-transform/cli     # enables the optimize pipeline
//   npm run optimize:model -- source.glb --max-bytes 2097152
//
// Usage:
//   node scripts/optimize-glb.mjs <input...> [options] [-- <extra CLI args>]
//
// Options:
//   --out <dir>       Output directory. Default: alongside the input.
//   --max-bytes <n>   Byte ceiling per output file. Default 2 MiB (a product
//                     model that has to paint on a mobile PDP).
//   --ktx2            Also run `etc1s` (KTX2/Basis). Needs the KTX-Software
//                     tools on PATH; skip it when they are not installed.
//   --report-only     Measure and gate only — never invoke the CLI.
//
// Exit codes: 0 = within budget, 1 = over budget, 2 = bad input / missing tool.
// ---------------------------------------------------------------------------

import { spawnSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { mkdir, stat } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

/** A product model has to paint on a mid-range phone. 2 MiB is the ceiling. */
const DEFAULT_MAX_BYTES = 2 * 1024 * 1024;

const EXTENSIONS = new Set(['.glb', '.gltf']);

/** Parse argv: positional inputs, known flags, and a `--` passthrough tail. */
function parseArgs(argv) {
	const inputs = [];
	const extra = [];
	const options = {
		out: undefined,
		maxBytes: DEFAULT_MAX_BYTES,
		ktx2: false,
		reportOnly: false,
	};

	for (let i = 0; i < argv.length; i += 1) {
		const arg = argv[i];
		if (arg === '--') {
			extra.push(...argv.slice(i + 1));
			break;
		}
		switch (arg) {
			case '--out':
				options.out = argv[++i];
				break;
			case '--max-bytes':
				options.maxBytes = Number(argv[++i]);
				break;
			case '--ktx2':
				options.ktx2 = true;
				break;
			case '--report-only':
				options.reportOnly = true;
				break;
			case '--help':
			case '-h':
				options.help = true;
				break;
			default:
				if (arg.startsWith('-')) {
					throw new Error(`Unknown option: ${arg}`);
				}
				inputs.push(arg);
		}
	}

	return { inputs, options, extra };
}

/** The locally installed CLI, or null when the optional dep is absent. */
function resolveCli() {
	const bin = path.join(
		process.cwd(),
		'node_modules',
		'.bin',
		process.platform === 'win32' ? 'gltf-transform.cmd' : 'gltf-transform'
	);
	return existsSync(bin) ? bin : null;
}

/** Run one CLI command; returns true on success. */
function runCli(bin, args) {
	const result = spawnSync(bin, args, {
		stdio: 'inherit',
		// A .cmd shim on Windows only resolves through the shell.
		shell: process.platform === 'win32',
	});
	if (result.error) {
		console.error(`  ! ${result.error.message}`);
		return false;
	}
	if (result.status !== 0) {
		console.error(`  ! gltf-transform ${args[0]} exited ${result.status}`);
		return false;
	}
	return true;
}

/** Human-readable byte size. */
function human(bytes) {
	if (bytes < 1024) {
		return `${bytes} B`;
	}
	if (bytes < 1024 * 1024) {
		return `${(bytes / 1024).toFixed(1)} KiB`;
	}
	return `${(bytes / (1024 * 1024)).toFixed(2)} MiB`;
}

/** Size on disk, or null when the path is missing. */
async function sizeOf(file) {
	try {
		return (await stat(file)).size;
	} catch {
		return null;
	}
}

/**
 * The full pipeline for one input. Textures → geometry, per the budget order.
 * Returns the output path, or null when a step failed.
 */
async function optimizeOne(bin, input, outDir, ktx2, extra) {
	const base = path.basename(input, path.extname(input));
	const optimized = path.join(outDir, `${base}.glb`);

	if (!runCli(bin, ['optimize', input, optimized, '--compress', 'draco', '--texture-compress', 'webp', ...extra])) {
		return null;
	}

	if (ktx2) {
		// KTX2/Basis is the VRAM win, but the encoder is external tooling — a
		// missing KTX-Software install must not throw away the WebP output.
		const staged = path.join(outDir, `${base}.ktx2.glb`);
		if (!runCli(bin, ['etc1s', optimized, staged, '--power-of-two'])) {
			console.warn('  ! KTX2 step skipped (is KTX-Software on PATH?). Keeping the WebP output.');
			return optimized;
		}
		return staged;
	}

	return optimized;
}

async function main() {
	let parsed;
	try {
		parsed = parseArgs(process.argv.slice(2));
	} catch (error) {
		console.error(`optimize-glb: ${error.message}`);
		return 2;
	}

	const { inputs, options, extra } = parsed;

	if (options.help || inputs.length === 0) {
		console.log(
			[
				'Usage: node scripts/optimize-glb.mjs <input...> [--out <dir>] [--max-bytes <n>]',
				'                                      [--ktx2] [--report-only] [-- <extra CLI args>]',
				'',
				'  --out          Output directory (default: alongside the input).',
				`  --max-bytes    Per-file ceiling (default ${DEFAULT_MAX_BYTES} = 2 MiB).`,
				'  --ktx2         Also run etc1s (needs KTX-Software on PATH).',
				'  --report-only  Measure + gate only; never invoke the CLI.',
			].join('\n')
		);
		return options.help ? 0 : 2;
	}

	for (const input of inputs) {
		if (!EXTENSIONS.has(path.extname(input).toLowerCase())) {
			console.error(`optimize-glb: ${input} is not a .glb/.gltf file`);
			return 2;
		}
		if (!existsSync(input)) {
			console.error(`optimize-glb: ${input} does not exist`);
			return 2;
		}
	}

	const bin = options.reportOnly ? null : resolveCli();
	if (!options.reportOnly && !bin) {
		console.warn(
			'optimize-glb: @gltf-transform/cli is not installed — reporting sizes only.\n' +
				'              Run `npm i -D @gltf-transform/cli` to enable the pipeline.'
		);
	}

	if (bin && options.out) {
		await mkdir(options.out, { recursive: true });
	}

	/** @type {{ file: string, before: number|null, after: number|null }[]} */
	const rows = [];

	for (const input of inputs) {
		const before = await sizeOf(input);
		const outDir = options.out ?? path.dirname(input);

		let produced = null;
		if (bin) {
			console.log(`\n› ${input}`);
			produced = await optimizeOne(bin, input, outDir, options.ktx2, extra);
		}

		const target = produced ?? (options.out ? path.join(options.out, path.basename(input)) : input);
		const after = await sizeOf(target);
		rows.push({ file: target, before, after: after ?? before });
	}

	let overBudget = false;
	console.log('\nfile'.padEnd(52) + 'before'.padStart(12) + 'after'.padStart(12) + '  verdict');
	console.log('-'.repeat(92));
	for (const row of rows) {
		const breach = (row.after ?? 0) > options.maxBytes;
		overBudget = overBudget || breach;
		const delta =
			row.before && row.after && row.before !== row.after
				? ` (${Math.round((1 - row.after / row.before) * 100)}% smaller)`
				: '';
		console.log(
			row.file.slice(-50).padEnd(52) +
				human(row.before ?? 0).padStart(12) +
				`${human(row.after ?? 0)}${delta}`.padStart(12) +
				(breach ? `  OVER ${human(options.maxBytes)}` : '  ok')
		);
	}

	if (overBudget) {
		console.error(
			`\noptimize-glb: at least one model exceeds ${human(options.maxBytes)}. ` +
				'Textures first (KTX2), then draw calls, then triangles.'
		);
		return 1;
	}

	console.log(`\nAll ${rows.length} model(s) within ${human(options.maxBytes)}.`);
	return 0;
}

process.exitCode = await main();
