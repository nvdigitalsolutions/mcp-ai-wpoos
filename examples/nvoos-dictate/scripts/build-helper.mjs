// Build the ScreenCaptureKit sidecar (mac-helper) and stage it over the
// externalBin stub so Tauri bundles + signs it inside the .app.
//
// Runs as `beforeBundleCommand` on every platform:
//   - macOS: `swift build` the helper (optionally pinned to an arch via the
//     first CLI arg: `node scripts/build-helper.mjs arm64|x86_64|universal`)
//     and copy the release binary over the triple-suffixed stub
//     (src-tauri/binaries/nvoos-audio-helper-<triple>). `universal` builds
//     both slices into isolated scratch dirs, lipo's them together, stages
//     the result over the -universal-apple-darwin stub, and self-checks the
//     merged binary with `lipo -info`.
//   - elsewhere: no-op (the stubs ship; the sidecar is never invoked).
//
// Dev usage: `npm run build:helper` (tauri dev resolves the helper from the
// Swift .build dir — see macos_system_audio.rs::helper_path).

import { spawnSync } from 'node:child_process';
import { cpSync, existsSync, mkdirSync, readdirSync, rmSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');

if (process.platform !== 'darwin') {
  console.log('build:helper — not macOS, skipping sidecar build');
  process.exit(0);
}

const archArg = process.argv[2];
// Prefer the env pin (publish matrix / boot-smoke set NVOOS_HELPER_ARCH so
// the beforeBundleCommand stages the right slice even when re-run inside
// tauri-action); fall back to the CLI arg, then the native arch.
const arch =
  process.env.NVOOS_HELPER_ARCH ?? archArg ?? (process.arch === 'arm64' ? 'arm64' : 'x86_64');
if (!['arm64', 'x86_64', 'universal'].includes(arch)) {
  console.error(`build:helper — unknown arch '${arch}' (expected arm64 | x86_64 | universal)`);
  process.exit(2);
}
const triple =
  arch === 'arm64' ? 'aarch64-apple-darwin'
  : arch === 'x86_64' ? 'x86_64-apple-darwin'
  : 'universal-apple-darwin';

const helperDir = join(root, 'mac-helper');
const release = join(helperDir, '.build', 'release', 'nvoos-audio-helper');
const stub = join(root, 'src-tauri', 'binaries', `nvoos-audio-helper-${triple}`);

// Recursively find a file by name. SwiftPM moves products between layout
// schemes (.build/release vs .build/<triple>/release vs apple/Products) and
// cross-arch builds use per-target dirs, so never guess a path — search the
// build tree instead.
function findInTree(dir, name) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) {
      const hit = findInTree(path, name);
      if (hit) return hit;
    } else if (entry.name === name) {
      return path;
    }
  }
  return null;
}

if (arch === 'universal') {
  // Isolated scratch dirs per slice: a shared tree could let one arch's
  // products clobber the other's, and per-SwiftPM-version layout drift is
  // absorbed by findInTree instead of a hardcoded path.
  const slices = [];
  for (const slice of ['arm64', 'x86_64']) {
    const scratch = join(helperDir, '.build', `universal-${slice}`);
    const build = spawnSync(
      'swift',
      ['build', '-c', 'release', '--arch', slice, '--scratch-path', scratch, '--package-path', helperDir],
      { stdio: 'inherit' },
    );
    if (build.status !== 0) {
      console.error(`build:helper — swift build failed for ${slice}`);
      process.exit(build.status ?? 1);
    }
    const bin = findInTree(scratch, 'nvoos-audio-helper');
    if (!bin) {
      console.error(`build:helper — ${slice} slice not found under ${scratch}`);
      process.exit(1);
    }
    slices.push(bin);
  }

  mkdirSync(dirname(stub), { recursive: true });
  rmSync(stub, { force: true });
  const lipo = spawnSync('lipo', ['-create', ...slices, '-output', stub], { stdio: 'inherit' });
  if (lipo.status !== 0) {
    console.error('build:helper — lipo failed');
    process.exit(lipo.status ?? 1);
  }
  // Self-check: the staged binary must contain both slices, otherwise the
  // bundle is silently single-arch and Intel (or Silicon) Macs fail at
  // launch with "not supported on this Mac".
  const info = spawnSync('lipo', ['-info', stub], { encoding: 'utf8' });
  const infoOut = `${info.stdout || ''}${info.stderr || ''}`;
  if (!infoOut.includes('arm64') || !infoOut.includes('x86_64')) {
    console.error(`build:helper — staged helper is not universal: ${infoOut.trim()}`);
    process.exit(1);
  }
  console.log(`build:helper — staged universal binary -> ${stub}`);
} else {
  const build = spawnSync(
    'swift',
    ['build', '-c', 'release', '--arch', arch, '--package-path', helperDir],
    { stdio: 'inherit' },
  );
  if (build.status !== 0) {
    console.error('build:helper — swift build failed');
    process.exit(build.status ?? 1);
  }

  if (!existsSync(release)) {
    console.error(`build:helper — expected binary not found: ${release}`);
    process.exit(1);
  }

  mkdirSync(dirname(stub), { recursive: true });
  cpSync(release, stub);
  console.log(`build:helper — staged ${release} -> ${stub}`);
}
