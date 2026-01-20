"use strict";
var __rest = (this && this.__rest) || function (s, e) {
    var t = {};
    for (var p in s) if (Object.prototype.hasOwnProperty.call(s, p) && e.indexOf(p) < 0)
        t[p] = s[p];
    if (s != null && typeof Object.getOwnPropertySymbols === "function")
        for (var i = 0, p = Object.getOwnPropertySymbols(s); i < p.length; i++) {
            if (e.indexOf(p[i]) < 0 && Object.prototype.propertyIsEnumerable.call(s, p[i]))
                t[p[i]] = s[p[i]];
        }
    return t;
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.encodeQueryParamsForGetRequests = exports.getCreatedEntityId = void 0;
const decoder_1 = require("./decoder");
const encoder_1 = require("./encoder");
/**
 * Miscellaneous Rest.li Utils
 */
const constants_1 = require("./constants");
/**
 * Returns the created entity id, provided the raw response. By default, the created
 * entity id will be the decoded value.
 */
function getCreatedEntityId(
/** The raw response object from a Rest.li create request. */
response, 
/** Flag whether to decode the created entity id. The entity is decoded by default (e.g. "urn:li:myEntity:123"), otherwise the raw, reduced encoded value is returned (e.g. "urn%3A%li%3AmyEntity%3A123"). */
decode = true) {
    const reducedEncodedEntityId = response === null || response === void 0 ? void 0 : response.headers[constants_1.HEADERS.CREATED_ENTITY_ID];
    return decode ? (0, decoder_1.reducedDecode)(reducedEncodedEntityId) : reducedEncodedEntityId;
}
exports.getCreatedEntityId = getCreatedEntityId;
/**
 * This wrapper function on top of encoder.paramEncode is needed specifically to handle the
 * "fields" query parameter for field projections. Although Rest.li protocol version 2.0.0 should
 * have supported a query param string like "?fields=List(id,firstName,lastName)" it still requires
 * the Rest.li protocol version 1.0.0 format of "?fields=id,firstName,lastName". Thus, if "fields"
 * is provided as a query parameter for HTTP GET requests, it should not be encoded like all the other
 * parameters.
 */
function encodeQueryParamsForGetRequests(queryParams) {
    const { fields } = queryParams, otherQueryParams = __rest(queryParams, ["fields"]);
    let encodedQueryParamString = (0, encoder_1.paramEncode)(otherQueryParams);
    if (fields) {
        encodedQueryParamString = [encodedQueryParamString, `fields=${fields}`].join('&');
    }
    return encodedQueryParamString;
}
exports.encodeQueryParamsForGetRequests = encodeQueryParamsForGetRequests;
//# sourceMappingURL=restli-utils.js.map