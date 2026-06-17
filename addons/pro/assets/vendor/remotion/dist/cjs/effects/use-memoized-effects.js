"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.useMemoizedEffects = void 0;
const react_1 = require("react");
const useMemoizedEffects = (effects) => {
    const previousRef = (0, react_1.useRef)(null);
    const previous = previousRef.current;
    const isSame = previous !== null &&
        previous.length === effects.length &&
        previous.every((p, i) => p.definition === effects[i].definition && p.stack === effects[i].stack);
    if (isSame) {
        return previous;
    }
    const next = effects.map((e) => ({
        definition: e.definition,
        stack: e.stack,
    }));
    previousRef.current = next;
    return next;
};
exports.useMemoizedEffects = useMemoizedEffects;
