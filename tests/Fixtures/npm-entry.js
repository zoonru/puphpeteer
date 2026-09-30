import stealth from 'puppeteer-extra-plugin-stealth';
import {newInjectedPage} from 'fingerprint-injector';
import {FingerprintGenerator} from 'fingerprint-generator';

export {stealth, newInjectedPage};
export const twice = value => value * 2;
export const fingerprintUserAgent = () => new FingerprintGenerator()
  .getFingerprint({browsers: ['chrome']}).fingerprint.navigator.userAgent;
