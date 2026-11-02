import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { mkdir,writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { startFixture } from './fixture.mjs';

if(process.env.ADMIN_LIVE_URL)throw new Error('reference-labels.mjs is fixture-only');
const fixture=await startFixture();
fixture.store.groups.push({id:13,name:'权限组十三'});
fixture.store.routes.push({id:7,remarks:'路由七',action:'block',match:[]});
const common={type:'v2node',protocol:'vless',host:'node.example.test',port:'443',server_port:443,rate:2,show:1,tls:1,tls_settings:{server_name:'node.example.test',cert_mode:'none'},network:'tcp',disable_sni:0,zero_rtt_handshake:0};
fixture.store.nodes.push({...common,id:8,name:'父节点八',group_id:[13],route_id:[]},{...common,id:41,name:'旧节点',group_id:['13'],route_id:['7'],parent_id:'8'});
fixture.store.plans.push({id:16,name:'关联套餐',group_id:13,show:1,renew:1,transfer_enable:100,month_price:1000},{id:17,name:'备用套餐',group_id:13,show:1,renew:1,transfer_enable:100,month_price:2000});
fixture.store.users.push({id:101,email:'legacy@example.test',plan_id:'16',group_id:'13',banned:0,is_admin:0,is_staff:0,balance:0,commission_balance:0,u:0,d:0,transfer_enable:107374182400});
fixture.store.orders.push({id:301,user_id:101,email:'legacy@example.test',trade_no:'reference-order-fixture',plan_id:'16',plan_name:'关联套餐',period:'month_price',status:0,type:1,total_amount:1000,created_at:1700000000});
const output=resolve(import.meta.dirname,'../test-results/reference-labels');await mkdir(output,{recursive:true});
const report={passed:[],failures:[]};
const browser=await chromium.launch({executablePath:process.env.ADMIN_BROWSER_PATH??'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
const context=await browser.newContext({viewport:{width:1440,height:1100},locale:'zh-CN'}),page=await context.newPage();
page.on('pageerror',e=>report.failures.push({name:'runtime',message:e.message}));
const go=async path=>{await page.goto(fixture.origin+'/#'+path);await page.reload({waitUntil:'networkidle'});};
const field=(scope,id)=>scope.locator('#'+id).locator('xpath=ancestor::div[contains(concat(" ",normalize-space(@class)," ")," ant-form-item ")][1]');
const selected=async(scope,id)=>field(scope,id).locator('.ant-select-selection-item').allTextContents();
const check=async(name,fn)=>{try{await fn();report.passed.push(name);console.log('PASS '+name);}catch(e){report.failures.push({name,message:e.message});await page.screenshot({path:resolve(output,'failure.png'),fullPage:true});throw e;}};
try {
 await page.addInitScript(()=>localStorage.setItem('v2board-admin-v1:'+location.host+':test-admin','Bearer fixture-admin'));
 await check('node-rate-is-centered-and-string-group-ids-show-names',async()=>{
  await go('/server/manage');const row=page.locator('tr[data-row-key="v2node:41"]');
  await row.getByRole('link',{name:'权限组十三',exact:true}).waitFor();
  const alignment=await row.locator('.node-rate').evaluate(el=>{
   const box=el.getBoundingClientRect(),cell=el.closest('td').getBoundingClientRect(),range=document.createRange();range.selectNodeContents(el);const text=range.getBoundingClientRect();
   return {text:Math.abs(text.x+text.width/2-box.x-box.width/2),cell:Math.abs(box.x+box.width/2-cell.x-cell.width/2),align:getComputedStyle(el).textAlign};
  });assert.equal(alignment.align,'center');assert.ok(alignment.text<1,JSON.stringify(alignment));assert.ok(alignment.cell<1,JSON.stringify(alignment));
  await row.getByText('操作',{exact:true}).click();await page.locator('.ant-dropdown:visible').getByText('编辑',{exact:true}).click();
  const editor=page.locator('.ant-drawer-open').filter({has:page.locator('.node-editor')});await editor.waitFor();
  assert.ok((await selected(editor,'group_id')).join().includes('权限组十三'));
  assert.ok((await selected(editor,'route_id')).join().includes('路由七'));
  assert.ok((await selected(editor,'parent_id')).join().includes('父节点八'));
  const save=page.waitForRequest(r=>r.url().endsWith('/server/manage/batch'));
  await editor.getByRole('button',{name:'保存',exact:true}).click();const payload=(await save).postDataJSON();
  assert.deepEqual(payload.changes.group_id,[13]);assert.deepEqual(payload.changes.route_id,[7]);assert.equal(payload.changes.parent_id,8);await editor.waitFor({state:'hidden'});
 });
 await check('string-reference-options-match-numeric-plan-group-values',async()=>{
  fixture.store.groups[0].id='13';await go('/plan');
  await page.locator('tr[data-row-key="16"] .row-menu-link').click();await page.locator('.ant-dropdown:visible').getByText('编辑',{exact:true}).click();
  const editor=page.locator('.ant-drawer-open').filter({has:page.locator('.plan-editor')});await editor.waitFor();
  assert.ok((await selected(editor,'group_id')).join().includes('权限组十三'));
  const save=page.waitForRequest(r=>r.url().endsWith('/plan/save'));await editor.getByRole('button',{name:'保存',exact:true}).click();
  assert.equal((await save).postDataJSON().group_id,13);await editor.waitFor({state:'hidden'});
 });
 await check('user-plan-selector-preserves-the-legacy-plan-without-reselection',async()=>{
  await go('/user');await page.locator('tr[data-row-key="101"] .row-menu-link').click();await page.locator('.ant-dropdown:visible').getByText('编辑',{exact:true}).click();
  const editor=page.locator('.ant-drawer-open').filter({has:page.locator('.user-editor')});await editor.waitFor();
  assert.ok((await selected(editor,'plan_id')).join().includes('关联套餐'));
  const save=page.waitForRequest(r=>r.url().endsWith('/user/update'));await editor.getByRole('button',{name:'保存',exact:true}).click();
  assert.equal((await save).postDataJSON().plan_id,16);await editor.waitFor({state:'hidden'});
 });
 await check('order-detail-resolves-a-string-plan-reference',async()=>{
  await go('/order');await page.locator('tr[data-row-key="301"] a').first().click();
  const detail=page.locator('.order-detail-modal');await detail.waitFor();await detail.getByRole('link',{name:'关联套餐',exact:true}).waitFor();
  await detail.locator('.ant-modal-close').click();
 });
 await check('public-and-trial-plan-settings-display-and-save-reference-names',async()=>{
  const headers={Authorization:'Bearer fixture-admin'};
  await context.request.post(fixture.origin+'/api/v1/test-admin/config/save',{headers,data:{try_out_plan_id:'16',public_plan_enabled:1,public_plan_ids:['16']}});
  await go('/config/system');assert.ok((await selected(page,'try_out_plan_id')).join().includes('关联套餐'));
  await page.getByRole('tab',{name:'订阅',exact:true}).click();await page.locator('#public_plan_ids').waitFor();
  assert.ok((await selected(page,'public_plan_ids')).join().includes('关联套餐'));
  await field(page,'public_plan_ids').locator('.ant-select-selector').click();const list=await page.locator('#public_plan_ids').getAttribute('aria-controls');
  const save=page.waitForResponse(r=>r.url().endsWith('/config/save')&&Array.isArray(r.request().postDataJSON().public_plan_ids));
  await page.locator('.ant-select-dropdown').filter({has:page.locator(`[id="${list}"]`)}).locator('.ant-select-item-option').filter({has:page.getByText('备用套餐',{exact:true})}).click();
  const response=await save;assert.ok(response.ok());assert.deepEqual(response.request().postDataJSON().public_plan_ids,[16,17]);
 });
 assert.deepEqual(report.failures,[]);
} finally {await writeFile(resolve(output,'report.json'),JSON.stringify(report,null,2));await browser.close();await fixture.close();}
