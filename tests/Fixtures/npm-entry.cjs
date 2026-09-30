var __create = Object.create;
var __defProp = Object.defineProperty;
var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
var __getOwnPropNames = Object.getOwnPropertyNames;
var __getProtoOf = Object.getPrototypeOf;
var __hasOwnProp = Object.prototype.hasOwnProperty;
var __export = (target, all) => {
  for (var name in all)
    __defProp(target, name, { get: all[name], enumerable: true });
};
var __copyProps = (to, from, except, desc) => {
  if (from && typeof from === "object" || typeof from === "function") {
    for (let key of __getOwnPropNames(from))
      if (!__hasOwnProp.call(to, key) && key !== except)
        __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
  }
  return to;
};
var __toESM = (mod, isNodeMode, target) => (target = mod != null ? __create(__getProtoOf(mod)) : {}, __copyProps(
  // If the importer is in node compatibility mode or this is not an ESM
  // file that has been converted to a CommonJS file using a Babel-
  // compatible transform (i.e. "__esModule" has not been set), then set
  // "default" to the CommonJS "module.exports" for node compatibility.
  isNodeMode || !mod || !mod.__esModule ? __defProp(target, "default", { value: mod, enumerable: true }) : target,
  mod
));
var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);

// tests/Fixtures/npm-entry.js
var npm_entry_exports = {};
__export(npm_entry_exports, {
  fingerprintUserAgent: () => fingerprintUserAgent,
  newInjectedPage: () => import_fingerprint_injector.newInjectedPage,
  stealth: () => import_puppeteer_extra_plugin_stealth.default,
  twice: () => twice
});
module.exports = __toCommonJS(npm_entry_exports);
var import_puppeteer_extra_plugin_stealth = __toESM(require("puppeteer-extra-plugin-stealth"));
var import_fingerprint_injector = require("fingerprint-injector");
var import_fingerprint_generator = require("fingerprint-generator");
var twice = (value) => value * 2;
var fingerprintUserAgent = () => new import_fingerprint_generator.FingerprintGenerator().getFingerprint({ browsers: ["chrome"] }).fingerprint.navigator.userAgent;
// Annotate the CommonJS export names for ESM import in node:
0 && (module.exports = {
  fingerprintUserAgent,
  newInjectedPage,
  stealth,
  twice
});
