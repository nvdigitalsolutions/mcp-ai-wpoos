"use strict";
/**
 * Utilities related to working with LinkedIn's APIs
 */
Object.defineProperty(exports, "__esModule", { value: true });
exports.getRestliRequestHeaders = exports.buildRestliUrl = void 0;
const constants_1 = require("./constants");
const encoder_1 = require("./encoder");
const package_json_1 = require("../../package.json");
/**
 * Method to build the URL (not including query parameters) for a REST-based API call to LinkedIn
 */
function buildRestliUrl(resourcePath, pathKeys = null, versionString) {
    const baseUrl = versionString ? constants_1.VERSIONED_BASE_URL : constants_1.NON_VERSIONED_BASE_URL;
    pathKeys = pathKeys || {};
    const PLACEHOLDER_REGEX = /\{\w+\}/g;
    // Validate resourcePath and pathKeys
    const placeholderMatches = resourcePath.match(PLACEHOLDER_REGEX);
    const numPlaceholders = placeholderMatches ? placeholderMatches.length : 0;
    if (numPlaceholders !== Object.keys(pathKeys).length) {
        throw new Error(`The number of placeholders in the resourcePath (${resourcePath}) does not match the number of entries in the pathKeys argument`);
    }
    const resourcePathWithKeys = resourcePath.replace(PLACEHOLDER_REGEX, match => {
        // match looks like "{id}", so remove the curly braces to get the placeholder
        const placeholder = match.substring(1, match.length - 1);
        if (Object.prototype.hasOwnProperty.call(pathKeys, placeholder)) {
            return (0, encoder_1.encode)(pathKeys[placeholder]);
        }
        else {
            throw new Error(`The placeholder ${match} was found in resourcePath, which does not have a corresponding entry in pathKeys`);
        }
    });
    return `${baseUrl}${resourcePathWithKeys}`;
}
exports.buildRestliUrl = buildRestliUrl;
function getRestliRequestHeaders({ restliMethodType, accessToken, versionString, httpMethodOverride, contentType = 'application/json' }) {
    const headers = {
        [constants_1.HEADERS.CONNECTION]: 'Keep-Alive',
        [constants_1.HEADERS.RESTLI_PROTOCOL_VERSION]: '2.0.0',
        [constants_1.HEADERS.RESTLI_METHOD]: restliMethodType.toLowerCase(),
        [constants_1.HEADERS.AUTHORIZATION]: `Bearer ${accessToken}`,
        [constants_1.HEADERS.CONTENT_TYPE]: contentType,
        [constants_1.HEADERS.USER_AGENT]: `linkedin-api-js-client/${package_json_1.version}`
    };
    if (versionString) {
        headers[constants_1.HEADERS.LINKEDIN_VERSION] = versionString;
    }
    if (httpMethodOverride) {
        headers[constants_1.HEADERS.HTTP_METHOD_OVERRIDE] = httpMethodOverride.toUpperCase();
    }
    return headers;
}
exports.getRestliRequestHeaders = getRestliRequestHeaders;
//# sourceMappingURL=api-utils.js.map