"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.OBJ_SUFFIX = exports.OBJ_PREFIX = exports.LIST_SUFFIX = exports.LIST_PREFIX = exports.RESTLI_METHOD_TO_HTTP_METHOD_MAP = exports.RESTLI_METHODS = exports.HTTP_METHODS = exports.CONTENT_TYPE = exports.HEADERS = exports.VERSIONED_BASE_URL = exports.NON_VERSIONED_BASE_URL = exports.OAUTH_BASE_URL = void 0;
exports.OAUTH_BASE_URL = 'https://www.linkedin.com/oauth/v2';
exports.NON_VERSIONED_BASE_URL = 'https://api.linkedin.com/v2';
exports.VERSIONED_BASE_URL = 'https://api.linkedin.com/rest';
exports.HEADERS = {
    CONTENT_TYPE: 'Content-Type',
    CONNECTION: 'Connection',
    RESTLI_PROTOCOL_VERSION: 'X-RestLi-Protocol-Version',
    RESTLI_METHOD: 'X-RestLi-Method',
    CREATED_ENTITY_ID: 'x-restli-id',
    HTTP_METHOD_OVERRIDE: 'X-HTTP-Method-Override',
    LINKEDIN_VERSION: 'LinkedIn-Version',
    AUTHORIZATION: 'Authorization',
    USER_AGENT: 'user-agent'
};
exports.CONTENT_TYPE = {
    JSON: 'application/json',
    URL_ENCODED: 'application/x-www-form-urlencoded',
    MULTIPART_MIXED_WITH_BOUNDARY: (boundary) => `multipart/mixed; boundary=${boundary}`
};
exports.HTTP_METHODS = {
    GET: 'GET',
    POST: 'POST',
    PUT: 'PUT',
    DELETE: 'DELETE'
};
exports.RESTLI_METHODS = {
    GET: 'GET',
    BATCH_GET: 'BATCH_GET',
    GET_ALL: 'GET_ALL',
    UPDATE: 'UPDATE',
    BATCH_UPDATE: 'BATCH_UPDATE',
    PARTIAL_UPDATE: 'PARTIAL_UPDATE',
    BATCH_PARTIAL_UPDATE: 'BATCH_PARTIAL_UPDATE',
    CREATE: 'CREATE',
    BATCH_CREATE: 'BATCH_CREATE',
    DELETE: 'DELETE',
    BATCH_DELETE: 'BATCH_DELETE',
    FINDER: 'FINDER',
    BATCH_FINDER: 'BATCH_FINDER',
    ACTION: 'ACTION'
};
exports.RESTLI_METHOD_TO_HTTP_METHOD_MAP = {
    GET: 'GET',
    BATCH_GET: 'GET',
    GET_ALL: 'GET',
    FINDER: 'GET',
    BATCH_FINDER: 'GET',
    UPDATE: 'PUT',
    BATCH_UPDATE: 'PUT',
    CREATE: 'POST',
    BATCH_CREATE: 'POST',
    PARTIAL_UPDATE: 'POST',
    BATCH_PARTIAL_UPDATE: 'POST',
    ACTION: 'POST',
    DELETE: 'DELETE',
    BATCH_DELETE: 'DELETE'
};
/**
 * Rest.li protocol encoding constants
 */
exports.LIST_PREFIX = 'List(';
exports.LIST_SUFFIX = ')';
exports.OBJ_PREFIX = '(';
exports.OBJ_SUFFIX = ')';
//# sourceMappingURL=constants.js.map