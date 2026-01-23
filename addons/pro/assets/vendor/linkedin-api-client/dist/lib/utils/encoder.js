"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.paramEncode = exports.reducedEncode = exports.encode = void 0;
const constants_1 = require("./constants");
// rest.li special characters:
const badChars = /[,()':]/g;
const possible = /[,()':]/;
/**
 * Check if a parameter is object-like, assert via TS and throw Error if not object-like
 * @param json - unknown parameter to be checked
 */
function assertIsObjectNotArray(json, errorMessage) {
    if (Array.isArray(json) || typeof json !== 'object' || json === null) {
        throw new Error(errorMessage);
    }
}
/**
 * Entry point to encode a JSON object to the rest.li spec with URL encoding.
 *
 * NOTES:
 * - `undefined` values will be removed from the passed in JSON.
 * - `null` values will be turned into the string 'null'.
 * - `true` values will be turned into the string 'true'.
 * - `false` values will be turned into the string 'false'.
 */
function encode(value) {
    // This will remove undefined values from an object
    const parsedValue = JSON.parse(JSON.stringify(value));
    return encodeAnyType(parsedValue, false);
}
exports.encode = encode;
/**
 * Entry point to encode a JSON object to the rest.li spec with body encoding.
 */
function reducedEncode(value) {
    const parsedValue = JSON.parse(JSON.stringify(value));
    return encodeAnyType(parsedValue, true);
}
exports.reducedEncode = reducedEncode;
/**
 * Entry point for serializing an arbitrary map of rest.li objects to querystring
 */
function paramEncode(json) {
    if (!json) {
        return '';
    }
    const parsedJson = JSON.parse(JSON.stringify(json));
    assertIsObjectNotArray(parsedJson, 'You must pass an object to the paramEncode function.');
    const query = Object.keys(parsedJson).map((property) => {
        return `${encodePrimitive(property)}=${encodeAnyType(parsedJson[property], false)}`;
    });
    return query.join('&');
}
exports.paramEncode = paramEncode;
function isRecord(value) {
    return typeof value === 'object' && value !== null;
}
function encodeAnyType(value, reduced) {
    if (Array.isArray(value)) {
        return encodeArray(value, reduced);
    }
    else if (isRecord(value)) {
        return encodeObject(value, reduced);
    }
    else {
        return encodePrimitive(value, reduced);
    }
}
/**
 * Escapes an array.
 */
function encodeArray(value, reduced) {
    const nested = new Array(value.length);
    for (let i = 0; i < value.length; i++) {
        nested[i] = encodeAnyType(value[i], reduced);
    }
    return `${constants_1.LIST_PREFIX}${nested.join(',')}${constants_1.LIST_SUFFIX}`;
}
/**
 * Escapes an object.
 */
function encodeObject(value, reduced) {
    const nested = Object.keys(value).map((property) => {
        return `${encodePrimitive(property, reduced)}:${encodeAnyType(value[property], reduced)}`;
    });
    return `${constants_1.OBJ_PREFIX}${nested.join(',')}${constants_1.OBJ_SUFFIX}`;
}
/**
 * Escapes a primitive value.
 */
function encodePrimitive(value, reduced = false) {
    if (value === '') {
        return "''";
    }
    else if (reduced && typeof value === 'string' && possible.test(value)) {
        return value.replace(badChars, escape);
    }
    else if (!reduced) {
        // TODO avoid casting here. encodeURIComponent type is not correct as it actually accepts null and undefined
        return encodeURIComponent(value).replace(badChars, escape);
    }
    else {
        return value;
    }
}
//# sourceMappingURL=encoder.js.map