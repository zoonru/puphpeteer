'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const {updateBadges} = require('../../../tools/upstream/readme-badges.cjs');

test('README badges follow upstream versions without changing documentation or duplicating badges', () => {
 const original = '# PuPHPeteer\n\nDocumentation stays here.\n';
 const initial = updateBadges(original, {version: '25.11.0', chromeBuildId: '153.0.8010.36'});
 const next = {version: '26.0.0', chromeBuildId: '154.0.8100.1'};
 const updated = updateBadges(initial, next);
 assert.match(updated, /Puppeteer-26\.0\.0-/);
 assert.match(updated, /releases\/tag\/puppeteer-v26\.0\.0/);
 assert.match(updated, /Chrome-154\.0\.8100\.1-/);
 assert.doesNotMatch(updated, /25\.11\.0|153\.0\.8010\.36/);
 assert.equal(updated.split('[![Puppeteer]').length, 2);
 assert.ok(updated.endsWith('\n\nDocumentation stays here.\n'));
 assert.equal(updateBadges(updated, next), updated);
});
