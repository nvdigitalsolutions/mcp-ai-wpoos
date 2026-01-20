"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.logError = exports.logSuccess = void 0;
function logSuccess(response) {
    var _a, _b, _c, _d;
    console.log(`${new Date().toISOString()} [linkedin-api-client]: Success Response`);
    console.group();
    console.log(JSON.stringify({
        method: (_a = response.config) === null || _a === void 0 ? void 0 : _a.method,
        url: (_b = response.config) === null || _b === void 0 ? void 0 : _b.url,
        status: response.status,
        requestHeaders: (_c = response.config) === null || _c === void 0 ? void 0 : _c.headers,
        requestData: (_d = response.config) === null || _d === void 0 ? void 0 : _d.data,
        responseHeaders: response.headers,
        responseData: response.data
    }, null, 2));
    console.groupEnd();
}
exports.logSuccess = logSuccess;
function logError(error) {
    var _a, _b, _c, _d, _e, _f, _g, _h, _j, _k, _l, _m, _o, _p, _q;
    const dateString = new Date().toISOString();
    if (error.response) {
        console.error(`${dateString} [linkedin-api-client]: Error Response`);
        console.group();
        console.error(JSON.stringify({
            method: (_b = (_a = error.response) === null || _a === void 0 ? void 0 : _a.config) === null || _b === void 0 ? void 0 : _b.method,
            url: (_d = (_c = error.response) === null || _c === void 0 ? void 0 : _c.config) === null || _d === void 0 ? void 0 : _d.url,
            status: (_e = error.response) === null || _e === void 0 ? void 0 : _e.status,
            requestHeaders: (_g = (_f = error.response) === null || _f === void 0 ? void 0 : _f.config) === null || _g === void 0 ? void 0 : _g.headers,
            requestData: (_j = (_h = error.response) === null || _h === void 0 ? void 0 : _h.config) === null || _j === void 0 ? void 0 : _j.data,
            responseHeaders: (_k = error.response) === null || _k === void 0 ? void 0 : _k.headers,
            responseData: (_l = error.response) === null || _l === void 0 ? void 0 : _l.data
        }, null, 2));
        console.groupEnd();
    }
    else {
        console.error(`${dateString} [linkedin-api-client]: Other Error`);
        console.group();
        console.error(`${error.name}: ${error.message}`);
        console.error(JSON.stringify({
            method: (_m = error.config) === null || _m === void 0 ? void 0 : _m.method,
            url: (_o = error.config) === null || _o === void 0 ? void 0 : _o.url,
            requestHeaders: (_p = error.config) === null || _p === void 0 ? void 0 : _p.headers,
            requestData: (_q = error.config) === null || _q === void 0 ? void 0 : _q.data
        }, null, 2));
        console.groupEnd();
    }
}
exports.logError = logError;
//# sourceMappingURL=logging.js.map