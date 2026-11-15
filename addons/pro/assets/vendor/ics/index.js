"use strict";

Object.defineProperty(exports, "__esModule", {
  value: true
});
exports.convertTimestampToArray = convertTimestampToArray;
exports.createEvent = createEvent;
exports.createEvents = createEvents;
exports.createEventsAsync = createEventsAsync;
exports.isValidURL = isValidURL;
var _pipeline = require("./pipeline");
function _regeneratorRuntime() { "use strict"; var r = _regenerator(), e = r.m(_regeneratorRuntime), t = (Object.getPrototypeOf ? Object.getPrototypeOf(e) : e.__proto__).constructor; function n(r) { var e = "function" == typeof r && r.constructor; return !!e && (e === t || "GeneratorFunction" === (e.displayName || e.name)); } var o = { "throw": 1, "return": 2, "break": 3, "continue": 3 }; function a(r) { var e, t; return function (n) { e || (e = { stop: function stop() { return t(n.a, 2); }, "catch": function _catch() { return n.v; }, abrupt: function abrupt(r, e) { return t(n.a, o[r], e); }, delegateYield: function delegateYield(r, o, a) { return e.resultName = o, t(n.d, _regeneratorValues(r), a); }, finish: function finish(r) { return t(n.f, r); } }, t = function t(r, _t, o) { n.p = e.prev, n.n = e.next; try { return r(_t, o); } finally { e.next = n.n; } }), e.resultName && (e[e.resultName] = n.v, e.resultName = void 0), e.sent = n.v, e.next = n.n; try { return r.call(this, e); } finally { n.p = e.prev, n.n = e.next; } }; } return (_regeneratorRuntime = function _regeneratorRuntime() { return { wrap: function wrap(e, t, n, o) { return r.w(a(e), t, n, o && o.reverse()); }, isGeneratorFunction: n, mark: r.m, awrap: function awrap(r, e) { return new _OverloadYield(r, e); }, AsyncIterator: _regeneratorAsyncIterator, async: function async(r, e, t, o, u) { return (n(e) ? _regeneratorAsyncGen : _regeneratorAsync)(a(r), e, t, o, u); }, keys: _regeneratorKeys, values: _regeneratorValues }; })(); }
function _regeneratorValues(e) { if (null != e) { var t = e["function" == typeof Symbol && Symbol.iterator || "@@iterator"], r = 0; if (t) return t.call(e); if ("function" == typeof e.next) return e; if (!isNaN(e.length)) return { next: function next() { return e && r >= e.length && (e = void 0), { value: e && e[r++], done: !e }; } }; } throw new TypeError(_typeof(e) + " is not iterable"); }
function _regeneratorKeys(e) { var n = Object(e), r = []; for (var t in n) r.unshift(t); return function e() { for (; r.length;) if ((t = r.pop()) in n) return e.value = t, e.done = !1, e; return e.done = !0, e; }; }
function _regeneratorAsync(n, e, r, t, o) { var a = _regeneratorAsyncGen(n, e, r, t, o); return a.next().then(function (n) { return n.done ? n.value : a.next(); }); }
function _regeneratorAsyncGen(r, e, t, o, n) { return new _regeneratorAsyncIterator(_regenerator().w(r, e, t, o), n || Promise); }
function _regeneratorAsyncIterator(t, e) { function n(r, o, i, f) { try { var c = t[r](o), u = c.value; return u instanceof _OverloadYield ? e.resolve(u.v).then(function (t) { n("next", t, i, f); }, function (t) { n("throw", t, i, f); }) : e.resolve(u).then(function (t) { c.value = t, i(c); }, function (t) { return n("throw", t, i, f); }); } catch (t) { f(t); } } var r; this.next || (_regeneratorDefine2(_regeneratorAsyncIterator.prototype), _regeneratorDefine2(_regeneratorAsyncIterator.prototype, "function" == typeof Symbol && Symbol.asyncIterator || "@asyncIterator", function () { return this; })), _regeneratorDefine2(this, "_invoke", function (t, o, i) { function f() { return new e(function (e, r) { n(t, i, e, r); }); } return r = r ? r.then(f, f) : f(); }, !0); }
function _regenerator() { /*! regenerator-runtime -- Copyright (c) 2014-present, Facebook, Inc. -- license (MIT): https://github.com/babel/babel/blob/main/packages/babel-helpers/LICENSE */ var e, t, r = "function" == typeof Symbol ? Symbol : {}, n = r.iterator || "@@iterator", o = r.toStringTag || "@@toStringTag"; function i(r, n, o, i) { var c = n && n.prototype instanceof Generator ? n : Generator, u = Object.create(c.prototype); return _regeneratorDefine2(u, "_invoke", function (r, n, o) { var i, c, u, f = 0, p = o || [], y = !1, G = { p: 0, n: 0, v: e, a: d, f: d.bind(e, 4), d: function d(t, r) { return i = t, c = 0, u = e, G.n = r, a; } }; function d(r, n) { for (c = r, u = n, t = 0; !y && f && !o && t < p.length; t++) { var o, i = p[t], d = G.p, l = i[2]; r > 3 ? (o = l === n) && (u = i[(c = i[4]) ? 5 : (c = 3, 3)], i[4] = i[5] = e) : i[0] <= d && ((o = r < 2 && d < i[1]) ? (c = 0, G.v = n, G.n = i[1]) : d < l && (o = r < 3 || i[0] > n || n > l) && (i[4] = r, i[5] = n, G.n = l, c = 0)); } if (o || r > 1) return a; throw y = !0, n; } return function (o, p, l) { if (f > 1) throw TypeError("Generator is already running"); for (y && 1 === p && d(p, l), c = p, u = l; (t = c < 2 ? e : u) || !y;) { i || (c ? c < 3 ? (c > 1 && (G.n = -1), d(c, u)) : G.n = u : G.v = u); try { if (f = 2, i) { if (c || (o = "next"), t = i[o]) { if (!(t = t.call(i, u))) throw TypeError("iterator result is not an object"); if (!t.done) return t; u = t.value, c < 2 && (c = 0); } else 1 === c && (t = i["return"]) && t.call(i), c < 2 && (u = TypeError("The iterator does not provide a '" + o + "' method"), c = 1); i = e; } else if ((t = (y = G.n < 0) ? u : r.call(n, G)) !== a) break; } catch (t) { i = e, c = 1, u = t; } finally { f = 1; } } return { value: t, done: y }; }; }(r, o, i), !0), u; } var a = {}; function Generator() {} function GeneratorFunction() {} function GeneratorFunctionPrototype() {} t = Object.getPrototypeOf; var c = [][n] ? t(t([][n]())) : (_regeneratorDefine2(t = {}, n, function () { return this; }), t), u = GeneratorFunctionPrototype.prototype = Generator.prototype = Object.create(c); function f(e) { return Object.setPrototypeOf ? Object.setPrototypeOf(e, GeneratorFunctionPrototype) : (e.__proto__ = GeneratorFunctionPrototype, _regeneratorDefine2(e, o, "GeneratorFunction")), e.prototype = Object.create(u), e; } return GeneratorFunction.prototype = GeneratorFunctionPrototype, _regeneratorDefine2(u, "constructor", GeneratorFunctionPrototype), _regeneratorDefine2(GeneratorFunctionPrototype, "constructor", GeneratorFunction), GeneratorFunction.displayName = "GeneratorFunction", _regeneratorDefine2(GeneratorFunctionPrototype, o, "GeneratorFunction"), _regeneratorDefine2(u), _regeneratorDefine2(u, o, "Generator"), _regeneratorDefine2(u, n, function () { return this; }), _regeneratorDefine2(u, "toString", function () { return "[object Generator]"; }), (_regenerator = function _regenerator() { return { w: i, m: f }; })(); }
function _regeneratorDefine2(e, r, n, t) { var i = Object.defineProperty; try { i({}, "", {}); } catch (e) { i = 0; } _regeneratorDefine2 = function _regeneratorDefine(e, r, n, t) { function o(r, n) { _regeneratorDefine2(e, r, function (e) { return this._invoke(r, n, e); }); } r ? i ? i(e, r, { value: n, enumerable: !t, configurable: !t, writable: !t }) : e[r] = n : (o("next", 0), o("throw", 1), o("return", 2)); }, _regeneratorDefine2(e, r, n, t); }
function _OverloadYield(e, d) { this.v = e, this.k = d; }
function asyncGeneratorStep(n, t, e, r, o, a, c) { try { var i = n[a](c), u = i.value; } catch (n) { return void e(n); } i.done ? t(u) : Promise.resolve(u).then(r, o); }
function _asyncToGenerator(n) { return function () { var t = this, e = arguments; return new Promise(function (r, o) { var a = n.apply(t, e); function _next(n) { asyncGeneratorStep(a, r, o, _next, _throw, "next", n); } function _throw(n) { asyncGeneratorStep(a, r, o, _next, _throw, "throw", n); } _next(void 0); }); }; }
function _typeof(o) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (o) { return typeof o; } : function (o) { return o && "function" == typeof Symbol && o.constructor === Symbol && o !== Symbol.prototype ? "symbol" : typeof o; }, _typeof(o); }
function ownKeys(e, r) { var t = Object.keys(e); if (Object.getOwnPropertySymbols) { var o = Object.getOwnPropertySymbols(e); r && (o = o.filter(function (r) { return Object.getOwnPropertyDescriptor(e, r).enumerable; })), t.push.apply(t, o); } return t; }
function _objectSpread(e) { for (var r = 1; r < arguments.length; r++) { var t = null != arguments[r] ? arguments[r] : {}; r % 2 ? ownKeys(Object(t), !0).forEach(function (r) { _defineProperty(e, r, t[r]); }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys(Object(t)).forEach(function (r) { Object.defineProperty(e, r, Object.getOwnPropertyDescriptor(t, r)); }); } return e; }
function _defineProperty(e, r, t) { return (r = _toPropertyKey(r)) in e ? Object.defineProperty(e, r, { value: t, enumerable: !0, configurable: !0, writable: !0 }) : e[r] = t, e; }
function _toPropertyKey(t) { var i = _toPrimitive(t, "string"); return "symbol" == _typeof(i) ? i : i + ""; }
function _toPrimitive(t, r) { if ("object" != _typeof(t) || !t) return t; var e = t[Symbol.toPrimitive]; if (void 0 !== e) { var i = e.call(t, r || "default"); if ("object" != _typeof(i)) return i; throw new TypeError("@@toPrimitive must return a primitive value."); } return ("string" === r ? String : Number)(t); }
function buildHeaderAndValidate(header) {
  return (0, _pipeline.validateHeader)((0, _pipeline.buildHeader)(header));
}
function buildHeaderAndEventAndValidate(event) {
  return (0, _pipeline.validateHeaderAndEvent)(_objectSpread(_objectSpread({}, (0, _pipeline.buildHeader)(event)), (0, _pipeline.buildEvent)(event)));
}
function convertTimestampToArray(timestamp) {
  var inputType = arguments.length > 1 && arguments[1] !== undefined ? arguments[1] : 'local';
  var dateArray = [];
  var d = new Date(timestamp);
  dateArray.push(inputType === 'local' ? d.getFullYear() : d.getUTCFullYear());
  dateArray.push((inputType === 'local' ? d.getMonth() : d.getUTCMonth()) + 1);
  dateArray.push(inputType === 'local' ? d.getDate() : d.getUTCDate());
  dateArray.push(inputType === 'local' ? d.getHours() : d.getUTCHours());
  dateArray.push(inputType === 'local' ? d.getMinutes() : d.getUTCMinutes());
  return dateArray;
}
function createEvent(attributes, cb) {
  return createEvents([attributes], cb);
}
function createEvents(events, headerAttributesOrCb, cb) {
  var resolvedHeaderAttributes = _typeof(headerAttributesOrCb) === 'object' ? headerAttributesOrCb : {};
  var resolvedCb = arguments.length === 3 ? cb : typeof headerAttributesOrCb === 'function' ? headerAttributesOrCb : null;
  var run = function run() {
    if (!events) {
      return {
        error: new Error('one argument is required'),
        value: null
      };
    }
    var _ref = events.length === 0 ? buildHeaderAndValidate(resolvedHeaderAttributes) : buildHeaderAndEventAndValidate(_objectSpread(_objectSpread({}, events[0]), resolvedHeaderAttributes)),
      headerError = _ref.error,
      headerValue = _ref.value;
    if (headerError) {
      return {
        error: headerError,
        value: null
      };
    }
    var value = '';
    value += (0, _pipeline.formatHeader)(headerValue);
    for (var i = 0; i < events.length; i++) {
      var _buildHeaderAndEventA = buildHeaderAndEventAndValidate(events[i]),
        eventError = _buildHeaderAndEventA.error,
        eventValue = _buildHeaderAndEventA.value;
      if (eventError) return {
        error: eventError,
        value: null
      };
      value += (0, _pipeline.formatEvent)(eventValue);
    }
    value += (0, _pipeline.formatFooter)();
    return {
      error: null,
      value: value
    };
  };
  var returnValue;
  try {
    returnValue = run();
  } catch (e) {
    returnValue = {
      error: e,
      value: null
    };
  }
  if (!resolvedCb) {
    return returnValue;
  }
  return resolvedCb(returnValue.error, returnValue.value);
}
function createEventsAsync(_x) {
  return _createEventsAsync.apply(this, arguments);
}
function _createEventsAsync() {
  _createEventsAsync = _asyncToGenerator(/*#__PURE__*/_regeneratorRuntime().mark(function _callee2(events) {
    var headerAttributes,
      tick,
      runAsync,
      returnValue,
      _args2 = arguments;
    return _regeneratorRuntime().wrap(function _callee2$(_context2) {
      while (1) switch (_context2.prev = _context2.next) {
        case 0:
          headerAttributes = _args2.length > 1 && _args2[1] !== undefined ? _args2[1] : {};
          // Yield to the event loop periodically so huge inputs don’t block too badly.
          tick = typeof (globalThis === null || globalThis === void 0 ? void 0 : globalThis.setImmediate) === 'function' ? function () {
            return new Promise(function (resolve) {
              return globalThis.setImmediate(resolve);
            });
          } : function () {
            return new Promise(function (resolve) {
              return setTimeout(resolve, 0);
            });
          };
          runAsync = /*#__PURE__*/function () {
            var _ref2 = _asyncToGenerator(/*#__PURE__*/_regeneratorRuntime().mark(function _callee() {
              var _ref3, headerError, headerValue, parts, yieldEvery, i, _buildHeaderAndEventA2, eventError, eventValue;
              return _regeneratorRuntime().wrap(function _callee$(_context) {
                while (1) switch (_context.prev = _context.next) {
                  case 0:
                    if (events) {
                      _context.next = 2;
                      break;
                    }
                    return _context.abrupt("return", {
                      error: new Error('one argument is required'),
                      value: null
                    });
                  case 2:
                    _ref3 = events.length === 0 ? buildHeaderAndValidate(headerAttributes) : buildHeaderAndEventAndValidate(_objectSpread(_objectSpread({}, events[0]), headerAttributes)), headerError = _ref3.error, headerValue = _ref3.value;
                    if (!headerError) {
                      _context.next = 5;
                      break;
                    }
                    return _context.abrupt("return", {
                      error: headerError,
                      value: null
                    });
                  case 5:
                    // Build up the calendar in parts to avoid quadratic string concatenation costs.
                    parts = [(0, _pipeline.formatHeader)(headerValue)]; // Yield every N events; tuneable but intentionally conservative.
                    yieldEvery = 1000;
                    i = 0;
                  case 8:
                    if (!(i < events.length)) {
                      _context.next = 19;
                      break;
                    }
                    _buildHeaderAndEventA2 = buildHeaderAndEventAndValidate(events[i]), eventError = _buildHeaderAndEventA2.error, eventValue = _buildHeaderAndEventA2.value;
                    if (!eventError) {
                      _context.next = 12;
                      break;
                    }
                    return _context.abrupt("return", {
                      error: eventError,
                      value: null
                    });
                  case 12:
                    parts.push((0, _pipeline.formatEvent)(eventValue));
                    if (!(i > 0 && i % yieldEvery === 0)) {
                      _context.next = 16;
                      break;
                    }
                    _context.next = 16;
                    return tick();
                  case 16:
                    i++;
                    _context.next = 8;
                    break;
                  case 19:
                    parts.push((0, _pipeline.formatFooter)());
                    return _context.abrupt("return", {
                      error: null,
                      value: parts.join('')
                    });
                  case 21:
                  case "end":
                    return _context.stop();
                }
              }, _callee);
            }));
            return function runAsync() {
              return _ref2.apply(this, arguments);
            };
          }();
          _context2.prev = 3;
          _context2.next = 6;
          return runAsync();
        case 6:
          returnValue = _context2.sent;
          _context2.next = 12;
          break;
        case 9:
          _context2.prev = 9;
          _context2.t0 = _context2["catch"](3);
          returnValue = {
            error: _context2.t0,
            value: null
          };
        case 12:
          return _context2.abrupt("return", returnValue);
        case 13:
        case "end":
          return _context2.stop();
      }
    }, _callee2, null, [[3, 9]]);
  }));
  return _createEventsAsync.apply(this, arguments);
}
function isValidURL(url) {
  return _pipeline.urlRegex.test(url);
}