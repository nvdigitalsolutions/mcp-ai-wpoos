"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.runEffectChain = exports.cleanupEffectChainState = exports.createEffectChainState = void 0;
const canvas_pool_js_1 = require("./canvas-pool.js");
const effect_internals_js_1 = require("./effect-internals.js");
const gpu_device_js_1 = require("./gpu-device.js");
const createEffectChainState = (width, height) => ({
    pool: new canvas_pool_js_1.CanvasPool(width, height),
    setupCache: new WeakMap(),
    cleanupRegistry: [],
    currentRunId: 0,
});
exports.createEffectChainState = createEffectChainState;
const cleanupEffectChainState = (state) => {
    state.currentRunId++;
    for (const entry of state.cleanupRegistry) {
        entry.definition.cleanup(entry.state);
    }
};
exports.cleanupEffectChainState = cleanupEffectChainState;
const ensureSetup = (state, def, target) => {
    const widened = def;
    if (state.setupCache.has(widened)) {
        return state.setupCache.get(widened);
    }
    const setupState = def.setup(target);
    state.setupCache.set(widened, setupState);
    state.cleanupRegistry.push({ definition: widened, state: setupState });
    return setupState;
};
// Runs the effect pipeline imperatively. Returns `true` if the pipeline
// completed and wrote to `output`, `false` if it was superseded by a newer
// run (caller should not act on a stale result).
const runEffectChain = async ({ state, source, effects, output, frame, width, height, }) => {
    var _a;
    const runId = ++state.currentRunId;
    const isCancelled = () => state.currentRunId !== runId;
    const flattened = (0, effect_internals_js_1.flattenEffects)(effects);
    const runs = (0, effect_internals_js_1.groupByBackend)(flattened);
    let currentImage = source;
    let lastTarget = null;
    if (runs.length === 0) {
        // In-place pipeline (e.g. <HtmlInCanvas>: drawElementImage into the same
        // surface, no further effects) — the bitmap is already on `output`.
        if (source === output) {
            return true;
        }
        const ctx = output.getContext('2d');
        if (!ctx) {
            throw new Error('Failed to acquire 2D context for output canvas');
        }
        ctx.clearRect(0, 0, width, height);
        ctx.drawImage(currentImage, 0, 0, width, height);
        return true;
    }
    let needsGpuDevice = false;
    for (const run of runs) {
        if (run.backend === 'webgpu') {
            needsGpuDevice = true;
            break;
        }
    }
    const gpuDevice = needsGpuDevice ? await (0, gpu_device_js_1.getGpuDevice)() : null;
    if (isCancelled()) {
        return false;
    }
    for (let runIndex = 0; runIndex < runs.length; runIndex++) {
        const run = runs[runIndex];
        const [a, b] = state.pool.getPair(run.backend);
        let dst = a;
        for (const eff of run.effects) {
            const def = eff.definition;
            const setupState = ensureSetup(state, def, dst);
            def.apply({
                source: currentImage,
                target: dst,
                state: setupState,
                params: eff.params,
                frame,
                width,
                height,
                gpuDevice,
            });
            if (run.backend === 'webgl2') {
                state.pool.assertContextNotLost(dst);
            }
            currentImage = dst;
            dst = dst === a ? b : a;
        }
        lastTarget = (_a = currentImage) !== null && _a !== void 0 ? _a : lastTarget;
        const nextRun = runs[runIndex + 1];
        if (nextRun && nextRun.backend !== run.backend && lastTarget) {
            // Bridge between backend groups via `createImageBitmap` rather than
            // passing the canvas straight through. A direct `drawImage(webglCanvas)`
            // in the next backend's first effect forces an implicit GPU readback /
            // finish on the consuming context, which empirically blows the per-frame
            // vsync budget and halves the paint rate. `createImageBitmap` performs
            // the same handoff but pipelines the GPU work, so the next effect reads
            // from a ready-to-sample bitmap without stalling.
            const bitmap = await createImageBitmap(lastTarget);
            if (isCancelled()) {
                bitmap.close();
                return false;
            }
            currentImage = bitmap;
        }
    }
    if (!lastTarget) {
        return true;
    }
    const outCtx = output.getContext('2d');
    if (!outCtx) {
        throw new Error('Failed to acquire 2D context for output canvas');
    }
    outCtx.clearRect(0, 0, width, height);
    outCtx.drawImage(lastTarget, 0, 0, width, height);
    return true;
};
exports.runEffectChain = runEffectChain;
