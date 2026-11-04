import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { startFixture } from './fixture.mjs';
import { mkdir,writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';

if(process.env.ADMIN_LIVE_URL)throw new Error('telegram-panel.mjs is fixture-only');
const fixture=await startFixture(),output=resolve(import.meta.dirname,'../test-results/telegram-panel');await mkdir(output,{recursive:true});
const browser=await chromium.launch({executablePath:process.env.ADMIN_BROWSER_PATH??'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
const page=await browser.newPage({viewport:{width:1440,height:1100},locale:'zh-CN'}),errors=[];
page.on('pageerror',error=>errors.push(error.message));
const changes=[];
try{
 await page.addInitScript(()=>localStorage.setItem('v2board-admin-v1:'+location.host+':test-admin','Bearer fixture-admin'));
 await page.goto(fixture.origin+'/#/config/system',{waitUntil:'networkidle'});await page.getByRole('tab',{name:'Telegram',exact:true}).click();
 const fields=['telegram_frontend_url','telegram_remind_enable','telegram_remind_expire_days','telegram_remind_traffic_percent','telegram_discuss_id','telegram_channel_id','telegram_group_prune_enable'];
 for(const id of fields)await page.locator('#'+id).waitFor();
 const switchFor=id=>page.locator('#'+id);
 assert.equal(await switchFor('telegram_remind_enable').getAttribute('aria-checked'),'false');
 assert.equal(await switchFor('telegram_group_prune_enable').getAttribute('aria-checked'),'false');
 for(const [key,value] of [['telegram_frontend_url','https://front.example.test'],['telegram_remind_expire_days','5'],['telegram_remind_traffic_percent','15'],['telegram_discuss_id','-100123,-100456'],['telegram_channel_id','-100789']]){
  const saved=page.waitForResponse(r=>r.url().endsWith('/config/save')&&Object.hasOwn(r.request().postDataJSON(),key));
  await page.locator('#'+key).fill(value);assert.ok((await saved).ok());changes.push(key);
 }
 for(const key of ['telegram_remind_enable','telegram_group_prune_enable']){
  const saved=page.waitForResponse(r=>r.url().endsWith('/config/save')&&r.request().postDataJSON()[key]===1);
  await switchFor(key).click();assert.ok((await saved).ok());changes.push(key);
 }
 await page.getByText('已保存',{exact:true}).waitFor();await page.reload({waitUntil:'networkidle'});await page.getByRole('tab',{name:'Telegram',exact:true}).click();
 assert.equal(await page.locator('#telegram_frontend_url').inputValue(),'https://front.example.test');
 assert.equal(await page.locator('#telegram_remind_expire_days').inputValue(),'5');assert.equal(await page.locator('#telegram_remind_traffic_percent').inputValue(),'15');
 assert.equal(await page.locator('#telegram_discuss_id').inputValue(),'-100123,-100456');assert.equal(await switchFor('telegram_remind_enable').getAttribute('aria-checked'),'true');
 const ids=await page.locator('.ant-form-item').evaluateAll(items=>items.map(item=>item.querySelector('input,button[role=switch]')?.id).filter(Boolean));
 assert.ok(ids.indexOf('telegram_bot_admin_id')>ids.indexOf('telegram_bot_token')&&ids.indexOf('telegram_bot_admin_id')<ids.indexOf('telegram_webhook_domain'));
 assert.deepEqual(errors,[]);console.log('PASS Telegram user entry, notification thresholds, managed groups, default-off automation and settings persistence');
 await page.screenshot({path:resolve(output,'settings.png'),fullPage:true});
}finally{await writeFile(resolve(output,'report.json'),JSON.stringify({changes,errors},null,2));await browser.close();await fixture.close();}
