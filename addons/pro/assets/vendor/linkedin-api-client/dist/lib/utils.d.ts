import { decode, paramDecode, reducedDecode } from './utils/decoder';
export declare const utils: {
    decode: typeof decode;
    paramDecode: typeof paramDecode;
    reducedDecode: typeof reducedDecode;
    encode(value: any): string;
    reducedEncode(value: any): string;
    paramEncode(json: unknown): string;
    OAUTH_BASE_URL: "https://www.linkedin.com/oauth/v2";
    NON_VERSIONED_BASE_URL: "https://api.linkedin.com/v2";
    VERSIONED_BASE_URL: "https://api.linkedin.com/rest";
    HEADERS: {
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
    CONTENT_TYPE: {
        JSON: string;
        URL_ENCODED: string;
        MULTIPART_MIXED_WITH_BOUNDARY: (boundary: string) => string;
    };
    HTTP_METHODS: {
        GET: string;
        POST: string;
        PUT: string;
        DELETE: string;
    };
    RESTLI_METHODS: {
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
    RESTLI_METHOD_TO_HTTP_METHOD_MAP: {
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
    LIST_PREFIX: "List(";
    LIST_SUFFIX: ")";
    OBJ_PREFIX: "(";
    OBJ_SUFFIX: ")";
    isQueryTunnelingRequired(encodedQueryParamString: string): boolean;
    maybeApplyQueryTunnelingToRequestsWithoutBody({ encodedQueryParamString, urlPath, originalRestliMethod, accessToken, versionString, additionalConfig }: {
        encodedQueryParamString: any;
        urlPath: any;
        originalRestliMethod: any;
        accessToken: any;
        versionString: any;
        additionalConfig?: {};
    }): any;
    maybeApplyQueryTunnelingToRequestsWithBody({ encodedQueryParamString, urlPath, originalRestliMethod, originalJSONRequestBody, accessToken, versionString, additionalConfig }: {
        encodedQueryParamString: any;
        urlPath: any;
        originalRestliMethod: any;
        originalJSONRequestBody: any;
        accessToken: any;
        versionString: any;
        additionalConfig?: {};
    }): any;
    getPatchObject(original: any, modified: any): {
        patch: any;
    };
    createUrnFromAttrs(type: string, id: string | number, namespace?: string): string;
    buildRestliUrl(resourcePath: string, pathKeys?: Record<string, any>, versionString?: string): string;
    getRestliRequestHeaders({ restliMethodType, accessToken, versionString, httpMethodOverride, contentType }: {
        restliMethodType: string;
        accessToken: string;
        versionString?: string;
        httpMethodOverride?: string;
        contentType?: string;
    }): Record<string, any>;
    getCreatedEntityId(response: import("axios").AxiosResponse<any, any>, decode?: boolean): string | string[] | Record<string, string>;
    encodeQueryParamsForGetRequests(queryParams: Record<string, any>): string;
    generateMemberAuthorizationUrl(params: {
        clientId: string;
        redirectUrl: string;
        scopes: string[];
        state?: string;
    }): string;
};
