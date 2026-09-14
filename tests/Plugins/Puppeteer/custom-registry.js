import {PuppeteerExtraPlugin} from 'puppeteer-extra-plugin';
class Marker extends PuppeteerExtraPlugin {
  get name() { return 'marker'; }
  async beforeLaunch(options) { return {...options, defaultViewport: {width: 640, height: 480}}; }
  async onPageCreated(page) {
    await page.evaluateOnNewDocument(value => { globalThis.pluginMarker = value; }, this.opts.value);
  }
}
export default {marker: options => new Marker(options)};
