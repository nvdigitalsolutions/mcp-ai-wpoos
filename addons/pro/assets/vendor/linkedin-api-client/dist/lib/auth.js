"use strict";
var __awaiter = (this && this.__awaiter) || function (thisArg, _arguments, P, generator) {
    function adopt(value) { return value instanceof P ? value : new P(function (resolve) { resolve(value); }); }
    return new (P || (P = Promise))(function (resolve, reject) {
        function fulfilled(value) { try { step(generator.next(value)); } catch (e) { reject(e); } }
        function rejected(value) { try { step(generator["throw"](value)); } catch (e) { reject(e); } }
        function step(result) { result.done ? resolve(result.value) : adopt(result.value).then(fulfilled, rejected); }
        step((generator = generator.apply(thisArg, _arguments || [])).next());
    });
};
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.AuthClient = void 0;
const axios_1 = __importDefault(require("axios"));
const constants_1 = require("./utils/constants");
const qs_1 = __importDefault(require("qs"));
const oauth_utils_1 = require("./utils/oauth-utils");
var TokenAuthType;
(function (TokenAuthType) {
    /** 2-legged application token */
    TokenAuthType["TWO_LEGGED"] = "2L";
    /** 3-legged member token */
    TokenAuthType["THREE_LEGGED"] = "3L";
    /** Enterprise member token */
    TokenAuthType["ENTERPRISE"] = "Enterprise_User";
})(TokenAuthType || (TokenAuthType = {}));
var TokenStatus;
(function (TokenStatus) {
    /** Token has been revoked */
    TokenStatus["REVOKED"] = "revoked";
    /** Token has expired */
    TokenStatus["EXPIRED"] = "expired";
    /** Token is active */
    TokenStatus["ACTIVE"] = "active";
})(TokenStatus || (TokenStatus = {}));
/**
 * A simple auth client for managing OAuth 2.0 authorization flows for LinkedIn APIs.
 */
class AuthClient {
    constructor(params) {
        this.clientId = params.clientId;
        this.clientSecret = params.clientSecret;
        this.redirectUrl = params.redirectUrl;
    }
    /**
     * Use client credential flow (2-legged OAuth) to retrieve a 2-legged access token for
     * accessing APIs that are not member-specific. Developer applications do not have the client
     * credential flow enabled by default.
     *
     * @returns A promise that resolves to the 2-legged access token details
     */
    getTwoLeggedAccessToken() {
        return __awaiter(this, void 0, void 0, function* () {
            const response = yield axios_1.default.request({
                method: constants_1.HTTP_METHODS.POST,
                url: `${constants_1.OAUTH_BASE_URL}/accessToken`,
                data: qs_1.default.stringify({
                    grant_type: 'client_credentials',
                    client_id: this.clientId,
                    client_secret: this.clientSecret
                }),
                headers: {
                    [constants_1.HEADERS.CONTENT_TYPE]: constants_1.CONTENT_TYPE.URL_ENCODED
                }
            });
            return response.data;
        });
    }
    /**
     * Generates the member authorization URL to direct members to. Once redirected, the member will be
     * presented with LinkedIn's OAuth consent page showing the OAuth scopes your application is requesting
     * on behalf of the user.
     *
     * @returns The member authorization URL
     */
    generateMemberAuthorizationUrl(
    /** An array of OAuth scopes (3-legged member permissions) your application is requesting on behalf of the user. */
    scopes, 
    /** An optional string that can be provided to test against CSRF attacks. */
    state = undefined) {
        return (0, oauth_utils_1.generateMemberAuthorizationUrl)({
            clientId: this.clientId,
            redirectUrl: this.redirectUrl,
            state,
            scopes
        });
    }
    /**
     * Exchanges an authorization code for a 3-legged access token. After member authorization, the browser redirects to the
     * provided redirect URL, setting the authorization code on the `code` query parameter.
     *
     * @returns a Promise that resolves to details of the 3-legged access token.
     */
    exchangeAuthCodeForAccessToken(
    /** The authorization code to exchange for an access token */
    code) {
        return __awaiter(this, void 0, void 0, function* () {
            const response = yield axios_1.default.request({
                method: constants_1.HTTP_METHODS.POST,
                url: `${constants_1.OAUTH_BASE_URL}/accessToken`,
                data: {
                    grant_type: 'authorization_code',
                    code,
                    client_id: this.clientId,
                    client_secret: this.clientSecret,
                    redirect_uri: this.redirectUrl
                },
                headers: {
                    [constants_1.HEADERS.CONTENT_TYPE]: constants_1.CONTENT_TYPE.URL_ENCODED
                }
            });
            return response.data;
        });
    }
    /**
     * Exchanges a refresh token for a new 3-legged access token. This allows access tokens
     * to be refreshed without having the member reauthorize your application.
     *
     * @returns a Promise that resolves to an object containing the details of the new access token
     * and refresh token
     */
    exchangeRefreshTokenForAccessToken(
    /** The refresh token to exchange for an access token. */
    refreshToken) {
        return __awaiter(this, void 0, void 0, function* () {
            const response = yield axios_1.default.request({
                method: constants_1.HTTP_METHODS.POST,
                url: `${constants_1.OAUTH_BASE_URL}/accessToken`,
                data: {
                    grant_type: 'refresh_token',
                    refresh_token: refreshToken,
                    client_id: this.clientId,
                    client_secret: this.clientSecret
                },
                headers: {
                    [constants_1.HEADERS.CONTENT_TYPE]: constants_1.CONTENT_TYPE.URL_ENCODED
                }
            });
            return response.data;
        });
    }
    /**
     * Introspect a 2-legged, 3-legged or Enterprise access token to get information on status,
     * expiry, and other details.
     *
     * @returns a Promise that resolves to the token introspection details.
     */
    introspectAccessToken(
    /** A 2-legged, 3-legged or Enterprise access token. */
    accessToken) {
        return __awaiter(this, void 0, void 0, function* () {
            return yield axios_1.default.request({
                method: constants_1.HTTP_METHODS.POST,
                url: `${constants_1.OAUTH_BASE_URL}/introspectToken`,
                data: {
                    client_id: this.clientId,
                    client_secret: this.clientSecret,
                    token: accessToken
                },
                headers: {
                    [constants_1.HEADERS.CONTENT_TYPE]: constants_1.CONTENT_TYPE.URL_ENCODED
                }
            });
        });
    }
}
exports.AuthClient = AuthClient;
//# sourceMappingURL=auth.js.map