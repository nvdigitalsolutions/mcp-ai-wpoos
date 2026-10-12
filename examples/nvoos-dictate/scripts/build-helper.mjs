// Build the ScreenCaptureKit sidecar (mac-helper) and stage it over the
// externalBin stub so Tauri bundles + signs it inside the .app.
//
// Runs as `beforeBundleCommand` on every platform:
//   - macOS: `swift build` the helper (optionally pinned to an arch via the
//     first CLI arg: `node scripts/build-helper.mjs arm64|x86_64`) and copy
//     the release binary over the triple-suffixed stub
//     (src-tauri/binaries/nvoos-audio-helper-<triple>).
//   - elsewhere: no-op (the stubs ship; the sidecar is never invoked).
//
// Dev usage: `npm run build:helper` (tauri dev resolves the helper from the
// Swift .build dir — see macos_system_audio.rs::helper_path).

import { spawnSync } from 'node:child_process';
import { cpSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');

if (process.platform !== 'darwin') {
  console.log('build:helper — not macOS, skipping sidecar build');
  process.exit(0);
}

const archArg = process.argv[2];
const arch = archArg ?? (process.arch === 'arm64' ? 'arm64' : 'x86_64');
const triple = arch === 'arm64' ? 'aarch64-apple-darwin' : 'x86_64-apple-darwin';

const helperDir = join(root, 'mac-helper');
const release = join(helperDir, '.build', 'release', 'nvoos-audio-helper');
const stub = join(root, 'src-tauri', 'binaries', `nvoos-audio-helper-${triple}`);

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
