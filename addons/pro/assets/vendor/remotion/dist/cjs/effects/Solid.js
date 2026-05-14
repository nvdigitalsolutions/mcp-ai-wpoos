"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.Solid = void 0;
const jsx_runtime_1 = require("react/jsx-runtime");
const react_1 = require("react");
const use_current_frame_js_1 = require("../use-current-frame.js");
const use_delay_render_js_1 = require("../use-delay-render.js");
const run_effect_chain_js_1 = require("./run-effect-chain.js");
const use_effect_chain_state_js_1 = require("./use-effect-chain-state.js");
const Solid = ({ color, width, height, _experimentalEffects: experimentalEffects = [], className, style, pixelRatio = 1, }) => {
    const frame = (0, use_current_frame_js_1.useCurrentFrame)();
    const { delayRender, continueRender, cancelRender } = (0, use_delay_render_js_1.useDelayRender)();
    const [outputCanvas, setOutputCanvas] = (0, react_1.useState)(null);
    const sourceCanvas = (0, react_1.useMemo)(() => {
        if (typeof document === 'undefined') {
            return null;
        }
        const canvas = document.createElement('canvas');
        canvas.width = 1;
        canvas.height = 1;
        return canvas;
    }, []);
    const chainState = (0, use_effect_chain_state_js_1.useEffectChainState)();
    // Fill source and run effect chain on every frame / color change.
    (0, react_1.useEffect)(() => {
        if (!outputCanvas || !sourceCanvas) {
            return;
        }
        const handle = delayRender(`Solid effect chain (frame ${frame})`);
        if (!chainState) {
            continueRender(handle);
            return () => {
                continueRender(handle);
            };
        }
        const ctx = sourceCanvas.getContext('2d', { colorSpace: 'srgb' });
        if (!ctx) {
            cancelRender(new Error('Failed to acquire 2D context for <Solid> source'));
            return;
        }
        ctx.fillStyle = color;
        ctx.fillRect(0, 0, 1, 1);
        (0, run_effect_chain_js_1.runEffectChain)({
            state: chainState.get(width, height),
            source: sourceCanvas,
            effects: experimentalEffects,
            output: outputCanvas,
            frame,
            width,
            height,
        })
            .then((completed) => {
            if (completed) {
                continueRender(handle);
            }
        })
            .catch((err) => {
            cancelRender(err);
        });
        return () => {
            continueRender(handle);
        };
    }, [
        frame,
        color,
        experimentalEffects,
        outputCanvas,
        sourceCanvas,
        chainState,
        width,
        height,
        pixelRatio,
        delayRender,
        continueRender,
        cancelRender,
    ]);
    return ((0, jsx_runtime_1.jsx)("canvas", { ref: setOutputCanvas, width: width, height: height, className: className, style: style }));
};
exports.Solid = Solid;
