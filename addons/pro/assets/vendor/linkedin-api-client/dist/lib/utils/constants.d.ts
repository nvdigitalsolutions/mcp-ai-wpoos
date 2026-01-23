export declare const OAUTH_BASE_URL = "https://www.linkedin.com/oauth/v2";
export declare const NON_VERSIONED_BASE_URL = "https://api.linkedin.com/v2";
export declare const VERSIONED_BASE_URL = "https://api.linkedin.com/rest";
export declare const HEADERS: {
    CONTENT_TYPE: string;
    CONNECTION: string;
    RESTLI_PROTOCOL_VERSION: string;
    RESTLI_METHOD: string;
    CREATED_ENTITY_ID: string;
    HTTP_METHOD_OVERRIDE: string;
    LINKEDIN_VERSION: string;
    AUTHORIZATION: string;
    USER_AGENT: string;
};
export declare const CONTENT_TYPE: {
    JSON: string;
    URL_ENCODED: string;
    MULTIPART_MIXED_WITH_BOUNDARY: (boundary: string) => string;
};
export declare const HTTP_METHODS: {
    GET: string;
    POST: string;
    PUT: string;
    DELETE: string;
};
export declare const RESTLI_METHODS: {
    GET: string;
    BATCH_GET: string;
    GET_ALL: string;
    UPDATE: string;
    BATCH_UPDATE: string;
    PARTIAL_UPDATE: string;
    BATCH_PARTIAL_UPDATE: string;
    CREATE: string;
    BATCH_CREATE: string;
    DELETE: string;
    BATCH_DELETE: string;
    FINDER: string;
    BATCH_FINDER: string;
    ACTION: string;
};
export declare const RESTLI_METHOD_TO_HTTP_METHOD_MAP: {
    GET: string;
    BATCH_GET: string;
    GET_ALL: string;
    FINDER: string;
    BATCH_FINDER: string;
    UPDATE: string;
    BATCH_UPDATE: string;
    CREATE: string;
    BATCH_CREATE: string;
    PARTIAL_UPDATE: string;
    BATCH_PARTIAL_UPDATE: string;
    ACTION: string;
    DELETE: string;
    BATCH_DELETE: string;
};
/**
 * Rest.li protocol encoding constants
 */
export declare const LIST_PREFIX = "List(";
export declare const LIST_SUFFIX = ")";
export declare const OBJ_PREFIX = "(";
export declare const OBJ_SUFFIX = ")";
