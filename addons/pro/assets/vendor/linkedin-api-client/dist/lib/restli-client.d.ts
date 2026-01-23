import { AxiosInstance, AxiosRequestConfig, AxiosResponse, CreateAxiosDefaults } from 'axios';
/**
 * Type Definitions
 */
export interface LIRestliRequestOptionsBase {
    /**
     * The resource path after the base URL, beginning with a forward slash. If the path contains keys,
     * add curly-brace placeholders for the keys and specify the path key-value map in the `pathKeys` argument.
     */
    resourcePath: string;
    /** The access token that should provide the application access to the specified API */
    accessToken: string;
    /**
     * If there are path keys that are part of the `resourcePath` argument, the key placeholders must be specified in
     * the provided `pathKeys` map. The path key values can be strings, numbers, or objects, and these
     * will be properly encoded.
     */
    pathKeys?: Record<string, any>;
    /** A map of query parameters, whose keys/values should not be encoded */
    queryParams?: Record<string, any>;
    /** optional version string of the format "YYYYMM" or "YYYYMM.RR". If specified, the version header will be passed and the request will use the versioned APIs base URL. */
    versionString?: string | null;
    /** optional Axios request config object that will be merged into the request config. This will override any properties the client method sets, which may cause unexpected errors. Query params should not be passed here--instead they should be set in the queryParams property for proper Rest.li encoding. */
    additionalConfig?: AxiosRequestConfig;
}
/**
 * A Rest.li entity
 */
export type RestliEntity = Record<string, any>;
/**
 * A Rest.li entity id or key. The id can be a string, number, or complex key. The id should not be encoded, as the client method will perform the correct encoding.
 */
export type RestliEntityId = string | number | Record<string, any>;
/**
 * An encoded entity id
 */
export type EncodedEntityId = string | number;
/**
 * Paging metadata object
 */
export interface PagingObject {
    /** Start index of returned entities list (zero-based index) */
    start: number;
    /** Number of entities returned */
    count: number;
    /** Total number of entities */
    total?: number;
}
/**
 * Request Options Interfaces
 */
export interface LIGetRequestOptions extends LIRestliRequestOptionsBase {
    /** The id or key of the entity to fetch. For simple resources, this should not be specified. */
    id?: RestliEntityId | null;
}
export interface LIBatchGetRequestOptions extends LIRestliRequestOptionsBase {
    /** The list of ids to fetch on the resource. */
    ids: RestliEntityId[];
}
export interface LIGetAllRequestOptions extends LIRestliRequestOptionsBase {
}
export interface LICreateRequestOptions extends LIRestliRequestOptionsBase {
    /** A JSON serialized value of the entity to create */
    entity: RestliEntity;
}
export interface LIBatchCreateRequestOptions extends LIRestliRequestOptionsBase {
    /** A list of JSON serialized entity values to create */
    entities: RestliEntity[];
}
export interface LIPartialUpdateRequestOptions extends LIRestliRequestOptionsBase {
    /** The id or key of the entity to update. For simple resources, this is not specified. */
    id?: RestliEntityId | null;
    /** The JSON-serialized value of the entity with only the modified fields present. If specified, this will be directly sent as the patch object. */
    patchSetObject?: RestliEntity;
    /** The JSON-serialized value of the original entity. If specified and patchSetObject is not provided, this will be used in conjunction with modifiedEntity to compute the patch object. */
    originalEntity?: RestliEntity;
    /** The JSON-serialized value of the modified entity. If specified and patchSetObject is not provided, this will be used in conjunction with originalEntity to compute the patch object. */
    modifiedEntity?: RestliEntity;
}
export interface LIBatchPartialUpdateRequestOptions extends LIRestliRequestOptionsBase {
    /** A list entity ids to update. */
    ids: RestliEntityId[];
    /** A list of JSON-serialized values of the entities with only the modified fields present. If specified, this will be directly sent as the patch object. */
    patchSetObjects?: RestliEntity[];
    /** A list of JSON-serialized values of the original entities. If specified and patchSetObjects is not provided, this will be used in conjunction with modifiedEntities to compute patch object for each entity. */
    originalEntities?: RestliEntity[];
    /** A list of JSON-serialized values of the modified entities. If specified and patchSetObjects is not provided, this will be used in conjunction with originalEntities to compute the patch object for each entity. */
    modifiedEntities?: RestliEntity[];
}
export interface LIUpdateRequestOptions extends LIRestliRequestOptionsBase {
    /** The id or key of the entity to update. For simple resources, this is not specified. */
    id?: RestliEntityId | null;
    /** The JSON-serialized value of the entity with updated values. */
    entity: RestliEntity;
}
export interface LIBatchUpdateRequestOptions extends LIRestliRequestOptionsBase {
    /** The list of entity ids to update. This should match with the corresponding entity object in the entities field. */
    ids: RestliEntityId[];
    /** The list of JSON-serialized values of entities with updated values. */
    entities: RestliEntity[];
}
export interface LIDeleteRequestOptions extends LIRestliRequestOptionsBase {
    /** The id or key of the entity to delete. For simple resources, this is not specified. */
    id?: RestliEntityId | null;
}
export interface LIBatchDeleteRequestOptions extends LIRestliRequestOptionsBase {
    /** A list of entity ids to delete. */
    ids: RestliEntityId[];
}
export interface LIFinderRequestOptions extends LIRestliRequestOptionsBase {
    /** The Rest.li finder name */
    finderName: string;
}
export interface BatchFinderCriteria {
    name: string;
    value: Array<Record<string, any>>;
}
export interface LIBatchFinderRequestOptions extends LIRestliRequestOptionsBase {
    /**
     * The Rest.li batch finder name (the value of the "bq" parameter). This will be added to the request
     * query parameters.
     */
    finderName: string;
    finderCriteria: BatchFinderCriteria;
}
export interface LIActionRequestOptions extends LIRestliRequestOptionsBase {
    /** The Rest.li action name */
    actionName: string;
    /** The request body data to pass to the action. */
    data?: Record<string, any> | null;
}
/**
 * Response Interfaces
 */
