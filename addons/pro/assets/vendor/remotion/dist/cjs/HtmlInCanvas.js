"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.HtmlInCanvas = exports.isHtmlInCanvasSupported = void 0;
const jsx_runtime_1 = require("react/jsx-runtime");
const react_1 = require("react");
const delay_render_js_1 = require("./delay-render.js");
const run_effect_chain_js_1 = require("./effects/run-effect-chain.js");
const use_effect_chain_state_js_1 = require("./effects/use-effect-chain-state.js");
const enable_sequence_stack_traces_js_1 = require("./enable-sequence-stack-traces.js");
const sequence_field_schema_js_1 = require("./sequence-field-schema.js");
const Sequence_js_1 = require("./Sequence.js");
const use_current_frame_js_1 = require("./use-current-frame.js");
const use_delay_render_js_1 = require("./use-delay-render.js");
const use_video_config_js_1 = require("./use-video-config.js");
const wrap_in_schema_js_1 = require("./wrap-in-schema.js");
// Memoize the support check across the session — neither the platform
// capability nor the chrome://flags toggle can change between calls.
// SSR results are not cached so the check runs again once `document` exists.
let cachedSupport = null;
const isHtmlInCanvasSupported = () => {
    if (cachedSupport !== null) {
        return cachedSupport;
    }
    if (typeof document === 'undefined') {
        return false;
    }
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    cachedSupport =
        typeof (ctx === null || ctx === void 0 ? void 0 : ctx.drawElementImage) === 'function' &&
            typeof canvas.requestPaint === 'function' &&
            typeof canvas.captureElementImage === 'function';
    return cachedSupport;
};
exports.isHtmlInCanvasSupported = isHtmlInCanvasSupported;
function assertHtmlInCanvasDimensions(width, height) {
    if (typeof width !== 'number' || typeof height !== 'number') {
        throw new Error(`HtmlInCanvas: \`width\` and \`height\` must be numbers. Received width=${String(width)}, height=${String(height)}.`);
    }
    if (!Number.isInteger(width) || width <= 0) {
        throw new Error(`HtmlInCanvas: \`width\` must be a positive integer. Received: ${String(width)}.`);
    }
    if (!Number.isInteger(height) || height <= 0) {
        throw new Error(`HtmlInCanvas: \`height\` must be a positive integer. Received: ${String(height)}.`);
    }
}
const defaultOnPaint = ({ canvas, element, elementImage, }) => {
    const ctx = canvas.getContext('2d');
    if (!ctx) {
        throw new Error('Failed to acquire 2D context for <HtmlInCanvas> canvas');
    }
    ctx.reset();
    const transform = ctx.drawElementImage(elementImage, 0, 0);
    element.style.transform = transform.toString();
};
/* eslint-enable react/require-default-props */
const HtmlInCanvasAncestorContext = (0, react_1.createContext)(false);
const HtmlInCanvasInner = (0, react_1.forwardRef)(({ width, height, _experimentalEffects: effects = [], children, onPaint, onInit, _experimentalControls: controls, style, durationInFrames, ...sequenceProps }, ref) => {
    const isInsideAncestorHtmlInCanvas = (0, react_1.useContext)(HtmlInCanvasAncestorContext);
    assertHtmlInCanvasDimensions(width, height);
    const { continueRender, cancelRender } = (0, use_delay_render_js_1.useDelayRender)();
    if (!(0, exports.isHtmlInCanvasSupported)()) {
        cancelRender(new Error('HTML in Canvas is not supported. Open this page in Chrome Canary with chrome://flags/#canvas-draw-element enabled.'));
    }
    const { durationInFrames: videoDuration } = (0, use_video_config_js_1.useVideoConfig)();
    const resolvedDuration = durationInFrames !== null && durationInFrames !== void 0 ? durationInFrames : videoDuration;
    const frame = (0, use_current_frame_js_1.useCurrentFrame)();
    const canvas2dRef = (0, react_1.useRef)(null);
    const divRef = (0, react_1.useRef)(null);
    const setLayoutCanvasRef = (0, react_1.useCallback)((node) => {
        canvas2dRef.current = node;
        if (typeof ref === 'function') {
            ref(node);
        }
        else if (ref) {
            ref.current =
                node;
        }
    }, [ref]);
    const [offscreenCanvas] = (0, react_1.useState)(() => new OffscreenCanvas(1, 1));
    const chainState = (0, use_effect_chain_state_js_1.useEffectChainState)();
    // Refs so the paint handler always reads fresh values.
    const effectsRef = (0, react_1.useRef)(effects);
    effectsRef.current = effects;
    const frameRef = (0, react_1.useRef)(frame);
    frameRef.current = frame;
    const onPaintRef = (0, react_1.useRef)(onPaint);
    onPaintRef.current = onPaint;
    const onInitRef = (0, react_1.useRef)(onInit);
    onInitRef.current = onInit;
    const initializedRef = (0, react_1.useRef)(false);
    const onInitCleanupRef = (0, react_1.useRef)(null);
    const unmountedRef = (0, react_1.useRef)(false);
    const onPaintCb = (0, react_1.useCallback)(async () => {
        var _a;
        const element = divRef.current;
        if (!element) {
            throw new Error('Canvas or scene element not found');
        }
        offscreenCanvas.width = width;
        offscreenCanvas.height = height;
        try {
            const layoutCanvas = canvas2dRef.current;
            if (!layoutCanvas) {
                throw new Error('Canvas not found');
            }
            // `GPUQueue.copyElementImageToTexture` / related paths validate the
            // layout canvas has a rendering context. `runEffectChain` only runs
            // after `onPaint`, so acquire `2d` here before any capture or handler.
            const layout2d = layoutCanvas.getContext('2d');
            if (!layout2d) {
                throw new Error('Failed to acquire 2D context for <HtmlInCanvas> layout canvas');
            }
            const handle = (0, delay_render_js_1.delayRender)('onPaint');
            if (!initializedRef.current) {
                initializedRef.current = true;
                // `onInit` may be async (e.g. WebGPU `requestAdapter`/`requestDevice`).
                // Capture an `ElementImage` here only for `onInit` consumers — do NOT
                // reuse it for the paint handler below, because awaiting `onInit`
                // can invalidate the capture's paint context, leaving subsequent
                // uploads (e.g. `copyElementImageToTexture`) failing with
                // "No context found for ElementImage" on the very first paint.
                const initImage = layoutCanvas.captureElementImage(element);
                const currentOnInit = onInitRef.current;
                if (currentOnInit) {
                    const cleanup = await currentOnInit({
                        canvas: offscreenCanvas,
                        element,
                        elementImage: initImage,
                    });
                    if (typeof cleanup !== 'function') {
                        throw new Error('HtmlInCanvas: when `onInit` is provided, it must return a cleanup function, or a Promise that resolves to one.');
                    }
                    if (unmountedRef.current) {
                        cleanup();
                    }
                    else {
                        onInitCleanupRef.current = cleanup;
                    }
                }
            }
            const handler = (_a = onPaintRef.current) !== null && _a !== void 0 ? _a : defaultOnPaint;
            const elImage = layoutCanvas.captureElementImage(element);
            await handler({
                canvas: offscreenCanvas,
                element,
                elementImage: elImage,
            });
            await (0, run_effect_chain_js_1.runEffectChain)({
                state: chainState.get(width, height),
                source: offscreenCanvas,
                effects: effectsRef.current,
                output: canvas2dRef.current,
                frame: frameRef.current,
                width,
                height,
            });
            continueRender(handle);
        }
        catch (error) {
            cancelRender(error);
        }
    }, [
        chainState,
        continueRender,
        cancelRender,
        width,
        height,
        offscreenCanvas,
    ]);
    // Set up layoutSubtree and persistent paint listener. Runs as a
    // layout effect so the listener is attached before the resize effect
    // below dispatches its first synthetic paint.
    (0, react_1.useLayoutEffect)(() => {
        const canvas = canvas2dRef.current;
        if (!canvas) {
            throw new Error('Canvas not found');
        }
        canvas.layoutSubtree = true;
        canvas.addEventListener('paint', onPaintCb);
        return () => {
            var _a;
            canvas.removeEventListener('paint', onPaintCb);
            unmountedRef.current = true;
            (_a = onInitCleanupRef.current) === null || _a === void 0 ? void 0 : _a.call(onInitCleanupRef);
            onInitCleanupRef.current = null;
        };
    }, [onPaintCb, cancelRender]);
    const onPaintChangedRef = (0, react_1.useRef)(false);
    (0, react_1.useLayoutEffect)(() => {
        var _a;
        if (!onPaintChangedRef.current) {
            onPaintChangedRef.current = true;
            return;
        }
        const canvas = canvas2dRef.current;
        if (!canvas) {
            return;
        }
        (_a = canvas.requestPaint) === null || _a === void 0 ? void 0 : _a.call(canvas);
    }, [onPaint]);
    (0, react_1.useLayoutEffect)(() => {
        const canvas = canvas2dRef.current;
        if (!canvas) {
            return;
        }
        const handle = (0, delay_render_js_1.delayRender)('waiting for first paint after canvas resize');
        canvas.addEventListener('paint', () => {
            continueRender(handle);
        }, { once: true });
        return () => {
            continueRender(handle);
        };
    }, [width, height, continueRender]);
    const innerStyle = (0, react_1.useMemo)(() => {
        return {
            width,
            height,
        };
    }, [width, height]);
    if (isInsideAncestorHtmlInCanvas) {
        throw new Error('<HtmlInCanvas> effects cannot be nested together. Chrome will only display the outer effect. Consider merging the effects into one if you can.');
    }
    return ((0, jsx_runtime_1.jsx)(Sequence_js_1.Sequence, { durationInFrames: resolvedDuration, name: "<HtmlInCanvas>", _experimentalControls: controls, _experimentalEffects: effects, layout: "none", ...sequenceProps, children: (0, jsx_runtime_1.jsx)(HtmlInCanvasAncestorContext.Provider, { value: true, children: (0, jsx_runtime_1.jsx)("canvas", { ref: setLayoutCanvasRef, width: width, height: height, style: style, children: (0, jsx_runtime_1.jsx)("div", { ref: divRef, style: innerStyle, children: children }) }) }) }));
});
HtmlInCanvasInner.displayName = 'HtmlInCanvas';
const HtmlInCanvasWrapped = (0, wrap_in_schema_js_1.wrapInSchema)(HtmlInCanvasInner, sequence_field_schema_js_1.sequenceStyleSchema);
exports.HtmlInCanvas = Object.assign(HtmlInCanvasWrapped, {
    isSupported: exports.isHtmlInCanvasSupported,
});
exports.HtmlInCanvas.displayName = 'HtmlInCanvas';
(0, enable_sequence_stack_traces_js_1.addSequenceStackTraces)(exports.HtmlInCanvas);
