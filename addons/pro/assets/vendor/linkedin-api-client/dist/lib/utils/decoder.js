"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.reducedDecode = exports.decode = exports.paramDecode = void 0;
const constants_1 = require("./constants");
// rest.li special characters: ,()':
const escapedChars = /(%2C|%28|%29|%27|%3A)/g;
const testEscapedChars = /(%2C|%28|%29|%27|%3A)/;
/**
 * Polyfill startsWith for IE11
 *
 * @param {string} str
 * @param {string} search
 * @param {number} [pos]
 * @returns {boolean}
 */
function strStartsWith(str, search, pos = 0) {
    return str.indexOf(search, pos) === pos;
}
/**
 * Validate that input ends with a specified suffix.
 * The suffix has to be a single-character string.
 *
 * @param {string} serializedrestli
 * @param {string} suffix a single-character string
 */
function validateSuffix(serializedrestli, suffix) {
    if (serializedrestli[serializedrestli.length - 1] !== suffix) {
        throw new Error(`Input has unbalanced prefix and suffix: ${serializedrestli}`);
    }
}
/**
 * Find Last bracket to match, starting from pos
 *
 * @param {string} str
 * @param {number} [pos=0]
 * @returns {number}
 */
function findLastRightBracket(str, pos = 0) {
    let numLeft = 0;
    let hasMetFirst = false;
    const L = '(';
    const R = ')';
    while (pos < str.length) {
        const currChar = str[pos];
        if (currChar === L) {
            numLeft++;
            hasMetFirst = true;
        }
        if (currChar === R)
            numLeft--;
        if (numLeft === 0 && hasMetFirst)
            break;
        pos++;
    }
    return pos;
}
/**
 * Reverse the rest.li escaping, called during the decoding.
 */
function restliUnescape(value, reduced) {
    if (!reduced) {
        value = decodeURIComponent(value);
    }
    else if (testEscapedChars.test(value)) {
        value = value.replace(escapedChars, unescape);
    }
    return value === undefined || value === "''" ? '' : value;
}
function paramDecode(querystring) {
    return querystring
        .split('&')
        .reduce(function (previous, current) {
        // Short circuit if there isn't a key.
        if (!current.length) {
            return previous;
        }
        if (current.indexOf('=') === 0) {
            return previous;
        }
        let [key = '', value] = current.split('=');
        // Rest.li special-cases empty strings.
        if (key === "''") {
            key = '';
        }
        if (value === undefined || value === '') {
            value = "''";
        }
        previous[decodeURIComponent(key)] = decode(value);
        return previous;
    }, {});
}
exports.paramDecode = paramDecode;
/**
 * Entry point to decode a URL encoded rest.li object.
 *
 * NOTES:
 * - The Rest.li format is lossy. All values come out of this as strings.
 */
function decode(serializedrestli) {
    return internalDecode(serializedrestli, false);
}
exports.decode = decode;
/**
 * Entry point to decode a body encoded rest.li object.
 */
function reducedDecode(serializedrestli) {
    return internalDecode(serializedrestli, true);
}
exports.reducedDecode = reducedDecode;
function internalDecode(serializedrestli, reduced) {
    if (serializedrestli === undefined || serializedrestli === "''") {
        serializedrestli = '';
    }
    if (strStartsWith(serializedrestli, constants_1.LIST_PREFIX)) {
        validateSuffix(serializedrestli, constants_1.LIST_SUFFIX);
        return decodeList(serializedrestli.substring(5, serializedrestli.length - 1), reduced);
    }
    else if (strStartsWith(serializedrestli, constants_1.OBJ_PREFIX)) {
        validateSuffix(serializedrestli, constants_1.OBJ_SUFFIX);
        return decodeObject(serializedrestli.substring(1, serializedrestli.length - 1), reduced);
    }
    else {
        return restliUnescape(serializedrestli, reduced);
    }
}
/**
 * @param {string} list e.g. 1,2,(k:v),3
 * @param {boolean} reduced
 * @returns {Array<*>}
 */
function decodeList(str, reduced = false) {
    const retList = [];
    let idx = 0;
    while (idx < str.length) {
        if (strStartsWith(str, constants_1.LIST_PREFIX, idx) || strStartsWith(str, constants_1.OBJ_PREFIX, idx)) {
            const rightBracketIdx = findLastRightBracket(str, idx);
            retList.push(internalDecode(str.substring(idx, rightBracketIdx + 1), reduced) // TODO type overload _decode so we don't need this cast
            );
            idx = rightBracketIdx + 2; // skip the next comma
            continue;
        }
        let endIdx = str.indexOf(',', idx);
        if (endIdx < 0)
            endIdx = str.length;
        retList.push(restliUnescape(str.substring(idx, endIdx), reduced));
        idx = endIdx + 1;
    }
    return retList;
}
/**
 * @param {string} str e.g. k1:v1,k2:List(1,2,3),k3:v3
 * @param {boolean} reduced
 * @returns {Object}
 */
function decodeObject(str, reduced = false) {
    const retObj = {};
    let idx = 0;
    while (idx < str.length) {
        const colonIdx = str.indexOf(':', idx);
        const key = restliUnescape(str.substring(idx, colonIdx), reduced);
        idx = colonIdx + 1;
        if (str.startsWith(constants_1.LIST_PREFIX, idx) || str.startsWith(constants_1.OBJ_PREFIX, idx)) {
            const rightBracketIdx = findLastRightBracket(str, idx);
            retObj[key] = internalDecode(str.substring(idx, rightBracketIdx + 1), reduced); // TODO type overload _decode so we don't need this cast
            idx = rightBracketIdx + 2; // skip the next comma
            continue;
        }
        let endIdx = str.indexOf(',', idx);
        if (endIdx < 0)
            endIdx = str.length;
        const value = restliUnescape(str.substring(idx, endIdx), reduced);
        retObj[key] = value;
        idx = endIdx + 1;
    }
    return retObj;
}
//# sourceMappingURL=decoder.js.map