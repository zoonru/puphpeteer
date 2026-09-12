import {registry} from './registry.js';
import customPlugins from 'puphpeteer-custom-plugins';

/** Browser-side subset of puppeteer-extra: native process/profile ownership stays in PHP. */
export class PluginAdapter {
  constructor(factories = null) {
    if (factories === null) {
      for (const name of Object.keys(customPlugins)) {
        if (Object.hasOwn(registry, name)) throw new Error(`Reserved bundled plugin name: ${name}`);
      }
      factories = {...registry, ...customPlugins};
    }
    this.factories = factories;
    this.plugins = [];
    this.pages = new WeakMap();
    this.targets = new WeakSet();
    this.failure = null;
  }

  async prepare(definitions, options, mode) {
    if (this.prepared) throw new Error('Plugins have already been prepared');
    if (!['launch', 'connect'].includes(mode)) throw new Error('Invalid plugin lifecycle mode');
    this.prepared = true;
    this.mode = mode;
    this.options = {...options};
    const visiting = new Set();
    const configurations = new Map();
    for (const definition of definitions) {
      const name = definition.name.replace(/^puppeteer-extra-plugin-/, '');
      if (configurations.has(name)) throw new Error(`Duplicate plugin registration: ${name}`);
      configurations.set(name, definition.options || {});
    }
    const add = name => {
      name = name.replace(/^puppeteer-extra-plugin-/, '');
      let opts = configurations.get(name) || {};
      if (this.plugins.some(plugin => plugin.name === name)) return;
      if (visiting.has(name)) throw new Error(`Circular plugin dependency: ${name}`);
      const factory = Object.hasOwn(this.factories, name) ? this.factories[name] : undefined;
      if (typeof factory !== 'function') throw new Error(`Plugin is not bundled: ${name}`);
      if (name === 'stealth' && opts.enabledEvasions) opts = {...opts, enabledEvasions: new Set(opts.enabledEvasions)};
      const plugin = factory(opts);
      if (!plugin || plugin._isPuppeteerExtraPlugin !== true) throw new Error(`Not a PuppeteerExtraPlugin: ${name}`);
      for (const requirement of plugin.requirements) {
        if (!['runLast', 'dataFromPlugins', 'launch', 'headful'].includes(requirement)) throw new Error(`Unsupported plugin requirement: ${requirement}`);
        if (requirement === 'launch' && mode !== 'launch') throw new Error(`${name} requires launch()`);
        if (requirement === 'headful' && (mode !== 'launch' || options.headless !== false)) throw new Error(`${name} requires headless: false`);
      }
      if (plugin.dependencyOptions !== undefined) throw new Error(`Plugin dependencyOptions are not supported: ${name}`);
      visiting.add(name);
      for (const dependency of plugin.dependencies) {
        // This upstream dependency writes Node-owned profiles. The UA evasion's CDP
        // language override serves both launch modes here; PHP owns the profile.
        if (name === 'stealth/evasions/user-agent-override' && dependency === 'user-preferences') continue;
        add(dependency);
      }
      visiting.delete(name);
      plugin.getDataFromPlugins = dataName => this.plugins.flatMap(item => item.data || []).filter(item => !dataName || item.name === dataName);
      this.plugins.push(plugin);
    };
    for (const name of configurations.keys()) add(name);
    this.plugins.sort((a, b) => Number(a.requirements.has('runLast')) - Number(b.requirements.has('runLast')));
    for (const plugin of this.plugins) await plugin.onPluginRegistered?.();
    if (mode === 'launch') this.options = {args: [], headless: true, ...this.options};
    for (const plugin of this.plugins) {
      this.options = await plugin[mode === 'launch' ? 'beforeLaunch' : 'beforeConnect']?.(this.options) || this.options;
      if (plugin.name === 'stealth/evasions/user-agent-override') plugin._headless = true;
    }
    return this.options;
  }

  async hook(name, ...args) {
    for (const plugin of this.plugins) {
      try { await plugin[name]?.(...args); }
      catch (cause) { throw new Error(`Plugin ${plugin.name}.${name}: ${cause.message}`, {cause}); }
    }
  }

  check() { if (this.failure) throw this.failure; }

  async page(page, target = page?.target()) {
    if (!page || !this.plugins.length) return page;
    let ready = this.pages.get(page);
    if (!ready) {
      ready = this.hook('onPageCreated', page, target).catch(error => {
        // A target can close while its scripts are being installed (including
        // pages owned by another browser connection). That is ordinary teardown.
        if (!page.isClosed() || !/Target closed|Session closed/.test(error.message)) throw error;
      });
      this.pages.set(page, ready);
      page.once('close', () => { this.hook('onPageClose', page).catch(error => { this.failure = error; }); });
    }
    await ready;
    return page;
  }

  async target(target) {
    if (this.targets.has(target)) return;
    this.targets.add(target);
    await this.hook('onTargetCreated', target);
    if (target.type() === 'page') await this.page(await target.page(), target);
  }

  async connect(puppeteer, options, transport) {
    this.check();
    const browser = await puppeteer.connect({...options, transport});
    if (!this.plugins.length) return browser;
    const background = work => work.catch(error => { this.failure = error; });
    browser.on('targetcreated', target => background(this.target(target)));
    browser.on('targetchanged', target => background(this.hook('onTargetChanged', target)));
    browser.on('targetdestroyed', target => background(this.hook('onTargetDestroyed', target)));
    browser.once('disconnected', () => background(this.hook('onDisconnected', browser)));
    // Same creation boundary patched by puppeteer-extra: newPage resolves only
    // after scripts are installed. Includes incognito BrowserContext.newPage().
    const close = browser.close;
    browser.close = async (...args) => {
      try { await this.hook('onClose'); }
      finally { await close.apply(browser, args); }
    };
    const createPage = browser._createPageInContext;
    if (typeof createPage !== 'function') throw new Error('Plugin adapter requires CDP Browser._createPageInContext');
    browser._createPageInContext = async (...args) => this.page(await createPage.apply(browser, args));
    await this.hook(this.mode === 'launch' ? 'afterLaunch' : 'afterConnect', browser, this.options);
    await this.hook('onBrowser', browser, {context: this.mode, options: this.options});
    for (const target of browser.targets()) await this.target(target);
    this.check();
    return browser;
  }
}
