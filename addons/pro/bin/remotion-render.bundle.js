#!/usr/bin/env node
"use strict";

// addons/pro/scripts/remotion-render.js
var path = require("path");
var fs = require("fs");
var os = require("os");
function done(success, extra = {}) {
  process.stdout.write(JSON.stringify({ success, ...extra }) + "\n");
  process.exit(success ? 0 : 1);
}
function rimraf(dir) {
  if (!fs.existsSync(dir)) return;
  fs.rmSync(dir, { recursive: true, force: true });
}
(async () => {
  const rawArg = process.argv[2];
  if (!rawArg) {
    done(false, { error: "No JSON argument provided to remotion-render.bundle.js." });
  }
  let input;
  try {
    input = JSON.parse(rawArg);
  } catch (e) {
    done(false, { error: "Invalid JSON argument: " + e.message });
  }
  const {
    indexFile,
    nodeModulesPath,
    compositionId,
    outputFile,
    codec = "h264",
    fps = 30,
    durationInFrames = 150,
    width = 1920,
    height = 1080
  } = input;
  if (!indexFile || !fs.existsSync(indexFile)) {
    done(false, { error: "indexFile does not exist: " + indexFile });
  }
  if (!outputFile) {
    done(false, { error: "outputFile is required." });
  }
  const resolvedNodeModules = nodeModulesPath && fs.existsSync(nodeModulesPath) ? nodeModulesPath : path.join(__dirname, "..", "node_modules");
  const bundleDir = fs.mkdtempSync(path.join(os.tmpdir(), "wp-remotion-"));
  try {
    const { bundle } = require("@remotion/bundler");
    const origNodePath = process.env.NODE_PATH || "";
    process.env.NODE_PATH = resolvedNodeModules + path.delimiter + origNodePath;
    const bundleLocation = await bundle({
      entryPoint: indexFile,
      outDir: bundleDir,
      // Silence noisy webpack progress output.
      onProgress: () => {
      }
    });
    process.env.NODE_PATH = origNodePath;
    const { selectComposition, renderMedia } = require("@remotion/renderer");
    const serveUrl = "file://" + bundleLocation;
    const composition = await selectComposition({
      serveUrl,
      id: compositionId,
      inputProps: {}
    });
    composition.durationInFrames = durationInFrames;
    composition.fps = fps;
    composition.width = width;
    composition.height = height;
    await renderMedia({
      composition,
      serveUrl,
      codec,
      outputLocation: outputFile
    });
    const stat = fs.statSync(outputFile);
    done(true, {
      outputFile,
      file_size: stat.size
    });
  } catch (err) {
    done(false, { error: err.message || String(err) });
  } finally {
    rimraf(bundleDir);
  }
})();
//# sourceMappingURL=remotion-render.bundle.js.map
