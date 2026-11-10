import assert from 'node:assert/strict';
import {chromium} from 'playwright';
import {startFixture} from './fixture.mjs';

if(process.env.ADMIN_LIVE_URL)throw new Error('ticket-settings.mjs is fixture-only');
const fixture=await startFixture();
const browser=await chromium.launch({executablePath:process.env.ADMIN_BROWSER_PATH??'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
try{
 const page=await browser.newPage({locale:'zh-CN'}),errors=[];
 page.on('pageerror',error=>errors.push(error.message));
 await page.addInitScript(()=>localStorage.setItem('v2board-admin-v1:'+location.host+':test-admin','Bearer fixture-admin'));
 const open=async()=>{await page.goto(fixture.origin+'/#/config/system');await page.reload({waitUntil:'networkidle'});await page.getByRole('tab',{name:'工单',exact:true}).click();await page.locator('#ticket_create_limit').waitFor();};
 await open();
 assert.equal(await page.locator('#ticket_create_limit').inputValue(),'5');
 const change=async(value,expected)=>{
  const saved=page.waitForResponse(response=>response.url().endsWith('/config/save')&&Object.hasOwn(response.request().postDataJSON(),'ticket_create_limit'));
  await page.locator('#ticket_create_limit').fill(value);const response=await saved;
  assert.ok(response.ok());assert.equal(response.request().postDataJSON().ticket_create_limit,expected);
 };
 await change('2',2);await open();assert.equal(await page.locator('#ticket_create_limit').inputValue(),'2');
 await change('',null);await open();assert.equal(await page.locator('#ticket_create_limit').inputValue(),'');
 assert.equal(await page.locator('#ticket_create_limit').getAttribute('placeholder'),'留空不限制');
 assert.deepEqual(errors,[]);
 console.log('PASS ticket creation limit saves numbers and preserves blank unlimited state after reload');
}finally{await browser.close();await fixture.close();}
