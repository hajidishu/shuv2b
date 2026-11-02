import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { startFixture } from './fixture.mjs';

if(process.env.ADMIN_LIVE_URL) throw new Error('telegram-management.mjs is fixture-only');
const fixture=await startFixture();
fixture.store.users.push({id:101,email:'admin@example.test',is_admin:1,is_staff:0,banned:0,telegram_admin_id:'808001',balance:0,commission_balance:0,u:0,d:0,transfer_enable:0});
const browser=await chromium.launch({executablePath:process.env.ADMIN_BROWSER_PATH??'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
const page=await browser.newPage({locale:'zh-CN',viewport:{width:1440,height:1000}});
try {
 await page.addInitScript(()=>localStorage.setItem('v2board-admin-v1:'+location.host+':test-admin','Bearer fixture-admin'));
 await page.goto(fixture.origin+'/#/config/system',{waitUntil:'networkidle'});
 await page.getByRole('tab',{name:'Telegram',exact:true}).click();
 const input=page.locator('#telegram_bot_admin_id');await input.waitFor();
 assert.equal(await input.count(),1);
 const order=await page.locator('.ant-form-item').evaluateAll(items=>items.map(item=>item.querySelector('input')?.id).filter(Boolean));
 assert.ok(order.indexOf('telegram_bot_admin_id')>order.indexOf('telegram_bot_token'));
 assert.ok(order.indexOf('telegram_bot_admin_id')<order.indexOf('telegram_webhook_domain'));
 const save=page.waitForResponse(r=>r.url().endsWith('/config/save')&&r.request().postDataJSON().telegram_bot_admin_id==='3456789012345678');
 await input.fill('3456789012345678');assert.ok((await save).ok());
 await page.getByText('已保存',{exact:true}).waitFor();
 await page.reload({waitUntil:'networkidle'});await page.getByRole('tab',{name:'Telegram',exact:true}).click();
 assert.equal(await input.inputValue(),'3456789012345678');
 const invalid=page.waitForResponse(r=>r.url().endsWith('/config/save')&&r.request().postDataJSON().telegram_bot_admin_id==='808001,808002');
 await input.fill('808001,808002');assert.equal((await invalid).status(),422);
 const clear=page.waitForResponse(r=>r.url().endsWith('/config/save')&&r.request().postDataJSON().telegram_bot_admin_id===null);
 await input.fill('');assert.ok((await clear).ok());
 await page.getByText('已保存',{exact:true}).waitFor();
 await page.goto(fixture.origin+'/#/user',{waitUntil:'networkidle'});await page.reload({waitUntil:'networkidle'});
 await page.locator('tr[data-row-key="101"] .row-menu-link').click();
 await page.locator('.ant-dropdown:visible').getByText('编辑',{exact:true}).click();
 const editor=page.locator('.ant-drawer-open').filter({has:page.locator('.user-editor')});await editor.waitFor();
 assert.equal(await editor.locator('input[id*="telegram_admin"]').count(),0);
 const saved=page.waitForRequest(r=>r.url().endsWith('/user/update'));
 await editor.locator('.ant-drawer-footer .ant-btn-primary').click();
 assert.ok(!Object.hasOwn((await saved).postDataJSON(),'telegram_admin_id'));
 console.log('PASS singleton Telegram administrator setting beside bot token, save/reload/clear, multiple IDs rejected, user editor has no management input');
} finally {await browser.close();await fixture.close();}
