"use strict";
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    var desc = Object.getOwnPropertyDescriptor(m, k);
    if (!desc || ("get" in desc ? !m.__esModule : desc.writable || desc.configurable)) {
      desc = { enumerable: true, get: function() { return m[k]; } };
    }
    Object.defineProperty(o, k2, desc);
}) : (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    o[k2] = m[k];
}));
var __setModuleDefault = (this && this.__setModuleDefault) || (Object.create ? (function(o, v) {
    Object.defineProperty(o, "default", { enumerable: true, value: v });
}) : function(o, v) {
    o["default"] = v;
});
var __importStar = (this && this.__importStar) || function (mod) {
    if (mod && mod.__esModule) return mod;
    var result = {};
    if (mod != null) for (var k in mod) if (k !== "default" && Object.prototype.hasOwnProperty.call(mod, k)) __createBinding(result, mod, k);
    __setModuleDefault(result, mod);
    return result;
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.utils = void 0;
const oauthUtils = __importStar(require("./utils/oauth-utils"));
const restliUtils = __importStar(require("./utils/restli-utils"));
const apiUtils = __importStar(require("./utils/api-utils"));
const urnUtils = __importStar(require("./utils/urn-utils"));
const patchUtils = __importStar(require("./utils/patch-generator"));
const queryTunnelingUtils = __importStar(require("./utils/query-tunneling"));
const constants = __importStar(require("./utils/constants"));
const encoderUtils = __importStar(require("./utils/encoder"));
const decoder_1 = require("./utils/decoder");
exports.utils = Object.assign(Object.assign(Object.assign(Object.assign(Object.assign(Object.assign(Object.assign(Object.assign(Object.assign({}, oauthUtils), restliUtils), apiUtils), urnUtils), patchUtils), queryTunnelingUtils), constants), encoderUtils), { decode: decoder_1.decode,
    paramDecode: decoder_1.paramDecode,
    reducedDecode: decoder_1.reducedDecode });
//# sourceMappingURL=utils.js.map