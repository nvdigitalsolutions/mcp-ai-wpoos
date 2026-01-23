"use strict";
/**
 * Utils related to query tunneling
 */
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.maybeApplyQueryTunnelingToRequestsWithBody = exports.maybeApplyQueryTunnelingToRequestsWithoutBody = exports.isQueryTunnelingRequired = void 0;
const lodash_1 = __importDefault(require("lodash"));
const api_utils_1 = require("./api-utils");
const constants_1 = require("./constants");
const MAX_QUERY_STRING_LENGTH = 4000; // 4KB max length
function isQueryTunnelingRequired(encodedQueryParamString) {
    return encodedQueryParamString && encodedQueryParamString.length > MAX_QUERY_STRING_LENGTH;
}
exports.isQueryTunnelingRequired = isQueryTunnelingRequired;
function maybeApplyQueryTunnelingToRequestsWithoutBody({ encodedQueryParamString, urlPath, originalRestliMethod, accessToken, versionString, additionalConfig = {} }) {
    let requestConfig;
    if (isQueryTunnelingRequired(encodedQueryParamString)) {
        requestConfig = lodash_1.default.merge({
            method: constants_1.HTTP_METHODS.POST,
            url: urlPath,
            data: encodedQueryParamString,
            headers: (0, api_utils_1.getRestliRequestHeaders)({
                contentType: constants_1.CONTENT_TYPE.URL_ENCODED,
                httpMethodOverride: constants_1.HTTP_METHODS.GET,
                restliMethodType: originalRestliMethod,
                accessToken,
                versionString
            })
        }, additionalConfig);
    }
    else {
        const url = encodedQueryParamString ? `${urlPath}?${encodedQueryParamString}` : urlPath;
        requestConfig = lodash_1.default.merge({
            method: constants_1.RESTLI_METHOD_TO_HTTP_METHOD_MAP[originalRestliMethod],
            url,
            headers: (0, api_utils_1.getRestliRequestHeaders)({
                restliMethodType: originalRestliMethod,
                accessToken,
                versionString
            })
        }, additionalConfig);
    }
    return requestConfig;
}
exports.maybeApplyQueryTunnelingToRequestsWithoutBody = maybeApplyQueryTunnelingToRequestsWithoutBody;
function maybeApplyQueryTunnelingToRequestsWithBody({ encodedQueryParamString, urlPath, originalRestliMethod, originalJSONRequestBody, accessToken, versionString, additionalConfig = {} }) {
    let requestConfig;
    const originalHttpMethod = constants_1.RESTLI_METHOD_TO_HTTP_METHOD_MAP[originalRestliMethod];
    if (isQueryTunnelingRequired(encodedQueryParamString)) {
        /**
         * Generate a boundary string that is not present at all in the raw request body
         */
        let boundary = generateRandomString();
        const rawRequestBodyString = encodedQueryParamString + JSON.stringify(originalJSONRequestBody);
        while (rawRequestBodyString.includes(boundary)) {
            boundary = generateRandomString();
        }
        // Generate the multipart request body
        const multipartRequestBody = `--${boundary}\r\n` +
            `${constants_1.HEADERS.CONTENT_TYPE}: ${constants_1.CONTENT_TYPE.URL_ENCODED}\r\n\r\n` +
            `${encodedQueryParamString}\r\n` +
            `--${boundary}\r\n` +
            `${constants_1.HEADERS.CONTENT_TYPE}: ${constants_1.CONTENT_TYPE.JSON}\r\n\r\n` +
            `${JSON.stringify(originalJSONRequestBody)}\r\n` +
            `--${boundary}--`;
        requestConfig = lodash_1.default.merge({
            method: constants_1.HTTP_METHODS.POST,
            url: urlPath,
            data: multipartRequestBody,
            headers: (0, api_utils_1.getRestliRequestHeaders)({
                contentType: constants_1.CONTENT_TYPE.MULTIPART_MIXED_WITH_BOUNDARY(boundary),
                httpMethodOverride: originalHttpMethod,
                restliMethodType: originalRestliMethod,
                accessToken,
                versionString
            }),
            additionalConfig
        });
    }
    else {
        const url = encodedQueryParamString ? `${urlPath}?${encodedQueryParamString}` : urlPath;
        requestConfig = lodash_1.default.merge({
            method: originalHttpMethod,
            url,
            headers: (0, api_utils_1.getRestliRequestHeaders)({
                restliMethodType: originalRestliMethod,
                accessToken,
                versionString
            }),
            data: originalJSONRequestBody
        }, additionalConfig);
    }
    return requestConfig;
}
exports.maybeApplyQueryTunnelingToRequestsWithBody = maybeApplyQueryTunnelingToRequestsWithBody;
function generateRandomString() {
    return Math.random().toString(36).substring(2);
}
//# sourceMappingURL=query-tunneling.js.map