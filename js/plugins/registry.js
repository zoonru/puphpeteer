// Static imports keep npm resolution and Node.js out of the runtime.
import stealth from 'puppeteer-extra-plugin-stealth';
import evasion0 from 'puppeteer-extra-plugin-stealth/evasions/chrome.app/index.js';
import evasion1 from 'puppeteer-extra-plugin-stealth/evasions/chrome.csi/index.js';
import evasion2 from 'puppeteer-extra-plugin-stealth/evasions/chrome.loadTimes/index.js';
import evasion3 from 'puppeteer-extra-plugin-stealth/evasions/chrome.runtime/index.js';
import evasion4 from 'puppeteer-extra-plugin-stealth/evasions/defaultArgs/index.js';
import evasion5 from 'puppeteer-extra-plugin-stealth/evasions/iframe.contentWindow/index.js';
import evasion6 from 'puppeteer-extra-plugin-stealth/evasions/media.codecs/index.js';
import evasion7 from 'puppeteer-extra-plugin-stealth/evasions/navigator.hardwareConcurrency/index.js';
import evasion8 from 'puppeteer-extra-plugin-stealth/evasions/navigator.languages/index.js';
import evasion9 from 'puppeteer-extra-plugin-stealth/evasions/navigator.permissions/index.js';
import evasion10 from 'puppeteer-extra-plugin-stealth/evasions/navigator.plugins/index.js';
import evasion11 from 'puppeteer-extra-plugin-stealth/evasions/navigator.webdriver/index.js';
import evasion12 from 'puppeteer-extra-plugin-stealth/evasions/sourceurl/index.js';
import evasion13 from 'puppeteer-extra-plugin-stealth/evasions/user-agent-override/index.js';
import evasion14 from 'puppeteer-extra-plugin-stealth/evasions/webgl.vendor/index.js';
import evasion15 from 'puppeteer-extra-plugin-stealth/evasions/window.outerdimensions/index.js';
import evasion16 from 'puppeteer-extra-plugin-stealth/evasions/navigator.vendor/index.js';

export const registry = {
  stealth,
  'stealth/evasions/chrome.app': evasion0,
  'stealth/evasions/chrome.csi': evasion1,
  'stealth/evasions/chrome.loadTimes': evasion2,
  'stealth/evasions/chrome.runtime': evasion3,
  'stealth/evasions/defaultArgs': evasion4,
  'stealth/evasions/iframe.contentWindow': evasion5,
  'stealth/evasions/media.codecs': evasion6,
  'stealth/evasions/navigator.hardwareConcurrency': evasion7,
  'stealth/evasions/navigator.languages': evasion8,
  'stealth/evasions/navigator.permissions': evasion9,
  'stealth/evasions/navigator.plugins': evasion10,
  'stealth/evasions/navigator.webdriver': evasion11,
  'stealth/evasions/sourceurl': evasion12,
  'stealth/evasions/user-agent-override': evasion13,
  'stealth/evasions/webgl.vendor': evasion14,
  'stealth/evasions/window.outerdimensions': evasion15,
  'stealth/evasions/navigator.vendor': evasion16,
};
