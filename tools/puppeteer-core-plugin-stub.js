export class PluginAdapter {
  check() {}
  page(page) { return page; }
  connect(puppeteer, options, transport) {
    return puppeteer.connect({...options, transport});
  }
  prepare() {
    throw new Error('Plugins require the full Puppeteer bundle');
  }
}
