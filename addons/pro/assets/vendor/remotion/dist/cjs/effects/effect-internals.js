"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.groupByBackend = exports.flattenEffects = void 0;
// Internal helpers for the chain runtime. Exported separately so they can be
// unit-tested without spinning up the React lifecycle / canvas mocking.
const flattenEffects = (effects) => {
    const out = [];
    for (const item of effects) {
        if (Array.isArray(item)) {
            for (const inner of item) {
                out.push(inner);
            }
        }
        else {
            out.push(item);
        }
    }
    return out;
};
exports.flattenEffects = flattenEffects;
const groupByBackend = (effects) => {
    const runs = [];
    let current = [];
    let currentBackend = null;
    for (const eff of effects) {
        const { backend } = eff.definition;
        if (currentBackend === null || backend === currentBackend) {
            current.push(eff);
            currentBackend = backend;
        }
        else {
            runs.push({ backend: currentBackend, effects: current });
            current = [eff];
            currentBackend = backend;
        }
    }
    if (currentBackend !== null && current.length > 0) {
        runs.push({ backend: currentBackend, effects: current });
    }
    return runs;
};
exports.groupByBackend = groupByBackend;
