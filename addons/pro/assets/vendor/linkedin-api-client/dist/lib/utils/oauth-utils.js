"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.generateMemberAuthorizationUrl = void 0;
const constants_1 = require("./constants");
const qs_1 = __importDefault(require("qs"));
/**
 * Generates the member authorization URL to redirect users to in order to
 * authorize the requested scopes for an application.
 */
function generateMemberAuthorizationUrl(params) {
    var _a;
    if (!params.clientId) {
        throw new Error('The client ID must be specified.');
    }
    if (!params.redirectUrl) {
        throw new Error('The OAuth 2.0 redirect URL must be specified.');
    }
    if (!((_a = params.scopes) === null || _a === void 0 ? void 0 : _a.length)) {
        throw new Error('At least one scope must be specified');
    }
    const queryParamString = qs_1.default.stringify({
        response_type: 'code',
        client_id: params.clientId,
        redirect_uri: params.redirectUrl,
        scope: params.scopes.join(','),
        state: params.state
    }, { encode: false });
    return `${constants_1.OAUTH_BASE_URL}/authorization?${queryParamString}`;
}
exports.generateMemberAuthorizationUrl = generateMemberAuthorizationUrl;
//# sourceMappingURL=oauth-utils.js.map