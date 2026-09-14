'use strict';
const fs = require('node:fs');
const path = require('node:path');

function updateBadges(content, {version, chromeBuildId}) {
 const badges = [
  `[![Puppeteer](https://img.shields.io/badge/Puppeteer-${encodeURIComponent(version)}-40B5A4?logo=puppeteer)](https://github.com/puppeteer/puppeteer/releases/tag/puppeteer-v${encodeURIComponent(version)})`,
  `[![Chrome](https://img.shields.io/badge/Chrome-${encodeURIComponent(chromeBuildId)}-4285F4?logo=googlechrome)](https://googlechromelabs.github.io/chrome-for-testing/)`,
 ].join('\n');
 const existing = /^\[!\[Puppeteer\]\(https:\/\/img\.shields\.io\/badge\/Puppeteer-[^\n]+\n\[!\[Chrome\]\(https:\/\/img\.shields\.io\/badge\/Chrome-[^\n]+/m;
 return existing.test(content) ? content.replace(existing, badges) : content.replace(/^(# [^\n]+\n)/, `$1\n${badges}\n`);
}

function generateReadmeBadges(root, lock) {
 return ['README.md', 'README-RU.md'].filter(file => fs.existsSync(path.join(root, file)))
  .map(file => ({path: file, content: updateBadges(fs.readFileSync(path.join(root, file), 'utf8'), lock.package)}));
}

module.exports = {generateReadmeBadges, updateBadges};
