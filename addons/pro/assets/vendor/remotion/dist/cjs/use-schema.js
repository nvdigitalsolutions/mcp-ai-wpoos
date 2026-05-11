"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.computeEffectiveSchemaValuesDotNotation = void 0;
const get_effective_visual_mode_value_js_1 = require("./get-effective-visual-mode-value.js");
const findFieldInSchema = (schema, key) => {
    if (key in schema) {
        return schema[key];
    }
    for (const field of Object.values(schema)) {
        if (field.type !== 'enum') {
            continue;
        }
        for (const variant of Object.values(field.variants)) {
            const found = findFieldInSchema(variant, key);
            if (found) {
                return found;
            }
        }
    }
    return undefined;
};
const computeEffectiveSchemaValuesDotNotation = ({ schema, currentValue, overrideValues, propStatus, }) => {
    var _a, _b;
    const merged = {};
    for (const key of Object.keys(currentValue)) {
        const codeValueStatus = (_a = propStatus === null || propStatus === void 0 ? void 0 : propStatus[key]) !== null && _a !== void 0 ? _a : null;
        merged[key] = (0, get_effective_visual_mode_value_js_1.getEffectiveVisualModeValue)({
            codeValue: codeValueStatus,
            runtimeValue: currentValue[key],
            dragOverrideValue: overrideValues[key],
            defaultValue: (_b = findFieldInSchema(schema, key)) === null || _b === void 0 ? void 0 : _b.default,
            shouldResortToDefaultValueIfUndefined: false,
        });
    }
    return merged;
};
exports.computeEffectiveSchemaValuesDotNotation = computeEffectiveSchemaValuesDotNotation;
