"use strict";
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    var desc = Object.getOwnPropertyDescriptor(m, k);
    if (!desc || ("get" in desc ? !m.__esModule : desc.writable || desc.configurable)) {
      desc = { enumerable: true, get: function() { return m[k]; } };
    }
    Object.defineProperty(o, k2, desc);
}) : (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    o[k2] = m[k];
}));
var __setModuleDefault = (this && this.__setModuleDefault) || (Object.create ? (function(o, v) {
    Object.defineProperty(o, "default", { enumerable: true, value: v });
}) : function(o, v) {
    o["default"] = v;
});
var __importStar = (this && this.__importStar) || (function () {
    var ownKeys = function(o) {
        ownKeys = Object.getOwnPropertyNames || function (o) {
            var ar = [];
            for (var k in o) if (Object.prototype.hasOwnProperty.call(o, k)) ar[ar.length] = k;
            return ar;
        };
        return ownKeys(o);
    };
    return function (mod) {
        if (mod && mod.__esModule) return mod;
        var result = {};
        if (mod != null) for (var k = ownKeys(mod), i = 0; i < k.length; i++) if (k[i] !== "default") __createBinding(result, mod, k[i]);
        __setModuleDefault(result, mod);
        return result;
    };
})();
Object.defineProperty(exports, "__esModule", { value: true });
exports.wrapInSchema = exports.mergeValues = exports.selectActiveKeys = exports.readValuesFromProps = exports.getNestedValue = void 0;
const react_1 = __importStar(require("react"));
const flatten_schema_js_1 = require("./flatten-schema.js");
const SequenceManager_js_1 = require("./SequenceManager.js");
const use_remotion_environment_js_1 = require("./use-remotion-environment.js");
const use_schema_js_1 = require("./use-schema.js");
const getNestedValue = (obj, key) => {
    const parts = key.split('.');
    let current = obj;
    for (const part of parts) {
        if (current === null ||
            current === undefined ||
            typeof current !== 'object')
            return undefined;
        current = current[part];
    }
    return current;
};
exports.getNestedValue = getNestedValue;
const readValuesFromProps = (props, keys) => {
    const out = {};
    for (const key of keys) {
        out[key] = (0, exports.getNestedValue)(props, key);
    }
    return out;
};
exports.readValuesFromProps = readValuesFromProps;
const selectActiveKeys = (schema, values) => {
    return Object.keys((0, flatten_schema_js_1.flattenActiveSchema)(schema, (key) => values[key]));
};
exports.selectActiveKeys = selectActiveKeys;
const mergeValues = ({ props, valuesDotNotation, schemaKeys, }) => {
    const merged = { ...props };
    for (const key of schemaKeys) {
        const value = valuesDotNotation[key];
        const parts = key.split('.');
        if (parts.length === 1) {
            merged[key] = value;
            continue;
        }
        // For dot-notation keys like 'style.opacity',
        // clone and set the nested path
        let current = merged;
        for (let i = 0; i < parts.length - 1; i++) {
            const part = parts[i];
            if (typeof current[part] === 'object' && current[part] !== null) {
                current[part] = { ...current[part] };
            }
            else {
                current[part] = {};
            }
            current = current[part];
        }
        current[parts[parts.length - 1]] = value;
    }
    return merged;
};
exports.mergeValues = mergeValues;
const wrapInSchema = (Component, schema) => {
    var _a;
    if (typeof process === 'undefined' ||
        !((_a = process.env) === null || _a === void 0 ? void 0 : _a.EXPERIMENTAL_VISUAL_MODE_ENABLED)) {
        return Component;
    }
    // Schema is static for a component, so we move this outside
    const flatSchema = (0, flatten_schema_js_1.getFlatSchemaWithAllKeys)(schema);
    const flatKeys = Object.keys(flatSchema);
    const Wrapped = (0, react_1.forwardRef)((props, ref) => {
        const env = (0, use_remotion_environment_js_1.useRemotionEnvironment)();
        const { visualModeEnabled, dragOverrides, codeValues } = (0, react_1.useContext)(SequenceManager_js_1.VisualModeOverridesContext);
        if (!env.isStudio ||
            env.isReadOnlyStudio ||
            env.isRendering ||
            !visualModeEnabled) {
            return react_1.default.createElement(Component, {
                ...props,
                _experimentalControls: null,
                ref,
            });
        }
        // If the parent has passed `_experimentalControls`, we should not override it.
        // @ts-expect-error
        if (props._experimentalControls) {
            return react_1.default.createElement(Component, {
                ...props,
                ref,
            });
        }
        // eslint-disable-next-line react-hooks/rules-of-hooks
        const [overrideId] = (0, react_1.useState)(() => String(Math.random()));
        // Read the runtime values for every flat key from the JSX props,
        // memoized on the leaf values so the object reference is stable
        // when nothing changed — otherwise downstream `useMemo`s churn and
        // effects (e.g. Sequence registration) re-fire every render.
        const runtimeValues = flatKeys.map((k) => (0, exports.getNestedValue)(props, k));
        // eslint-disable-next-line react-hooks/rules-of-hooks
        const currentRuntimeValueDotNotation = (0, react_1.useMemo)(() => (0, exports.readValuesFromProps)(props, flatKeys), 
        // eslint-disable-next-line react-hooks/exhaustive-deps
        runtimeValues);
        // eslint-disable-next-line react-hooks/rules-of-hooks
        const controls = (0, react_1.useMemo)(() => {
            return {
                schema,
                currentRuntimeValueDotNotation,
                overrideId,
            };
        }, [currentRuntimeValueDotNotation, overrideId]);
        // 3. Apply drag/code overrides on top of the runtime values.
        // eslint-disable-next-line react-hooks/rules-of-hooks
        const valuesDotNotation = (0, react_1.useMemo)(() => {
            var _a;
            return (0, use_schema_js_1.computeEffectiveSchemaValuesDotNotation)({
                schema,
                currentValue: currentRuntimeValueDotNotation,
                overrideValues: (_a = dragOverrides[overrideId]) !== null && _a !== void 0 ? _a : {},
                propStatus: codeValues[overrideId],
            });
        }, [currentRuntimeValueDotNotation, dragOverrides, overrideId, codeValues]);
        // 4. Eliminate values forbidden by the resolved discriminated union.
        const activeKeys = (0, exports.selectActiveKeys)(schema, valuesDotNotation);
        // 5. Apply the active values back onto the props.
        const mergedProps = (0, exports.mergeValues)({
            props: props,
            valuesDotNotation,
            schemaKeys: activeKeys,
        });
        return react_1.default.createElement(Component, {
            ...mergedProps,
            _experimentalControls: controls,
            ref,
        });
    });
    Wrapped.displayName = `wrapInSchema(${Component.displayName || Component.name || 'Component'})`;
    return Wrapped;
};
exports.wrapInSchema = wrapInSchema;
