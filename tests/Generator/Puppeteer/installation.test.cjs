'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const root = path.resolve(__dirname, '../../..');
const manifest = require(path.join(root, 'package.json'));
const lock = require(path.join(root, 'upstream/lock.json'));
const skipFlags = ['PUPPETEER_SKIP_DOWNLOAD', 'PUPPETEER_CHROME_SKIP_DOWNLOAD', 'PUPPETEER_SKIP_CHROME_DOWNLOAD'];
function run(cmd, args, cwd, extra = {}) {
 const env = {...process.env, npm_config_offline:'true', ...extra};
 for (const flag of skipFlags) if (!(flag in extra)) delete env[flag];
 return spawnSync(cmd, args, {cwd, env, encoding:'utf8', timeout:60000});
}

test('installer honors skip flags without npm dev dependencies and passes the locked Chrome to the official CLI', () => {
 const app = fs.mkdtempSync(path.join(os.tmpdir(), 'puphpeteer-install-'));
 try {
  fs.writeFileSync(path.join(app,'package.json'), JSON.stringify({private:true,scripts:manifest.scripts}));
  fs.mkdirSync(path.join(app,'upstream'));
  fs.writeFileSync(path.join(app,'upstream/lock.json'), JSON.stringify(lock));
  for (const flag of skipFlags) {
   const result = run('npm',['run','browser:install'],app,{[flag]:'true'});
   assert.equal(result.status,0,result.stderr);
   assert.equal(fs.existsSync(path.join(app,'node_modules')),false);
  }
  // The CLI double records invocation without downloading Chrome; Puppeteer itself is absent.
  const pkg = path.join(app,'node_modules/@puppeteer/browsers');
  fs.mkdirSync(pkg,{recursive:true}); fs.mkdirSync(path.join(app,'node_modules/.bin'));
  fs.writeFileSync(path.join(pkg,'package.json'),JSON.stringify({name:'@puppeteer/browsers',version:manifest.devDependencies['@puppeteer/browsers'],bin:{browsers:'cli.cjs'}}));
  fs.writeFileSync(path.join(pkg,'cli.cjs'), '#!/usr/bin/env node\nrequire("node:fs").writeFileSync("invocation.json",JSON.stringify(process.argv.slice(2)));');
  fs.chmodSync(path.join(pkg,'cli.cjs'),0o755);
  fs.symlinkSync('../@puppeteer/browsers/cli.cjs',path.join(app,'node_modules/.bin/browsers'));
  const result = run('npm',['run','browser:install'],app,{PUPPETEER_SKIP_DOWNLOAD:'false'});
  assert.equal(result.status,0,result.stdout+result.stderr);
  assert.deepEqual(JSON.parse(fs.readFileSync(path.join(app,'invocation.json'))),['install','chrome@'+lock.package.chromeBuildId,'--path','node_modules/.puphpeteer']);
 } finally { fs.rmSync(app,{recursive:true,force:true}); }
});

test('Composer consumer without dev dependencies uses its autoloader, vendor CLI and managed browser', () => {
 const app = fs.mkdtempSync(path.join(os.tmpdir(),'puphpeteer-consumer-'));
 try {
  const installed = JSON.parse(fs.readFileSync(path.join(root,'vendor/composer/installed.json')));
  const snapshot = path.join(app, 'package'); fs.mkdirSync(snapshot);
  for (const file of ['composer.json','package.json','src','resources','bin']) fs.cpSync(path.join(root,file),path.join(snapshot,file),{recursive:true});
  const repository = pkg => pkg['install-path']
   ? {type:'path',url:path.resolve(root,'vendor/composer',pkg['install-path']),options:{symlink:true,versions:{[pkg.name]:pkg.version}}}
   : {type:'package',package:Object.fromEntries(['name','version','type','require','provide','replace','conflict'].filter(k=>pkg[k]!==undefined).map(k=>[k,pkg[k]]))};
  const repositories = [{type:'path',url:snapshot,options:{symlink:true,versions:{'zoon/puphpeteer':'dev-fixture'}}}];
  for (const pkg of installed.packages.filter(p=>!p['dev_requirement'])) {
   repositories.push(repository(pkg));
  }
  repositories.push({'packagist.org':false});
  fs.writeFileSync(path.join(app,'composer.json'),JSON.stringify({require:{'zoon/puphpeteer':'dev-fixture'},'require-dev':{'phpunit/phpunit':'^11'},repositories,'minimum-stability':'dev',config:{'allow-plugins':false},autoload:{'psr-4':{'Consumer\\':'src/'}}}));
  // Lock resolution also needs root dev packages; make them locally available, then omit installation.
  const config=JSON.parse(fs.readFileSync(path.join(app,'composer.json')));
  config.repositories.splice(-1,0,...installed.packages.filter(p=>p['dev_requirement']).map(repository));
  fs.writeFileSync(path.join(app,'composer.json'),JSON.stringify(config));
  fs.mkdirSync(path.join(app,'src'));
  fs.writeFileSync(path.join(app,'src/Marker.php'),'<?php namespace Consumer; final class Marker {}');
  const install=run('composer',['update','--no-dev','--no-interaction','--no-plugins','--no-scripts','--ignore-platform-req=ext-php_quickjs'],app);
  assert.equal(install.status,0,install.stdout+install.stderr);
  assert.equal(fs.existsSync(path.join(app,'vendor/phpunit/phpunit')),false);
  const cli=run('php',['vendor/bin/console','list','--raw'],app);
  assert.equal(cli.status,0,cli.stderr);
  assert.match(cli.stdout,/browser:install/);
  const cache=path.join(app,'node_modules/.puphpeteer/chrome/test-build'); fs.mkdirSync(cache,{recursive:true});
  fs.writeFileSync(path.join(cache,'chrome'),'#!/bin/sh\nexit 0\n'); fs.chmodSync(path.join(cache,'chrome'),0o755);
  const probe=run('php',['-r','require "vendor/autoload.php"; if (!class_exists("Consumer\\\\Marker")) exit(2); echo Nesk\\Puphpeteer\\Internal\\BrowserExecutable::resolve($argv[1]);', path.join(app,'vendor/zoon/puphpeteer')],app,{PUPPETEER_EXECUTABLE_PATH:''});
  assert.equal(probe.status,0,probe.stderr); assert.equal(probe.stdout,path.join(cache,'chrome'));
 } finally { fs.rmSync(app,{recursive:true,force:true}); }
});
