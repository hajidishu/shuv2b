import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, copyFileSync, existsSync, realpathSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { execFileSync } from 'node:child_process';
const repo=resolve(import.meta.dirname,'..'),scratch=realpathSync.native(mkdtempSync(join(tmpdir(),'v2board-profile-export-')));
const source=join(scratch,'source'),target=join(scratch,'deployment');
const put=(path,text)=>{mkdirSync(resolve(path,'..'),{recursive:true});writeFileSync(path,text)};
const git=(cwd,...args)=>execFileSync('git',['-c',`safe.directory=${cwd.replaceAll('\\','/')}`,...args],{cwd,encoding:'utf8',stdio:['ignore','pipe','pipe']});
for(const root of [source,target]){mkdirSync(root);git(root,'init');git(root,'config','user.name','Profile export fixture');git(root,'config','user.email','fixture@example.test')}
for(const name of ['export-deployment.mjs','runtime-files.mjs']){
 const path=join(source,'frontend/admin/scripts',name);mkdirSync(resolve(path,'..'),{recursive:true});copyFileSync(join(repo,'frontend/admin/scripts',name),path)
}
put(join(source,'frontend/admin/package.json'),'{"version":"fixture"}');
put(join(source,'public/assets/admin-v2/manifest.json'),JSON.stringify({'index.html':{file:'assets/current.js'}}));
for(const name of ['index.html','build-info.json','assets/current.js'])put(join(source,'public/assets/admin-v2',name),'fixture');
put(join(source,'.gitignore'),'composer.lock\n.v2board-runtime.json\n.env\nconfig/v2board.php\n');
put(join(source,'composer.json'),'{"name":"v2board/v2board"}');
const published=['composer.fpm.json','composer.fpm.lock','composer.fpm-php80.json','composer.fpm-php80.lock','composer.webman.json','composer.webman.lock','composer.profile-audit.json'];
for(const name of published)put(join(source,name),'published '+name);
git(source,'add','.');git(source,'commit','-m','fixture source');
put(join(source,'composer.lock'),'source developer instance lock');
put(join(source,'.v2board-runtime.json'),'{"runtime":"private-instance"}');
put(join(source,'config/v2board.php'),'source private configuration');
put(join(target,'marker'),'existing release');git(target,'add','.');git(target,'commit','-m','fixture target');
put(join(target,'composer.lock'),'server instance lock');
put(join(target,'.v2board-runtime.json'),'{"runtime":"webman"}');
put(join(target,'config/v2board.php'),'server private configuration');
execFileSync(process.execPath,[join(source,'frontend/admin/scripts/export-deployment.mjs'),target],{encoding:'utf8',stdio:['ignore','pipe','pipe']});
for(const name of published)assert.equal(readFileSync(join(target,name),'utf8'),'published '+name);
assert.equal(readFileSync(join(target,'composer.lock'),'utf8'),'server instance lock');
assert.equal(readFileSync(join(target,'.v2board-runtime.json'),'utf8'),'{"runtime":"webman"}');
assert.equal(readFileSync(join(target,'config/v2board.php'),'utf8'),'server private configuration');
assert(!existsSync(join(target,'frontend')));
assert.equal(JSON.parse(readFileSync(join(target,'release.json'),'utf8')).source_commit,git(source,'rev-parse','HEAD').trim());
console.log('PASS release exports all published runtime locks without exporting instance configuration, locks or frontend source');
