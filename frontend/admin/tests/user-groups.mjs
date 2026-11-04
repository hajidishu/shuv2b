import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { startFixture } from './fixture.mjs';
import { mkdir,writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';

if(process.env.ADMIN_LIVE_URL)throw new Error('user-groups.mjs is fixture-only');
const fixture=await startFixture(),output=resolve(import.meta.dirname,'../test-results/user-groups');await mkdir(output,{recursive:true});
fixture.store.groups.push({id:1,name:'套餐权限组'},{id:13,name:'独立权限组'});
fixture.store.plans.push({id:11,name:'原套餐',group_id:1,show:1,renew:1,transfer_enable:100,month_price:1000});
fixture.store.users.push({id:101,email:'custom-group@example.test',plan_id:11,group_id:'1',group_mode:0,balance:0,commission_balance:0,transfer_enable:107374182400,u:0,d:0,banned:0,is_admin:0,is_staff:0,expired_at:null});
const browser=await chromium.launch({executablePath:process.env.ADMIN_BROWSER_PATH??'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
const page=await browser.newPage({viewport:{width:1440,height:1000},locale:'zh-CN'}),errors=[];
page.on('pageerror',error=>errors.push(error.message));
const passed=[];
async function choose(id,label){
 const input=page.locator('#'+id);await input.locator('xpath=ancestor::div[contains(concat(" ",normalize-space(@class)," ")," ant-select ")][1]').locator('.ant-select-selector').click();
 const list=await input.getAttribute('aria-controls');
 await page.locator('.ant-select-dropdown').filter({has:page.locator(`[id="${list}"]`)}).locator('.ant-select-item-option-content').getByText(label,{exact:true}).click();
}
async function edit(){
 await page.goto(fixture.origin+'/#/user',{waitUntil:'networkidle'});await page.locator('tr[data-row-key="101"] .row-menu-link').click();
 await page.locator('.ant-dropdown:visible').getByText('编辑',{exact:true}).click();
 const editor=page.locator('.ant-drawer-open').filter({has:page.locator('.user-editor')});await editor.waitFor();return editor;
}
async function save(editor){
 const pending=page.waitForResponse(r=>r.url().endsWith('/user/update'));await editor.getByRole('button',{name:'保存',exact:true}).click();
 const response=await pending;assert.ok(response.ok());await editor.waitFor({state:'hidden'});return response.request().postDataJSON();
}
try{
 await page.addInitScript(()=>localStorage.setItem('v2board-admin-v1:'+location.host+':test-admin','Bearer fixture-admin'));
 let editor=await edit();assert.equal(await editor.locator('#group_id').count(),0);
 await choose('group_mode','自定义权限组');await editor.locator('#group_id').waitFor();
 await choose('group_id','独立权限组');let data=await save(editor);
 assert.equal(data.plan_id,11);assert.equal(data.group_mode,1);assert.equal(data.group_id,13);passed.push('custom group is saved independently of the plan');
 fixture.store.users.find(u=>u.id===101).group_id='13';
 editor=await edit();assert.match(await editor.locator('.ant-form-item').filter({has:page.locator('#group_id')}).innerText(),/独立权限组/);
 await editor.locator('#balance').fill('5');data=await save(editor);assert.equal(data.group_mode,1);assert.equal(data.group_id,13);passed.push('custom selection keeps reference names and survives unrelated edits');
 editor=await edit();await choose('group_mode','跟随套餐权限组');assert.equal(await editor.locator('#group_id').count(),0);
 data=await save(editor);assert.equal(data.group_mode,0);assert.equal(Object.hasOwn(data,'group_id'),false);passed.push('following mode omits hidden custom values');
 await page.goto(fixture.origin+'/#/config/system',{waitUntil:'networkidle'});await page.getByRole('tab',{name:'订阅',exact:true}).click();
 const setting=page.locator('#reset_custom_group_on_purchase');await setting.waitFor();assert.equal(await setting.getAttribute('aria-checked'),'false');
 const saved=page.waitForResponse(r=>r.url().endsWith('/config/save')&&r.request().postDataJSON().reset_custom_group_on_purchase===1);
 await setting.click();assert.ok((await saved).ok());await page.reload({waitUntil:'networkidle'});await page.getByRole('tab',{name:'订阅',exact:true}).click();
 assert.equal(await setting.getAttribute('aria-checked'),'true');passed.push('purchase reset policy defaults off and persists under subscription settings');
 await page.setViewportSize({width:390,height:844});editor=await edit();await choose('group_mode','自定义权限组');await editor.locator('#group_id').waitFor();
 assert.ok((await editor.boundingBox()).width<=390);passed.push('user group controls remain usable at a mobile width');
 assert.deepEqual(errors,[]);console.log(passed.map(s=>'PASS '+s).join('\n'));
 await page.screenshot({path:resolve(output,'mobile.png'),fullPage:true});
}finally{await writeFile(resolve(output,'report.json'),JSON.stringify({passed,errors},null,2));await browser.close();await fixture.close();}
