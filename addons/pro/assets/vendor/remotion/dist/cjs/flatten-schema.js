"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.getFlatSchemaWithAllKeys = exports.flattenActiveSchema = void 0;
const flattenActiveSchema = (schema, resolve) => {
    var _a;
    const out = {};
    for (const key of Object.keys(schema)) {
        const field = schema[key];
        if (field.type === 'enum') {
            out[key] = field;
            const current = (_a = resolve(key)) !== null && _a !== void 0 ? _a : field.default;
            const variant = field.variants[current];
            if (variant) {
                Object.assign(out, (0, exports.flattenActiveSchema)(variant, resolve));
            }
        }
        else {
            out[key] = field;
        }
    }
    return out;
};
exports.flattenActiveSchema = flattenActiveSchema;
const getFlatSchemaWithAllKeys = (schema) => {
    const out = {};
    const addKey = (key, field) => {
        if (key in out) {
            throw new Error(`Duplicate key "${key}" in schema: discriminated union variants must not share keys`);
        }
        out[key] = field;
    };
    for (const key of Object.keys(schema)) {
        const field = schema[key];
        addKey(key, field);
        if (field.type === 'enum') {
            for (const variant of Object.values(field.variants)) {
                const flatVariant = (0, exports.getFlatSchemaWithAllKeys)(variant);
                for (const variantKey of Object.keys(flatVariant)) {
                    addKey(variantKey, flatVariant[variantKey]);
                }
            }
        }
    }
    return out;
};
exports.getFlatSchemaWithAllKeys = getFlatSchemaWithAllKeys;
