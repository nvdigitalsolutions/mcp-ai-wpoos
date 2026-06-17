"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.useEffectChainState = void 0;
const react_1 = require("react");
const run_effect_chain_js_1 = require("./run-effect-chain.js");
const useEffectChainState = () => {
    const chainStateRef = (0, react_1.useRef)(null);
    const sizeRef = (0, react_1.useRef)(null);
    (0, react_1.useEffect)(() => {
        return () => {
            if (chainStateRef.current) {
                (0, run_effect_chain_js_1.cleanupEffectChainState)(chainStateRef.current);
            }
        };
    }, []);
    return (0, react_1.useMemo)(() => ({
        get: (width, height) => {
            if (!sizeRef.current ||
                sizeRef.current.width !== width ||
                sizeRef.current.height !== height) {
                if (chainStateRef.current) {
                    (0, run_effect_chain_js_1.cleanupEffectChainState)(chainStateRef.current);
                }
                chainStateRef.current = (0, run_effect_chain_js_1.createEffectChainState)(width, height);
                sizeRef.current = { width, height };
            }
            return chainStateRef.current;
        },
    }), []);
};
exports.useEffectChainState = useEffectChainState;
