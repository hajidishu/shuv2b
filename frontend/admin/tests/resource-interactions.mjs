import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { mkdir,writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { startFixture } from './fixture.mjs';

const fixture=await startFixture();
fixture.store.plans.push(...Array.from({length:25},(_,i)=>({id:i+1,name:`套餐-${i+1}`,show:1,renew:1,transfer_enable:100,month_price:1000,group_id:1})));
fixture.store.coupon.push({id:201,name:'旧优惠券',code:'LEGACY',type:2,value:25,limit_plan_ids:['16','17'],limit_period:[],limit_use:10,started_at:1700000000,ended_at:1800000000});
fixture.store.payment.push(...[101,102,103].map(id=>({id,name:`支付-${id}`,payment:'EPay',enable:1,notify_url:'https://panel.example.test/notify'})));
fixture.store.users.push({id:101,email:'selected@example.test',is_admin:0,is_staff:0,banned:0,balance:0,commission_balance:0,u:0,d:0,transfer_enable:0});
fixture.store.nodes.push(...Array.from({length:120},(_,i)=>({id:i+1,type:'v2node',protocol:'vless',name:`节点-${i+1}`,group_id:[],host:'node.example.test',port:'443',server_port:443,rate:1,show:1,tls:0,network:'tcp'})));
const browser=await chromium.launch({executablePath:process.env.ADMIN_BROWSER_PATH??'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
const context=await browser.newContext({locale:'zh-CN',viewport:{width:1440,height:1100}}), page=await context.newPage();
const output=resolve(import.meta.dirname,'../test-results/resource-interactions');await mkdir(output,{recursive:true});
const report={passed:[],failures:[]};
page.on('pageerror',e=>report.failures.push({name:'runtime',message:e.message}));
const go=async path=>{await page.goto(fixture.origin+'/#'+path);await page.reload({waitUntil:'networkidle'});};
const check=async(name,fn)=>{try{await fn();report.passed.push(name);console.log('PASS '+name);}catch(e){report.failures.push({name,message:e.message});await page.screenshot({path:resolve(output,'failure.png'),fullPage:true});throw e;}};
try {
 await page.addInitScript(()=>localStorage.setItem('v2board-admin-v1:'+location.host+':test-admin','Bearer fixture-admin'));
 await check('selected-user-actions-only-exist-in-the-toolbar-menu',async()=>{
  await go('/user');await page.locator('tr[data-row-key="101"] .ant-checkbox-input').check();
  const selected=page.locator('.selection-toolbar');assert.ok((await selected.textContent()).includes('已选 1 个用户'));
  assert.deepEqual(await selected.locator('button').allTextContents(),['取消选择']);
  await page.locator('.table-toolbar').getByRole('button',{name:'操作',exact:true}).hover();
  const menu=page.locator('.ant-dropdown:visible');await menu.getByText('发送邮件',{exact:true}).waitFor();
  assert.deepEqual(await menu.locator('.ant-dropdown-menu-item').allTextContents(),['导出CSV','发送邮件','批量封禁','批量删除']);
  await page.mouse.move(750,200);await menu.waitFor({state:'hidden'});await selected.getByRole('button',{name:'取消选择'}).click();await selected.waitFor({state:'hidden'});
 });
 await check('legacy-string-plan-ids-show-labels-and-save-without-reselection',async()=>{
  await go('/coupon');await page.locator('tr[data-row-key="201"]').getByText('编辑',{exact:true}).click();
  const modal=page.getByRole('dialog',{name:'编辑优惠券'});await modal.waitFor();
  const field=modal.locator('.ant-form-item').filter({has:page.getByText('限定套餐',{exact:true})});
  assert.deepEqual(await field.locator('.ant-select-selection-item-content').allTextContents(),['套餐-16','套餐-17']);
  const request=page.waitForRequest(r=>r.url().endsWith('/coupon/generate'));
  await modal.getByRole('button',{name:'保存',exact:true}).click();assert.deepEqual((await request).postDataJSON().limit_plan_ids,[16,17]);await modal.waitFor({state:'hidden'});
 });
 await check('plan-drag-sort-is-explicit-cancelable-and-preserves-hidden-pages',async()=>{
  await go('/plan');const table=page.locator('.plans-table');await table.locator('tr[data-row-key="1"]').waitFor();
  assert.equal(await table.locator('[draggable=true]').count(),0);
  await page.getByRole('button',{name:'编辑排序',exact:true}).click();
  await table.getByRole('button',{name:'拖动排序 套餐-1',exact:true}).dragTo(table.locator('tr[data-row-key="3"]'));
  assert.equal(fixture.calls.filter(c=>c.path==='/plan/sort').length,0);
  await page.getByRole('button',{name:'取消排序',exact:true}).click();
  assert.equal(await table.locator('tbody tr.ant-table-row').first().getAttribute('data-row-key'),'1');
  await page.getByRole('button',{name:'编辑排序',exact:true}).click();
  await table.getByRole('button',{name:'拖动排序 套餐-1',exact:true}).dragTo(table.locator('tr[data-row-key="3"]'));
  const saved=page.waitForRequest(r=>r.url().endsWith('/plan/sort'));
  await page.getByRole('button',{name:'保存排序',exact:true}).click();
  const ids=(await saved).postDataJSON().plan_ids;assert.equal(ids.length,25);assert.deepEqual(ids.slice(0,4),[2,3,1,4]);assert.equal(new Set(ids).size,25);
  await page.getByRole('button',{name:'编辑排序',exact:true}).waitFor();await page.reload({waitUntil:'networkidle'});
  assert.equal(await table.locator('tbody tr.ant-table-row').first().getAttribute('data-row-key'),'2');
 });
 await check('payment-drag-sort-preserves-draft-after-a-failed-save-and-can-retry',async()=>{
  await go('/config/payment');const table=page.locator('.payments-table');await table.locator('tr[data-row-key="101"]').waitFor();
  assert.equal(await table.locator('[draggable=true]').count(),0);await page.getByRole('button',{name:'编辑排序',exact:true}).click();
  await table.getByRole('button',{name:'拖动排序 支付-101',exact:true}).dragTo(table.locator('tr[data-row-key="103"]'));
  const pattern='**/payment/sort';await page.route(pattern,r=>r.fulfill({status:503,contentType:'application/json',body:JSON.stringify({message:'synthetic sort failure'})}));
  await page.getByRole('button',{name:'保存排序',exact:true}).click();await page.getByText('synthetic sort failure',{exact:true}).waitFor();
  assert.equal(await table.locator('tbody tr.ant-table-row').first().getAttribute('data-row-key'),'102');
  await page.unroute(pattern);const saved=page.waitForResponse(r=>r.url().endsWith('/payment/sort'));
  await page.getByRole('button',{name:'保存排序',exact:true}).click();const response=await saved;assert.ok(response.ok(),await response.text());assert.deepEqual(response.request().postDataJSON().ids,[102,103,101]);
  await page.getByRole('button',{name:'编辑排序',exact:true}).waitFor();await page.reload({waitUntil:'networkidle'});
  assert.equal(await table.locator('tbody tr.ant-table-row').first().getAttribute('data-row-key'),'102');
 });
 await check('nodes-default-to-one-hundred-rows-per-page',async()=>{
  await go('/server/manage');await page.waitForFunction(()=>document.querySelectorAll('.nodes-table tbody tr.ant-table-row').length===100);
  assert.ok((await page.locator('.nodes-table .ant-pagination-options').textContent()).includes('100'));
 });
 assert.deepEqual(report.failures,[]);
} finally {await writeFile(resolve(output,'report.json'),JSON.stringify(report,null,2));await browser.close();await fixture.close();}
