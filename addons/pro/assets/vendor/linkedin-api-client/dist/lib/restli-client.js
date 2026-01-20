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
var __classPrivateFieldGet = (this && this.__classPrivateFieldGet) || function (receiver, state, kind, f) {
    if (kind === "a" && !f) throw new TypeError("Private accessor was defined without a getter");
    if (typeof state === "function" ? receiver !== state || !f : !state.has(receiver)) throw new TypeError("Cannot read private member from an object whose class did not declare it");
    return kind === "m" ? f : kind === "a" ? f.call(receiver) : f ? f.value : state.get(receiver);
};
var __classPrivateFieldSet = (this && this.__classPrivateFieldSet) || function (receiver, state, value, kind, f) {
    if (kind === "m") throw new TypeError("Private method is not writable");
    if (kind === "a" && !f) throw new TypeError("Private accessor was defined without a setter");
    if (typeof state === "function" ? receiver !== state || !f : !state.has(receiver)) throw new TypeError("Cannot write private member to an object whose class did not declare it");
    return (kind === "a" ? f.call(receiver, value) : f ? f.value = value : state.set(receiver, value)), value;
};
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
var _RestliClient_debugEnabled, _RestliClient_logSuccessResponses;
Object.defineProperty(exports, "__esModule", { value: true });
exports.RestliClient = void 0;
const axios_1 = __importDefault(require("axios"));
const constants_1 = require("./utils/constants");
const patch_generator_1 = require("./utils/patch-generator");
const encoder_1 = require("./utils/encoder");
const restli_utils_1 = require("./utils/restli-utils");
const api_utils_1 = require("./utils/api-utils");
const query_tunneling_1 = require("./utils/query-tunneling");
const lodash_1 = __importDefault(require("lodash"));
const logging_1 = require("./utils/logging");
class RestliClient {
    constructor(config = {}) {
        _RestliClient_debugEnabled.set(this, false);
        _RestliClient_logSuccessResponses.set(this, false);
        this.axiosInstance = axios_1.default.create(config);
        this.axiosInstance.interceptors.response.use((response) => {
            if (__classPrivateFieldGet(this, _RestliClient_debugEnabled, "f") && __classPrivateFieldGet(this, _RestliClient_logSuccessResponses, "f")) {
                (0, logging_1.logSuccess)(response);
            }
            return response;
        }, (error) => __awaiter(this, void 0, void 0, function* () {
            if (__classPrivateFieldGet(this, _RestliClient_debugEnabled, "f")) {
                (0, logging_1.logError)(error);
            }
            return yield Promise.reject(error);
        }));
    }
    /**
     * Set debug logging parameters for the client.
     */
    setDebugParams({ 
    /** Flag whether to enable debug logging of request responses */
    enabled = false, 
    /** Flag whether to log successful responses */
    logSuccessResponses = false }) {
        __classPrivateFieldSet(this, _RestliClient_debugEnabled, enabled, "f");
        __classPrivateFieldSet(this, _RestliClient_logSuccessResponses, logSuccessResponses, "f");
    }
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
    get({ resourcePath, accessToken, pathKeys = {}, queryParams = {}, versionString = null, additionalConfig = {} }) {
        return __awaiter(this, void 0, void 0, function* () {
            const encodedQueryParamString = (0, restli_utils_1.encodeQueryParamsForGetRequests)(queryParams);
            const urlPath = (0, api_utils_1.buildRestliUrl)(resourcePath, pathKeys, versionString);
            const requestConfig = (0, query_tunneling_1.maybeApplyQueryTunnelingToRequestsWithoutBody)({
                encodedQueryParamString,
                urlPath,
                originalRestliMethod: constants_1.RESTLI_METHODS.GET,
                accessToken,
                versionString,
                additionalConfig
            });
            return yield this.axiosInstance.request(requestConfig);
        });
    }
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
    batchGet({ resourcePath, ids, pathKeys = {}, queryParams = {}, versionString = null, accessToken, additionalConfig = {} }) {
        return __awaiter(this, void 0, void 0, function* () {
            const encodedQueryParamString = (0, restli_utils_1.encodeQueryParamsForGetRequests)(Object.assign({ ids }, queryParams));
            const urlPath = (0, api_utils_1.buildRestliUrl)(resourcePath, pathKeys, versionString);
            const requestConfig = (0, query_tunneling_1.maybeApplyQueryTunnelingToRequestsWithoutBody)({
                encodedQueryParamString,
                urlPath,
                originalRestliMethod: constants_1.RESTLI_METHODS.BATCH_GET,
                accessToken,
                versionString,
                additionalConfig
            });
            return yield this.axiosInstance.request(requestConfig);
        });
    }
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
    getAll({ resourcePath, accessToken, pathKeys = {}, queryParams = {}, versionString = null, additionalConfig = {} }) {
        return __awaiter(this, void 0, void 0, function* () {
            const urlPath = (0, api_utils_1.buildRestliUrl)(resourcePath, pathKeys, versionString);
            const encodedQueryParamString = (0, restli_utils_1.encodeQueryParamsForGetRequests)(queryParams);
            const requestConfig = (0, query_tunneling_1.maybeApplyQueryTunnelingToRequestsWithoutBody)({
                encodedQueryParamString,
                urlPath,
                originalRestliMethod: constants_1.RESTLI_METHODS.GET_ALL,
                accessToken,
                versionString,
                additionalConfig
            });
            return yield this.axiosInstance.request(requestConfig);
        });
    }
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
    finder({ resourcePath, finderName, pathKeys = {}, queryParams = {}, versionString = null, accessToken, additionalConfig = {} }) {
        return __awaiter(this, void 0, void 0, function* () {
            const urlPath = (0, api_utils_1.buildRestliUrl)(resourcePath, pathKeys, versionString);
            const encodedQueryParamString = (0, restli_utils_1.encodeQueryParamsForGetRequests)(Object.assign({ q: finderName }, queryParams));
            const requestConfig = (0, query_tunneling_1.maybeApplyQueryTunnelingToRequestsWithoutBody)({
                encodedQueryParamString,
                urlPath,
                originalRestliMethod: constants_1.RESTLI_METHODS.FINDER,
                accessToken,
                versionString,
                additionalConfig
            });
            return yield this.axiosInstance.request(requestConfig);
        });
    }
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
    batchFinder({ resourcePath, finderName, finderCriteria, pathKeys = {}, queryParams = {}, versionString = null, accessToken, additionalConfig = {} }) {
        return __awaiter(this, void 0, void 0, function* () {
            const urlPath = (0, api_utils_1.buildRestliUrl)(resourcePath, pathKeys, versionString);
            const encodedQueryParamString = (0, restli_utils_1.encodeQueryParamsForGetRequests)(Object.assign({ bq: finderName, [finderCriteria.name]: finderCriteria.value }, queryParams));
            const requestConfig = (0, query_tunneling_1.maybeApplyQueryTunnelingToRequestsWithoutBody)({
                encodedQueryParamString,
                urlPath,
                originalRestliMethod: constants_1.RESTLI_METHODS.BATCH_FINDER,
                accessToken,
                versionString,
                additionalConfig
            });
            return yield this.axiosInstance.request(requestConfig);
        });
    }
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
    create({ resourcePath, entity, pathKeys = {}, queryParams = {}, versionString = null, accessToken, additionalConfig = {} }) {
        return __awaiter(this, void 0, void 0, function* () {
            const urlPath = (0, api_utils_1.buildRestliUrl)(resourcePath, pathKeys, versionString);
            const encodedQueryParamString = (0, encoder_1.paramEncode)(queryParams);
            const requestConfig = lodash_1.default.merge({
                method: constants_1.HTTP_METHODS.POST,
                url: encodedQueryParamString
                    ? `${urlPath}?${encodedQueryParamString}`
                    : urlPath,
                data: entity,
                headers: (0, api_utils_1.getRestliRequestHeaders)({
                    restliMethodType: constants_1.RESTLI_METHODS.CREATE,
                    accessToken,
                    versionString
                })
            }, additionalConfig);
            const originalResponse = yield this.axiosInstance.request(requestConfig);
            return Object.assign(Object.assign({}, originalResponse), { createdEntityId: (0, restli_utils_1.getCreatedEntityId)(originalResponse, true) });
        });
    }
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
    batchCreate({ resourcePath, entities, pathKeys = {}, queryParams = {}, versionString = null, accessToken, additionalConfig = {} }) {
        return __awaiter(this, void 0, void 0, function* () {
            const urlPath = (0, api_utils_1.buildRestliUrl)(resourcePath, pathKeys, versionString);
            const encodedQueryParamString = (0, encoder_1.paramEncode)(queryParams);
            const requestConfig = lodash_1.default.merge({
                method: constants_1.HTTP_METHODS.POST,
                url: encodedQueryParamString
                    ? `${urlPath}?${encodedQueryParamString}`
                    : urlPath,
                data: {
                    elements: entities
                },
                headers: (0, api_utils_1.getRestliRequestHeaders)({
                    restliMethodType: constants_1.RESTLI_METHODS.BATCH_CREATE,
                    accessToken,
                    versionString
                })
            }, additionalConfig);
            return yield this.axiosInstance.request(requestConfig);
        });
    }
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
    partialUpdate({ resourcePath, patchSetObject, originalEntity, modifiedEntity, pathKeys = {}, queryParams = {}, versionString = null, accessToken, additionalConfig = {} }) {
        return __awaiter(this, void 0, void 0, function* () {
            const encodedQueryParamString = (0, encoder_1.paramEncode)(queryParams);
            const urlPath = (0, api_utils_1.buildRestliUrl)(resourcePath, pathKeys, versionString);
            let patchData;
            if (patchSetObject) {
                if (typeof patchSetObject === 'object' && Object.keys(patchSetObject).length === 0) {
                    throw new Error('patchSetObject must be an object with at least one key-value pair');
                }
                patchData = { patch: { $set: patchSetObject } };
            }
            else if (originalEntity && modifiedEntity) {
                patchData = (0, patch_generator_1.getPatchObject)(originalEntity, modifiedEntity);
                if (!patchData || Object.keys(patchData).length === 0) {
                    throw new Error('There must be a difference between originalEntity and modifiedEntity');
                }
            }
            else {
                throw new Error('Either patchSetObject or originalEntity and modifiedEntity properties must be present');
            }
            const requestConfig = (0, query_tunneling_1.maybeApplyQueryTunnelingToRequestsWithBody)({
                encodedQueryParamString,
                urlPath,
                originalRestliMethod: constants_1.RESTLI_METHODS.PARTIAL_UPDATE,
                originalJSONRequestBody: patchData,
                accessToken,
                versionString,
                additionalConfig
            });
            return yield this.axiosInstance.request(requestConfig);
        });
    }
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
    batchPartialUpdate({ resourcePath, ids, originalEntities, modifiedEntities, patchSetObjects, pathKeys = {}, queryParams = {}, versionString = null, accessToken, additionalConfig = {} }) {
        return __awaiter(this, void 0, void 0, function* () {
            const urlPath = (0, api_utils_1.buildRestliUrl)(resourcePath, pathKeys, versionString);
            if (patchSetObjects) {
                if (ids.length !== patchSetObjects.length) {
                    throw new Error('The fields { ids, patchSetObjects } must be arrays with the same length');
                }
            }
            else if (originalEntities && modifiedEntities) {
                if (ids.length !== originalEntities.length &&
                    originalEntities.length !== modifiedEntities.length) {
                    throw new Error('The fields { ids, originalEntities, modifiedEntities } must be arrays with the same length');
                }
            }
            else {
                throw new Error('Either { patchSetObjects } or { originalEntities, modifiedEntities } need to be provided as input parameters');
            }
            const encodedQueryParamString = (0, encoder_1.paramEncode)(Object.assign({ ids }, queryParams));
            let entities;
            if (patchSetObjects) {
                entities = ids.reduce((prev, curr, index) => {
                    const encodedEntityId = (0, encoder_1.encode)(curr);
                    prev[encodedEntityId] = {
                        patch: { $set: patchSetObjects[index] }
                    };
                    return prev;
                }, {});
            }
            else if (originalEntities && modifiedEntities) {
                entities = ids.reduce((prev, curr, index) => {
                    const encodedEntityId = (0, encoder_1.encode)(curr);
                    prev[encodedEntityId] = (0, patch_generator_1.getPatchObject)(originalEntities[index], modifiedEntities[index]);
                    return prev;
                }, {});
            }
            const requestConfig = (0, query_tunneling_1.maybeApplyQueryTunnelingToRequestsWithBody)({
                encodedQueryParamString,
                urlPath,
                originalRestliMethod: constants_1.RESTLI_METHODS.BATCH_PARTIAL_UPDATE,
                originalJSONRequestBody: {
                    entities
                },
                accessToken,
                versionString,
                additionalConfig
            });
            return yield this.axiosInstance.request(requestConfig);
        });
    }
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
    update({ resourcePath, entity, pathKeys = {}, queryParams = {}, versionString = null, accessToken, additionalConfig = {} }) {
        return __awaiter(this, void 0, void 0, function* () {
            const urlPath = (0, api_utils_1.buildRestliUrl)(resourcePath, pathKeys, versionString);
            const encodedQueryParamString = (0, encoder_1.paramEncode)(queryParams);
            const requestConfig = (0, query_tunneling_1.maybeApplyQueryTunnelingToRequestsWithBody)({
                encodedQueryParamString,
                urlPath,
                originalRestliMethod: constants_1.RESTLI_METHODS.UPDATE,
                originalJSONRequestBody: entity,
                accessToken,
                versionString,
                additionalConfig
            });
            return yield this.axiosInstance.request(requestConfig);
        });
    }
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
    batchUpdate({ resourcePath, ids, entities, pathKeys = {}, queryParams = {}, versionString = null, accessToken, additionalConfig = {} }) {
        return __awaiter(this, void 0, void 0, function* () {
            const urlPath = (0, api_utils_1.buildRestliUrl)(resourcePath, pathKeys, versionString);
            const encodedQueryParamString = (0, encoder_1.paramEncode)(Object.assign({ ids }, queryParams));
            // This as any[] workaround is due to this issue: https://github.com/microsoft/TypeScript/issues/36390
            const entitiesObject = ids.reduce((entitiesObject, currId, index) => {
                entitiesObject[(0, encoder_1.encode)(currId)] = entities[index];
                return entitiesObject;
            }, {});
            const requestConfig = (0, query_tunneling_1.maybeApplyQueryTunnelingToRequestsWithBody)({
                encodedQueryParamString,
                urlPath,
                originalRestliMethod: constants_1.RESTLI_METHODS.BATCH_UPDATE,
                originalJSONRequestBody: {
                    entities: entitiesObject
                },
                accessToken,
                versionString,
                additionalConfig
            });
            return yield this.axiosInstance.request(requestConfig);
        });
    }
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
    delete({ resourcePath, pathKeys = {}, queryParams = {}, versionString = null, accessToken, additionalConfig = {} }) {
        return __awaiter(this, void 0, void 0, function* () {
            const urlPath = (0, api_utils_1.buildRestliUrl)(resourcePath, pathKeys, versionString);
            const encodedQueryParamString = (0, encoder_1.paramEncode)(queryParams);
            const requestConfig = (0, query_tunneling_1.maybeApplyQueryTunnelingToRequestsWithoutBody)({
                encodedQueryParamString,
                urlPath,
                originalRestliMethod: constants_1.RESTLI_METHODS.DELETE,
                accessToken,
                versionString,
                additionalConfig
            });
            return yield this.axiosInstance.request(requestConfig);
        });
    }
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
    batchDelete({ resourcePath, ids, pathKeys = {}, queryParams = {}, versionString = null, accessToken, additionalConfig = {} }) {
        return __awaiter(this, void 0, void 0, function* () {
            const urlPath = (0, api_utils_1.buildRestliUrl)(resourcePath, pathKeys, versionString);
            const encodedQueryParamString = (0, encoder_1.paramEncode)(Object.assign({ ids }, queryParams));
            const requestConfig = (0, query_tunneling_1.maybeApplyQueryTunnelingToRequestsWithoutBody)({
                encodedQueryParamString,
                urlPath,
                originalRestliMethod: constants_1.RESTLI_METHODS.BATCH_DELETE,
                accessToken,
                versionString,
                additionalConfig
            });
            return yield this.axiosInstance.request(requestConfig);
        });
    }
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
    action({ resourcePath, actionName, data = null, pathKeys = {}, queryParams = {}, versionString = null, accessToken, additionalConfig }) {
        return __awaiter(this, void 0, void 0, function* () {
            const urlPath = (0, api_utils_1.buildRestliUrl)(resourcePath, pathKeys, versionString);
            const encodedQueryParamString = (0, encoder_1.paramEncode)(Object.assign({ action: actionName }, queryParams));
            const requestConfig = lodash_1.default.merge({
                method: constants_1.HTTP_METHODS.POST,
                url: `${urlPath}?${encodedQueryParamString}`,
                data,
                headers: (0, api_utils_1.getRestliRequestHeaders)({
                    restliMethodType: constants_1.RESTLI_METHODS.ACTION,
                    accessToken,
                    versionString
                }),
                additionalConfig
            });
            return yield this.axiosInstance.request(requestConfig);
        });
    }
}
exports.RestliClient = RestliClient;
_RestliClient_debugEnabled = new WeakMap(), _RestliClient_logSuccessResponses = new WeakMap();
//# sourceMappingURL=restli-client.js.map