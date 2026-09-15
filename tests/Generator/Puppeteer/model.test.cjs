'use strict';
const {test}=require('node:test');
const assert=require('node:assert/strict');
const {buildPhpModel}=require('../../../tools/upstream/php-model.cjs');
const {inside}=require('../../../tools/upstream/model.cjs');
function model(type={text:'string'}, options={}) {
 return buildPhpModel({api:{types:[],exports:[],classes:[{name:'Page',members:[{id:'Page.title',name:'title',kind:'method',signatures:[{parameters:[],returnType:type}],...options}]}]},config:{namespace:'Nesk\\Puphpeteer\\Puppeteer',wrapperClasses:['Page'],types:{undefined:{native:'null',psalm:'null'}},members:{}}});
}
test('remote methods return the resolved value',()=>assert.equal(model().classes[0].members[0].returnDocType,'string'));
test('Promise result is unwrapped',()=>assert.equal(model({name:'Promise',text:'Promise<string>',typeArguments:[{text:'string'}]}).classes[0].members[0].returnType,'string'));
test('unsupported types omit methods, never silently become supported mixed',()=>{const m=model({text:'UnimplementedStream'});assert.equal(m.classes[0].members.length,0);assert.equal(m.coverage[0].status,'unsupported');});
test('unselected overloads are reported',()=>assert.equal(model(undefined,{signatures:[{parameters:[{name:'a',type:{text:'string'}}],returnType:{text:'void'}},{parameters:[{name:'b',type:{text:'string'}}],returnType:{text:'void'}}]}).coverage[0].status,'unsupported'));
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

test('compatible overloads combine types, returns and optional trailing parameters', () => {
 const m=model(undefined,{signatures:[
  {parameters:[{name:'value',type:{text:'string'}}],returnType:{text:'string'}},
  {parameters:[{name:'value',type:{text:'number'}},{name:'options',type:{text:'boolean'}}],returnType:{text:'number'}},
 ]});
 assert.deepEqual(m.diagnostics,[]);
 assert.equal(m.classes[0].members[0].parameters[0].type,'string|int|float');
 assert.equal(m.classes[0].members[0].parameters[1].optional,true);
 assert.equal(m.classes[0].members[0].returnType,'string|int|float');
});
test('JS statics remain PHP instance methods with explicit transport routing',()=>{
 const member=model(undefined,{static:true}).classes[0].members[0];
 assert.equal(member.static,false); assert.equal(member.remoteStatic,true);
});
test('writable properties carry a setter contract',()=>{
 const member=model(undefined,{kind:'property',readonly:false,type:{text:'number'}}).classes[0].members[0];
 assert.equal(member.writable,true);
});

test('function-valued properties generate callable PHP methods', () => {
 const member=model(undefined,{kind:'property',type:{kind:'function',signatures:[{parameters:[],returnType:{text:'string'}}]}}).classes[0].members[0];
 assert.equal(member.kind,'method');
 assert.equal(member.returnType,'string');
});

test('generic aliases substitute outer parameters without self recursion', () => {
 const {createTypeMapper}=require('../../../tools/upstream/type-mapper.cjs');
 const diagnostics=[];
 const mapper=createTypeMapper({types:[{name:'Callback',typeParameters:[{name:'T'}],type:{kind:'function',signatures:[{parameters:[{name:'value',type:{kind:'typeParameter',text:'T'}}],returnType:{kind:'typeParameter',text:'T'}}]}}],config:{},classMap:new Map(),requireClass:()=>{},report:(...args)=>diagnostics.push(args)});
 const result=mapper.map({name:'Callback',text:'Callback<T>',typeArguments:[{kind:'typeParameter',text:'T'}]},'test',{templates:new Map([['T',{substitution:{text:'string'}}]])});
 assert.equal(result.psalm,'\\Closure(string):string');
 assert.deepEqual(diagnostics,[]);
});

test('unnamed structural types do not match the undefined type override', () => {
 const {createTypeMapper}=require('../../../tools/upstream/type-mapper.cjs');
 const mapper=createTypeMapper({types:[],config:{types:{undefined:{native:'null',psalm:'null'}}},classMap:new Map(),requireClass:()=>{},report:()=>assert.fail('Unexpected unsupported type')});
 assert.deepEqual(mapper.map({text:'undefined'},'test'),{native:'null',psalm:'null'});
 assert.deepEqual(mapper.map({kind:'union',types:[{text:'string'},{text:'number'}]},'test'),{native:'string|int|float',psalm:'string|int|float'});
 assert.deepEqual(mapper.map({properties:[{name:'content',type:{text:'string'}}]},'test'),{native:'array',psalm:'array{content: string}'});
});
