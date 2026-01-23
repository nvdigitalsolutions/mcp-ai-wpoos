"use strict";

Object.defineProperty(exports, "__esModule", {
  value: true
});
exports.default = exports.OptionsException = void 0;
var _axios = _interopRequireDefault(require("axios"));
var _createHmac = _interopRequireDefault(require("create-hmac"));
var _oauth = _interopRequireDefault(require("oauth-1.0a"));
var _urlParse = _interopRequireDefault(require("url-parse"));
function _interopRequireDefault(e) { return e && e.__esModule ? e : { default: e }; }
function ownKeys(e, r) { var t = Object.keys(e); if (Object.getOwnPropertySymbols) { var o = Object.getOwnPropertySymbols(e); r && (o = o.filter(function (r) { return Object.getOwnPropertyDescriptor(e, r).enumerable; })), t.push.apply(t, o); } return t; }
function _objectSpread(e) { for (var r = 1; r < arguments.length; r++) { var t = null != arguments[r] ? arguments[r] : {}; r % 2 ? ownKeys(Object(t), !0).forEach(function (r) { _defineProperty(e, r, t[r]); }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys(Object(t)).forEach(function (r) { Object.defineProperty(e, r, Object.getOwnPropertyDescriptor(t, r)); }); } return e; }
function _defineProperty(e, r, t) { return (r = _toPropertyKey(r)) in e ? Object.defineProperty(e, r, { value: t, enumerable: !0, configurable: !0, writable: !0 }) : e[r] = t, e; }
function _toPropertyKey(t) { var i = _toPrimitive(t, "string"); return "symbol" == typeof i ? i : i + ""; }
function _toPrimitive(t, r) { if ("object" != typeof t || !t) return t; var e = t[Symbol.toPrimitive]; if (void 0 !== e) { var i = e.call(t, r || "default"); if ("object" != typeof i) return i; throw new TypeError("@@toPrimitive must return a primitive value."); } return ("string" === r ? String : Number)(t); }
/**
 * WooCommerce REST API wrapper
 *
 * @param {Object} opt
 */
class WooCommerceRestApi {
  /**
   * Class constructor.
   *
   * @param {Object} opt
   */
  constructor(opt) {
    if (!(this instanceof WooCommerceRestApi)) {
      return new WooCommerceRestApi(opt);
    }
    opt = opt || {};
    if (!opt.url) {
      throw new OptionsException("url is required");
    }
    if (!opt.consumerKey) {
      throw new OptionsException("consumerKey is required");
    }
    if (!opt.consumerSecret) {
      throw new OptionsException("consumerSecret is required");
    }
    this.classVersion = "1.0.2";
    this._setDefaultsOptions(opt);
  }

  /**
   * Set default options
   *
   * @param {Object} opt
   */
  _setDefaultsOptions(opt) {
    this.url = opt.url;
    this.wpAPIPrefix = opt.wpAPIPrefix || "wp-json";
    this.version = opt.version || "wc/v3";
    this.isHttps = /^https/i.test(this.url);
    this.consumerKey = opt.consumerKey;
    this.consumerSecret = opt.consumerSecret;
    this.encoding = opt.encoding || "utf8";
    this.queryStringAuth = opt.queryStringAuth || false;
    this.port = opt.port || "";
    this.timeout = opt.timeout;
    this.axiosConfig = opt.axiosConfig || {};
  }

  /**
   * Parse params object.
   *
   * @param {Object} params
   * @param {Object} query
   */
  _parseParamsObject(params, query) {
    for (const key in params) {
      const value = params[key];
      if (typeof value === "object") {
        for (const prop in value) {
          const itemKey = key.toString() + "[" + prop.toString() + "]";
          query[itemKey] = value[prop];
        }
      } else {
        query[key] = value;
      }
    }
    return query;
  }

  /**
   * Normalize query string for oAuth
   *
   * @param  {String} url
   * @param  {Object} params
   *
   * @return {String}
   */
  _normalizeQueryString(url, params) {
    // Exit if don't find query string.
    if (url.indexOf("?") === -1 && Object.keys(params).length === 0) {
      return url;
    }
    const query = new _urlParse.default(url, null, true).query;
    const values = [];
    let queryString = "";

    // Include params object into URL.searchParams.
    this._parseParamsObject(params, query);
    for (const key in query) {
      values.push(key);
    }
    values.sort();
    for (const i in values) {
      if (queryString.length) {
        queryString += "&";
      }
      queryString += encodeURIComponent(values[i]).replace(/%5B/g, "[").replace(/%5D/g, "]");
      queryString += "=";
      queryString += encodeURIComponent(query[values[i]]);
    }
    return url.split("?")[0] + "?" + queryString;
  }

  /**
   * Get URL
   *
   * @param  {String} endpoint
   * @param  {Object} params
   *
   * @return {String}
   */
  _getUrl(endpoint, params) {
    const api = this.wpAPIPrefix + "/";
    let url = this.url.slice(-1) === "/" ? this.url : this.url + "/";
    url = url + api + this.version + "/" + endpoint;

    // Include port.
    if (this.port !== "") {
      const hostname = new _urlParse.default(url).hostname;
      url = url.replace(hostname, hostname + ":" + this.port);
    }
    if (!this.isHttps) {
      return this._normalizeQueryString(url, params);
    }
    return url;
  }

  /**
   * Get OAuth
   *
   * @return {Object}
   */
  _getOAuth() {
    const data = {
      consumer: {
        key: this.consumerKey,
        secret: this.consumerSecret
      },
      signature_method: "HMAC-SHA256",
      hash_function: (base, key) => {
        return (0, _createHmac.default)("sha256", key).update(base).digest("base64");
      }
    };
    return new _oauth.default(data);
  }

  /**
   * Do requests
   *
   * @param  {String} method
   * @param  {String} endpoint
   * @param  {Object} data
   * @param  {Object} params
   *
   * @return {Object}
   */
  _request(method, endpoint, data, params = {}) {
    const url = this._getUrl(endpoint, params);
    const headers = {
      Accept: "application/json"
    };
    // only set "User-Agent" in node environment
    // the checking method is identical to upstream axios
    if (typeof process !== "undefined" && Object.prototype.toString.call(process) === "[object process]") {
      headers["User-Agent"] = "WooCommerce REST API - JS Client/" + this.classVersion;
    }
    let options = {
      url: url,
      method: method,
      responseEncoding: this.encoding,
      timeout: this.timeout,
      responseType: "json",
      headers
    };
    if (this.isHttps) {
      if (this.queryStringAuth) {
        options.params = {
          consumer_key: this.consumerKey,
          consumer_secret: this.consumerSecret
        };
      } else {
        options.auth = {
          username: this.consumerKey,
          password: this.consumerSecret
        };
      }
      options.params = _objectSpread(_objectSpread({}, options.params), params);
    } else {
      options.params = this._getOAuth().authorize({
        url: url,
        method: method
      });
    }
    if (data) {
      options.headers["Content-Type"] = "application/json;charset=utf-8";
      options.data = JSON.stringify(data);
    }

    // Allow set and override Axios options.
    options = _objectSpread(_objectSpread({}, options), this.axiosConfig);
    return (0, _axios.default)(options);
  }

  /**
   * GET requests
   *
   * @param  {String} endpoint
   * @param  {Object} params
   *
   * @return {Object}
   */
  get(endpoint, params = {}) {
    return this._request("get", endpoint, null, params);
  }

  /**
   * POST requests
   *
   * @param  {String} endpoint
   * @param  {Object} data
   * @param  {Object} params
   *
   * @return {Object}
   */
  post(endpoint, data, params = {}) {
    return this._request("post", endpoint, data, params);
  }

  /**
   * PUT requests
   *
   * @param  {String} endpoint
   * @param  {Object} data
   * @param  {Object} params
   *
   * @return {Object}
   */
  put(endpoint, data, params = {}) {
    return this._request("put", endpoint, data, params);
  }

  /**
   * DELETE requests
   *
   * @param  {String} endpoint
   * @param  {Object} params
   * @param  {Object} params
   *
   * @return {Object}
   */
  delete(endpoint, params = {}) {
    return this._request("delete", endpoint, null, params);
  }

  /**
   * OPTIONS requests
   *
   * @param  {String} endpoint
   * @param  {Object} params
   *
   * @return {Object}
   */
  options(endpoint, params = {}) {
    return this._request("options", endpoint, null, params);
  }
}

/**
 * Options Exception.
 */
exports.default = WooCommerceRestApi;
class OptionsException {
  /**
   * Constructor.
   *
   * @param {String} message
   */
  constructor(message) {
    this.name = "Options Error";
    this.message = message;
  }
}
exports.OptionsException = OptionsException;