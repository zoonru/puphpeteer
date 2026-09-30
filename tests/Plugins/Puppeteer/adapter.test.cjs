const {test} = require('node:test');
const assert = require('node:assert/strict');
const esbuild = require('esbuild');
const vm = require('node:vm');
const {PuppeteerExtraPlugin} = require('puppeteer-extra-plugin');
const resolveStealth = name => {
  if (name === 'stealth') return require('puppeteer-extra-plugin-stealth');
  if (name.startsWith('stealth/evasions/')) return require(`puppeteer-extra-plugin-stealth/evasions/${name.slice('stealth/evasions/'.length)}`);
  return undefined;
};
const load = esbuild.build({
  entryPoints: ['js/plugins/adapter.js'], bundle: true, platform: 'browser', format: 'iife',
  globalName: 'adapter', write: false,
});
const context = vm.createContext({console, setTimeout, clearTimeout});
let PluginAdapter;
require('node:test').before(async () => { vm.runInContext((await load).outputFiles[0].text, context); PluginAdapter = context.adapter.PluginAdapter; });
class Plugin extends PuppeteerExtraPlugin { get name() {return 'custom';} }

test('genuine stealth defaults resolve all 16 upstream evasions and launch hooks', async () => {
  const adapter = new PluginAdapter({}, resolveStealth);
  const options = await adapter.prepare([{name: 'stealth', options: {}}], {}, 'launch');
  assert.equal(adapter.plugins.length, 17);
  assert.equal(options.defaultViewport, null);
  assert.equal(adapter.plugins.find(plugin => plugin.name === 'stealth/evasions/user-agent-override')._headless, true);
  assert.ok(options.args.includes('--disable-blink-features=AutomationControlled'));
  assert.ok(options.ignoreDefaultArgs.includes('--disable-extensions'));
  assert.ok(adapter.plugins.at(-1).requirements.has('runLast'));
});
test('enabled evasions and plugin options, connect lifecycle', async () => {
  const adapter = new PluginAdapter({}, resolveStealth);
  await adapter.prepare([{name: 'stealth', options: {enabledEvasions: ['navigator.languages']}}], {}, 'connect');
  assert.equal(adapter.plugins.length, 2);
  await assert.rejects(new PluginAdapter().prepare([{name:'missing'}], {}, 'launch'), /not available/);
  await assert.rejects(new PluginAdapter({}, resolveStealth).prepare([{name:'stealth', options:{enabledEvasions:['typo']}}], {}, 'launch'), /Failed to resolve plugin stealth\/evasions\/typo/);
});
test('custom plugins resolve dependencies, merge options and fail hooks visibly', async () => {
  const calls = [];
  class Custom extends Plugin {
    get dependencies() { return new Set(['dependency']); }
    async beforeLaunch(options) { calls.push(this.opts.value); return {...options, devtools:true}; }
    async onPageCreated() { throw new Error('hook failed'); }
  }
  class Dependency extends Plugin { get name() {return 'dependency';} async beforeLaunch() {calls.push('dependency');} }
  const adapter = new PluginAdapter({custom: options => new Custom(options), dependency: () => new Dependency()});
  assert.equal((await adapter.prepare([{name:'custom',options:{value:42}}], {}, 'launch')).devtools, true);
  assert.deepEqual(calls, ['dependency',42]);
  await assert.rejects(adapter.hook('onPageCreated', {}), /custom.onPageCreated: hook failed/);
});
test('resolver loads missing plugin factories once, including shared dependencies', async () => {
  class Root extends Plugin { get name() { return 'root'; } get dependencies() { return new Set(['dependency']); } }
  class Other extends Plugin { get name() { return 'other'; } get dependencies() { return new Set(['dependency']); } }
  class Dependency extends Plugin { get name() { return 'dependency'; } }
  const requested = [];
  const adapter = new PluginAdapter({root: () => new Root(), other: () => new Other()}, name => {
    requested.push(name);
    return name === 'dependency' ? () => new Dependency() : undefined;
  });
  await adapter.prepare([{name:'root'}, {name:'other'}], {}, 'launch');
  assert.deepEqual(requested, ['dependency']);
  assert.deepEqual(Array.from(adapter.plugins, plugin => plugin.name), ['dependency', 'root', 'other']);
});
test('resolver failures identify the requested plugin', async () => {
  await assert.rejects(
    new PluginAdapter({}, () => undefined).prepare([{name:'missing'}], {}, 'launch'),
    /Plugin is not available: missing/,
  );
  await assert.rejects(
    new PluginAdapter({}, () => {throw new Error('module not found');}).prepare([{name:'missing'}], {}, 'launch'),
    /Failed to resolve plugin missing: module not found/,
  );
});
test('plugin instances from runtime exports keep identity and resolve dependencies', async () => {
  class Exported extends Plugin { get name() { return 'exported'; } get dependencies() { return new Set(['dependency']); } }
  class Dependency extends Plugin { get name() { return 'dependency'; } }
  const exported = new Exported();
  const requested = [];
  const adapter = new PluginAdapter({}, name => {
    requested.push(name);
    return name === 'dependency' ? () => new Dependency() : undefined;
  });
  await adapter.prepare([{plugin: exported}], {}, 'launch');
  assert.equal(adapter.plugins[1], exported);
  assert.deepEqual(requested, ['dependency']);
  await assert.rejects(new PluginAdapter().prepare([{plugin:{name:'invalid'}}], {}, 'launch'), /Not a PuppeteerExtraPlugin: invalid/);
});
test('page initialization completes once before page result becomes usable', async () => {
  let count = 0;
  class Custom extends Plugin { async onPageCreated(page) { await new Promise(resolve => setTimeout(resolve, 10)); page.ready = ++count; } }
  const adapter = new PluginAdapter({custom: () => new Custom()});
  await adapter.prepare([{name:'custom'}], {}, 'connect');
  const page = {target(){return {};}, once(){}};
  await Promise.all([adapter.page(page), adapter.page(page)]);
  assert.equal(page.ready, 1);
});
test('unsupported requirement and dependency cycles fail explicitly', async () => {
  class Required extends Plugin { get requirements(){return new Set(['nodeFilesystem']);} }
  await assert.rejects(new PluginAdapter({custom:()=>new Required()}).prepare([{name:'custom'}],{},'connect'), /Unsupported plugin requirement/);
  class Cycle extends Plugin {get dependencies(){return new Set(['custom']);}}
  await assert.rejects(new PluginAdapter({custom:()=>new Cycle()}).prepare([{name:'custom'}],{},'launch'), /Circular/);
});
test('resolver loads a custom plugin with configured options', async () => {
  class Marker extends Plugin {
    get name() { return 'marker'; }
    async beforeLaunch(options) { return {...options, defaultViewport: {width: 640, height: 480}}; }
    async onPageCreated(page) { await page.evaluateOnNewDocument(() => {}, this.opts.value); }
  }
  const adapter = new PluginAdapter({}, name => name === 'marker' ? options => new Marker(options) : undefined);
  const options = await adapter.prepare([{name:'marker',options:{value:42}}],{},'launch');
  assert.equal(options.defaultViewport.width, 640);
  let value;
  await adapter.hook('onPageCreated', {evaluateOnNewDocument: async (fn,arg) => {value=arg;}});
  assert.equal(value,42);
});

test('headful launch uses the documented CDP locale adaptation', async () => {
  const adapter = new PluginAdapter({}, resolveStealth);
  await adapter.prepare([{name:'stealth/evasions/user-agent-override',options:{locale:'de-DE,de'}}],{headless:false},'launch');
  assert.equal(adapter.plugins[0]._headless,true);
  const sent = [];
  await adapter.hook('onPageCreated',{browser:()=>({userAgent:async()=> 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36'}), _client:()=>({send:(...args)=>sent.push(args)})});
  assert.equal(sent[0][1].acceptLanguage,'de-DE,de');
});

test('explicit evasion configuration is respected when stealth resolves dependencies', async () => {
  const adapter = new PluginAdapter({}, resolveStealth);
  await adapter.prepare([{name:'stealth'}, {name:'stealth/evasions/navigator.hardwareConcurrency',options:{hardwareConcurrency:8}}],{},'connect');
  assert.equal(adapter.plugins.find(plugin=>plugin.name === 'stealth/evasions/navigator.hardwareConcurrency').opts.hardwareConcurrency,8);
});
