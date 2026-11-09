import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { mkdir,writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { startFixture } from './fixture.mjs';

if(process.env.ADMIN_LIVE_URL)throw new Error('settings-switch-layout.mjs is fixture-only');
const fixture=await startFixture();
const browser=await chromium.launch({executablePath:process.env.ADMIN_BROWSER_PATH??'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
const page=await browser.newPage({locale:'zh-CN'});
const output=resolve(import.meta.dirname,'../test-results/settings-switch-layout');await mkdir(output,{recursive:true});
const report={passed:[],failures:[],metrics:[]};
page.on('pageerror',e=>report.failures.push({name:'runtime',message:e.message}));
try {
 await page.addInitScript(()=>localStorage.setItem('v2board-admin-v1:'+location.host+':test-admin','Bearer fixture-admin'));
 for(const width of [2560,1440,390]) {
  await page.setViewportSize({width,height:1100});
  await page.goto(fixture.origin+'/#/config/system');await page.reload({waitUntil:'networkidle'});
  const tabs=await page.getByRole('tab').allTextContents();let count=0;
  for(const tab of tabs) {
   await page.getByRole('tab',{name:tab,exact:true}).click();
   await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
   const metrics=await page.locator('.settings-switch-field:visible').evaluateAll(fields=>fields.map(field=>{
    const label=field.querySelector('.settings-label').getBoundingClientRect(),button=field.querySelector('.ant-switch').getBoundingClientRect(),control=field.querySelector('.ant-form-item-control').getBoundingClientRect(),card=field.closest('.ant-card').getBoundingClientRect();
    return {label:field.querySelector('.settings-label strong').textContent,gap:button.left-label.right,center:Math.abs(button.top+button.height/2-label.top-label.height/2),controlDelta:Math.abs(button.left-control.left),inside:button.left>=card.left&&button.right<=Math.min(card.right,innerWidth)+1};
   }));
   for(const metric of metrics)assert.ok(metric.gap>=8&&metric.center<2&&metric.controlDelta<1&&metric.inside,JSON.stringify({width,tab,...metric}));
   count+=metrics.length;report.metrics.push({width,tab,metrics});
  }
  assert.ok(count>=10,'The matrix must check multiple settings groups');report.passed.push(`all-settings-switches-share-a-row-at-${width}`);
  // Bring the overflowed first tab back into view before preparing the screenshot.
  await page.setViewportSize({width:2560,height:1100});
  await page.getByRole('tab',{name:'站点',exact:true}).click();
  await page.setViewportSize({width,height:1100});
  await page.locator('#stop_register').scrollIntoViewIfNeeded();
  await page.screenshot({path:resolve(output,`site-${width}.png`)});
 }
 const control=page.locator('#stop_register'),next=await control.getAttribute('aria-checked')==='true'?0:1;
 const saved=page.waitForResponse(response=>response.url().endsWith('/config/save')&&Object.hasOwn(response.request().postDataJSON(),'stop_register'));
 await control.click();const response=await saved;assert.ok(response.ok());assert.equal(response.request().postDataJSON().stop_register,next);
 await page.getByText('已保存',{exact:true}).waitFor();await page.reload({waitUntil:'networkidle'});
 assert.equal(await page.locator('#stop_register').getAttribute('aria-checked'),String(!!next));
  report.passed.push('switch-remains-clickable-and-setting-persists');
  const warning='配置已保存，但 OPcache 清理受限。请完整重启 PHP/FPM 或 Webman 主进程后生效。';
  await page.route('**/api/v1/test-admin/config/save',async route=>{
   const original=await route.fetch();await route.fulfill({response:original,json:{...await original.json(),warning}});
  });
  const warned=page.waitForResponse(response=>response.url().endsWith('/config/save'));
  await page.locator('#stop_register').click();assert.ok((await warned).ok());
  await page.getByText('已保存，需重载 PHP',{exact:true}).waitFor();
  await page.getByText(warning,{exact:true}).waitFor();
  report.passed.push('restricted-opcache-save-shows-actionable-warning');
  await page.getByRole('tab',{name:'安全',exact:true}).click();
  const before=page.url(),pathSaved=page.waitForResponse(response=>response.url().endsWith('/config/save')&&Object.hasOwn(response.request().postDataJSON(),'secure_path'));
  await page.locator('#secure_path').fill('pending-reload-admin');await pathSaved;
  await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  assert.equal(page.url(),before,'A path requiring PHP reload must not navigate before it becomes active');
  await page.getByText(warning,{exact:true}).waitFor();
  report.passed.push('restricted-opcache-secure-path-change-retains-the-warning');assert.deepEqual(report.failures,[]);
 console.log(JSON.stringify({passed:report.passed,checked:report.metrics.reduce((sum,row)=>sum+row.metrics.length,0)}));
} catch(error) {
 report.failures.push({name:'settings-switch-layout',message:error.message});await page.screenshot({path:resolve(output,'failure.png'),fullPage:true});throw error;
} finally {await writeFile(resolve(output,'report.json'),JSON.stringify(report,null,2));await browser.close();await fixture.close();}
