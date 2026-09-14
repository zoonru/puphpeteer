'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const {buildPhpModel}=require('../../../tools/upstream/php-model.cjs');
const {inside}=require('../../../tools/upstream/model.cjs');
function model(type={text:'string'}, options={}) {
 return buildPhpModel({api:{types:[],exports:[],classes:[{name:'Page',members:[{id:'Page.title',name:'title',kind:'method',signatures:[{parameters:[],returnType:type}],...options}]}]},config:{namespace:'Nesk\\Puphpeteer\\Puppeteer',wrapperClasses:['Page'],types:{},members:{}}});
}
test('remote methods return the resolved value',()=>assert.equal(model().classes[0].members[0].returnDocType,'string'));
test('Promise result is unwrapped',()=>assert.equal(model({name:'Promise',text:'Promise<string>',typeArguments:[{text:'string'}]}).classes[0].members[0].returnType,'string'));
test('unsupported types omit methods, never silently become supported mixed',()=>{const m=model({text:'UnimplementedStream'});assert.equal(m.classes[0].members.length,0);assert.equal(m.coverage[0].status,'unsupported');});
test('unselected overloads are reported',()=>assert.equal(model(undefined,{signatures:[{},{}]}).coverage[0].status,'unsupported'));
test('generated paths cannot leave project',()=>assert.throws(()=>inside('/tmp/project','../foreign.php'),/escapes/));
test('optional arguments are nullable but preserve omission metadata',()=>{const m=model(undefined,{signatures:[{parameters:[{name:'options',optional:true,type:{text:'string'}}],returnType:{text:'void'}}]});const p=m.classes[0].members[0].parameters[0];assert.equal(p.default,null);assert.equal(p.optional,true);assert.equal(p.type,'string|null');});
const {validateConfig}=require('../../../tools/upstream/model.cjs');
const {resolveSources}=require('../../../tools/upstream/source-resolver.cjs');
const fs=require('node:fs');
const os=require('node:os');
const path=require('node:path');
test('config rejects unknown schema and duplicate wrappers',()=>{
 assert.throws(()=>validateConfig({schemaVersion:2,namespace:'Nesk\\Puphpeteer\\Puppeteer',wrapperClasses:[]}),/schema/);
 assert.throws(()=>validateConfig({schemaVersion:1,namespace:'Nesk\\Puphpeteer\\Puppeteer',wrapperClasses:['Page','Page']}),/wrapperClasses/);
});
test('source resolution rejects npm pin mismatch before any fetch',async()=>{
 const root=fs.mkdtempSync(path.join(os.tmpdir(),'quickjs-source-test-'));
 try {fs.writeFileSync(path.join(root,'package.json'),JSON.stringify({devDependencies:{'puppeteer-core':'24.36.1'}}));
 fs.writeFileSync(path.join(root,'package-lock.json'),JSON.stringify({lockfileVersion:3,packages:{'':{devDependencies:{'puppeteer-core':'24.36.1'}},'node_modules/puppeteer-core':{version:'24.35.0'}}}));
 await assert.rejects(resolveSources({root,offline:true}),/does not pin/);
 } finally {fs.rmSync(root,{recursive:true,force:true});}
});