export interface LIGetResponse extends AxiosResponse {
    /** The entity that was fetched */
    data: RestliEntity;
}
export interface LIBatchGetResponse extends AxiosResponse {
    data: {
        /** A map containing entities that could not be successfully fetched and their associated error responses */
        errors: Record<EncodedEntityId, any>;
        /** A map of entities that were successfully retrieved */
        results: Record<EncodedEntityId, RestliEntity>;
        /** A map of entities and the corresponding status code */
        statuses?: Record<EncodedEntityId, number>;
    };
}
export interface LIGetAllResponse extends AxiosResponse {
    data: {
        /** List of entities */
        elements: RestliEntity[];
        paging?: PagingObject;
    };
}
export interface LICreateResponse extends AxiosResponse {
    /** The decoded, created entity id */
    createdEntityId: string | string[] | Record<string, string>;
}
export interface LIBatchCreateResponse extends AxiosResponse {
    data: {
        /** A list of entity creation response data in the same order as the entities provided in the batch create request. */
        elements: Array<{
            /** The response status when creating the entity. */
            status: number;
            /** The id of the newly-created entity, if creation was successful. */
            id?: string;
            /** Error details when creating an entity, if creation failed. */
            error?: any;
        }>;
    };
}
export interface LIPartialUpdateResponse extends AxiosResponse {
}
export interface LIBatchPartialUpdateResponse extends AxiosResponse {
    data: {
        /** A map of entities and their corresponding response status. */
        results: Record<EncodedEntityId, {
            status: number;
        }>;
        /** A map where the keys are the encoded entity ids that failed to be updated, and the values include the error response. */
        errors: Record<EncodedEntityId, any>;
    };
}
export interface LIUpdateResponse extends AxiosResponse {
}
export interface LIBatchUpdateResponse extends AxiosResponse {
    data: {
        /** A map where the keys are the encoded entity ids that were successfully updated, and the values are the update results, which include the status code. */
        results: Record<EncodedEntityId, {
            status: number;
        }>;
        /** A map where the keys are the encoded entity ids that failed to be updated, and the values include the error response. */
        errors: Record<EncodedEntityId, any>;
    };
}
export interface LIDeleteResponse extends AxiosResponse {
}
export interface LIBatchDeleteResponse extends AxiosResponse {
    data: {
        /** A map where the keys are the encoded entity ids that were successfully deleted, and the values are the delete results, which include the status code. */
        results: Record<EncodedEntityId, {
            status: number;
        }>;
        /** A map where the keys are the encoded entity ids that failed to be deleted, and the values include the error response. */
        errors: Record<EncodedEntityId, any>;
    };
}
export interface LIFinderResponse extends AxiosResponse {
    data: {
        /** An array of entities found based on the search criteria */
        elements: RestliEntity[];
        paging?: PagingObject;
    };
}
export interface LIBatchFinderResponse extends AxiosResponse {
    data: {
        /** An array of finder search results in the same order as the array of search criteria provided to the batch finder. */
        elements: Array<{
            /** An array of entities found based on the corresponding search critieria. */
            elements: RestliEntity[];
            paging?: PagingObject;
            metadata?: any;
            error?: any;
            /** Flag indicating whether the finder request encountered an error. */
            isError?: boolean;
        }>;
    };
}
export interface LIActionResponse extends AxiosResponse {
    data: {
        /** The action response value. */
        value: boolean | string | number | Record<string, any>;
    };
}
export declare class RestliClient {
    #private;
    axiosInstance: AxiosInstance;
    constructor(config?: CreateAxiosDefaults);
    /**
     * Set debug logging parameters for the client.
     */
    setDebugParams({ 
    /** Flag whether to enable debug logging of request responses */
    enabled, 
    /** Flag whether to log successful responses */
    logSuccessResponses }: {
        enabled?: boolean;
        logSuccessResponses?: boolean;
    }): void;
    /**
     * Makes a Rest.li GET request to fetch the specified entity on a resource. This method
     * will perform query tunneling if necessary.
     *
     * @example
     * ```ts
     * client.get({
     *   resourcePath: '/adAccounts/{id}',
     *   pathKeys: {
     *     id: 123
     *   },
     *   queryParams: {
     *     fields: 'id,name'
     *   },
     *   accessToken: 'ABC123',
     *   versionString: '202210'
     * }).then(response => {
     *   const entity = response.data;
     * });
     * ```
     *
     * @returns a Promise that resolves to the response object containing the entity.
     */
    get({ resourcePath, accessToken, pathKeys, queryParams, versionString, additionalConfig }: LIGetRequestOptions): Promise<LIGetResponse>;
    /**
     * Makes a Rest.li BATCH_GET request to fetch multiple entities on a resource. This method
     * will perform query tunneling if necessary.
     *
     * @example
     * ```ts
     * client.batchGet({
     *   resourcePath: '/adCampaignGroups',
     *   ids: [123, 456, 789],
     *   accessToken: 'ABC123',
     *   versionString: '202210'
     * }).then(response => {
     *   const entities = response.data.results;
     * })
     * ```
     */
    batchGet({ resourcePath, ids, pathKeys, queryParams, versionString, accessToken, additionalConfig }: LIBatchGetRequestOptions): Promise<LIBatchGetResponse>;
    /**
     * Makes a Rest.li GET_ALL request to fetch all entities on a resource. This method
     * will perform query tunneling if necessary.
     *
     * @example
     * ```ts
     * client.getAll({
     *   resourcePath: '/fieldsOfStudy',
     *   queryParams: {
     *     start: 0,
     *     count: 15
     *   },
     *   accessToken: 'ABC123'
     * }).then(response => {
     *   const entities = response.data.elements;
     * })
     * ```
     */
    getAll({ resourcePath, accessToken, pathKeys, queryParams, versionString, additionalConfig }: LIGetAllRequestOptions): Promise<LIGetAllResponse>;
    /**
     * Makes a Rest.li FINDER request to find entities by some specified criteria. This method
     * will perform query tunneling if necessary.
     *
     * @example
     * ```ts
     * restliClient.finder({
     *   resourcePath: '/adAccounts',
     *   finderName: 'search',
     *   queryParams: {
     *     search: {
     *       status: {
     *         values: ['DRAFT', 'ACTIVE', 'REMOVED']
     *       }
     *     }
     *   },
     *   accessToken: 'ABC123',
     *   versionString: '202210'
     * }).then(response => {
     *   const elements = response.data.elements;
     *   const total = response.data.paging.total;
     * });
     * ```
     */
    finder({ resourcePath, finderName, pathKeys, queryParams, versionString, accessToken, additionalConfig }: LIFinderRequestOptions): Promise<LIFinderResponse>;
    /**
     * Makes a Rest.li BATCH_FINDER request to find entities by multiple sets of
     * criteria. This method will perform query tunneling if necessary.
     *
     * @example
     * ```ts
     * restliClient.batchFinder({
     *   resourcePath: '/organizationAuthorizations',
     *   finderName: 'authorizationActionsAndImpersonator',
     *   finderCriteria: {
     *     name: 'authorizationActions',
     *     value: [
     *       {
     *         'OrganizationRoleAuthorizationAction': {
     *           actionType: 'ADMINISTRATOR_READ'
     *         }
     *       },
     *       {
     *          'OrganizationContentAuthorizationAction': {
     *           actionType: 'ORGANIC_SHARE_DELETE'
     *         }
     *       }
     *     ]
     *   },
     *   accessToken: 'ABC123',
     *   versionString: '202210'
     * }).then(response => {
     *   const allFinderResults = response.data.elements;
     * });
     * ```
     */
    batchFinder({ resourcePath, finderName, finderCriteria, pathKeys, queryParams, versionString, accessToken, additionalConfig }: LIBatchFinderRequestOptions): Promise<LIBatchFinderResponse>;
    /**
     * Makes a Rest.li CREATE request to create a new entity on the resource.
     *
     * @example
     * ```ts
     * client.create({
     *   resourcePath: '/adAccountsV2',
     *   entity: {
     *     name: 'Test Ad Account',
     *     type: 'BUSINESS',
     *     test: true
     *   },
     *   accessToken: 'ABC123'
     * }).then(response => {
     *   const createdId = response.createdEntityId;
     * })
     * ```
     */
    create({ resourcePath, entity, pathKeys, queryParams, versionString, accessToken, additionalConfig }: LICreateRequestOptions): Promise<LICreateResponse>;
    /**
     * Makes a Rest.li BATCH_CREATE request to create multiple entities in
     * a single call.
     *
     * @example
     * ```ts
     * client.batchCreate({
     *   resourcePath: '/adCampaignGroups',
     *   entities: [
     *     {
     *       account: 'urn:li:sponsoredAccount:111',
     *       name: 'CampaignGroupTest1',
     *       status: 'DRAFT'
     *     },
     *     {
     *       account: 'urn:li:sponsoredAccount:222',
     *       name: 'CampaignGroupTest2',
     *       status: 'DRAFT'
     *     }
     *   ],
     *   versionString: '202209',
     *   accessToken: 'ABC123'
     * }).then(response => {
     *   const createdElementsInfo = response.data.elements;
     * });
     * ```
     */
    batchCreate({ resourcePath, entities, pathKeys, queryParams, versionString, accessToken, additionalConfig }: LIBatchCreateRequestOptions): Promise<LIBatchCreateResponse>;
    /**
     * Makes a Rest.li PARTIAL_UPDATE request to update part of an entity. One can either
     * pass the full original and modified entity objects, with the method computing the correct
     * patch object, or one can directly pass the patch object to send in the request.
     *
     * When an entity has nested fields that can be modified, passing in the original and modified
     * entities may produce a complex patch object that is a technically correct format for the Rest.li
     * framework, but may not be supported for most LinkedIn APIs which mainly support partial
     * update of only top-level fields on an entity. In these cases it is better to specify `patchSetObject`
     * directly.
     *
     * This method will perform query tunneling if necessary.
     *
     * @example
     * ```ts
     * client.partialUpdate({
     *   resourcePath: '/adAccounts/{id}',
     *   pathKeys: {
     *     id: 123
     *   },
     *   patchSetObject: {
     *     name: 'TestAdAccountModified',
     *     reference: 'urn:li:organization:456'
     *   },
     *   versionString: '202209',
     *   accessToken: 'ABC123'
     * }).then(response => {
     *   ...
     * });
     * ```
     */
    partialUpdate({ resourcePath, patchSetObject, originalEntity, modifiedEntity, pathKeys, queryParams, versionString, accessToken, additionalConfig }: LIPartialUpdateRequestOptions): Promise<LIPartialUpdateResponse>;
    /**
     * Makes a Rest.li BATCH_PARTIAL_UPDATE request to partially update multiple entites at
     * once. This method will perform query tunneling if necessary.
     *
     * @example
     * ```ts
     * client.batchPartialUpdate({
     *   resourcePath: '/adCampaignGroups',
     *   ids: [123, 456],
     *   patchSetObjects: [
     *     { status: 'ACTIVE' },
     *     {
     *       runSchedule: {
     *         start: 1678029270721,
     *         end: 1679029270721
     *       }
     *     }
     *   ],
     *   versionString: '202209',
     *   accessToken: 'ABC123'
     * }).then(response => {
     *   const results = response.data.results;
     * })
     * ```
     */
    batchPartialUpdate({ resourcePath, ids, originalEntities, modifiedEntities, patchSetObjects, pathKeys, queryParams, versionString, accessToken, additionalConfig }: LIBatchPartialUpdateRequestOptions): Promise<LIBatchPartialUpdateResponse>;
    /**
     * Makes a Rest.li UPDATE request to update an entity (overwriting the entire entity).
     * This method will perform query tunneling if necessary.
     *
     * @example
     * ```ts
     * client.update({
     *   resourcePath: '/adAccountUsers/{accountUserKey}',
     *   pathKeys: {
     *     accountUserKey: {
     *       account: 'urn:li:sponsoredAccount:123',
     *       user: 'urn:li:person:foobar'
     *     }
     *   },
     *   entity: {
     *     account: 'urn:li:sponsoredAccount:123',
     *     user: 'urn:li:person:foobar',
     *     role: 'VIEWER'
     *   },
     *   versionString: '202209',
     *   accessToken: 'ABC123'
     * }).then(response => {
     *   ...
     * });
     * ```
     */
    update({ resourcePath, entity, pathKeys, queryParams, versionString, accessToken, additionalConfig }: LIUpdateRequestOptions): Promise<LIUpdateResponse>;
    /**
     * Makes a Rest.li BATCH_UPDATE request to update multiple entities in a single call.
     * This method will perform query tunneling if necessary.
     *
     * @example
     * ```ts
     * client.batchUpdate({
     *   resourcePath: '/campaignConversions',
     *   ids: [
     *     { campaign: 'urn:li:sponsoredCampaign:123', conversion: 'urn:lla:llaPartnerConversion:456' },
     *     { campaign: 'urn:li:sponsoredCampaign:123', conversion: 'urn:lla:llaPartnerConversion:789' }
     *   ],
     *   entities: [
     *     { campaign: 'urn:li:sponsoredCampaign:123', conversion: 'urn:lla:llaPartnerConversion:456' },
     *     { campaign: 'urn:li:sponsoredCampaign:123', conversion: 'urn:lla:llaPartnerConversion:789' }
     *   ],
     *   accessToken: 'ABC123'
     * }).then(response => {
     *   const results = response.data.results;
     * })
     * ```
     */
    batchUpdate({ resourcePath, ids, entities, pathKeys, queryParams, versionString, accessToken, additionalConfig }: LIBatchUpdateRequestOptions): Promise<LIBatchUpdateResponse>;
    /**
     * Makes a Rest.li DELETE request to delete an entity
     *
     * @sample
     * ```ts
     * restliClient.delete({
     *   resourcePath: '/adAccounts/{id}',
     *   pathKeys: {
     *     id: 123
     *   },
     *   versionString: '202210',
     *   accessToken: 'ABC123'
     * }).then(response => {
     *   const status = response.status;
     * });
     * ```
     */
    delete({ resourcePath, pathKeys, queryParams, versionString, accessToken, additionalConfig }: LIDeleteRequestOptions): Promise<LIDeleteResponse>;
    /**
     * Makes a Rest.li BATCH_DELETE request to delete multiple entities at once.
     *
     * @sample
     * ```ts
     * restliClient.batchDelete({
     *   resourcePath: '/adAccounts',
     *   ids: [123, 456],
     *   versionString: '202210',
     *   accessToken: 'ABC123'
     * }).then(response => {
     *   const results = response.data.results;
     * });
     * ```
     */
    batchDelete({ resourcePath, ids, pathKeys, queryParams, versionString, accessToken, additionalConfig }: LIBatchDeleteRequestOptions): Promise<LIBatchDeleteResponse>;
    /**
     * Makes a Rest.li ACTION request to perform an action on a specified resource
     *
     * @example
     * ```ts
     * restliClient.action({
     *   resource: 'testResource',
     *   actionName: 'doSomething'
     *   data: {
     *     additionalParam: 123
     *   },
     *   accessToken: 'ABC123'
     * }).then(response => {
     *   const result = response.data.value;
     * })
     * ```
     */
    action({ resourcePath, actionName, data, pathKeys, queryParams, versionString, accessToken, additionalConfig }: LIActionRequestOptions): Promise<LIActionResponse>;
}
