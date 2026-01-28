"use strict";

var _interopRequireDefault = require("@babel/runtime/helpers/interopRequireDefault").default;
var _interopRequireWildcard = require("@babel/runtime/helpers/interopRequireWildcard").default;
Object.defineProperty(exports, "__esModule", {
  value: true
});
exports.default = mjml2html;
var _mjmlCore = _interopRequireWildcard(require("mjml-core"));
var _mjmlValidator = require("mjml-validator");
var _mjmlPresetCore = _interopRequireDefault(require("mjml-preset-core"));
(0, _mjmlCore.assignComponents)(_mjmlCore.components, _mjmlPresetCore.default.components);
(0, _mjmlValidator.assignDependencies)(_mjmlValidator.dependencies, _mjmlPresetCore.default.dependencies);
async function mjml2html(input, options = {}) {
  return (0, _mjmlCore.default)(input, options);
}
module.exports = exports.default;