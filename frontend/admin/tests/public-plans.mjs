import assert from 'node:assert/strict';
import {chromium} from 'playwright';
import {startFixture} from './fixture.mjs';
const fixture=await startFixture();
fixture.store.plans.push({id:101,name:'公开套餐 A',show:1},{id:102,name:'内部套餐 B',show:0});
fixture.store.plans.push({id:103,name:'很长的公开展示套餐名称，用于检查多选标签换行之后不会超出输入框和设置行',show:1});
const browser=await chromium.launch({executablePath:process.env.ADMIN_BROWSER_PATH ?? 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
try {
 const page=await browser.newPage();
 await page.route('**/*',route=>new URL(route.request().url()).origin===fixture.origin?route.continue():route.abort());
 await page.goto(fixture.origin+'/#/login');
 await page.getByLabel('邮箱',{exact:true}).fill('admin@example.test');
 await page.getByLabel('密码',{exact:true}).fill('fixture');
 await page.getByRole('button',{name:'登录',exact:true}).click();await page.waitForURL('**#/dashboard');
 await page.goto(fixture.origin+'/#/config/system',{waitUntil:'networkidle'});
 assert.equal(await page.locator('#public_plan_enabled').count(),0);
 await page.getByRole('tab',{name:'订阅',exact:true}).click();
 assert.equal(await page.locator('#public_plan_enabled').getAttribute('aria-checked'),'false');
 await page.locator('#public_plan_ids').click();
 await page.locator('.ant-select-dropdown:visible').getByText('公开套餐 A',{exact:true}).click();
 await page.getByText('公开展示的套餐',{exact:true}).click();
 await page.getByText('已保存',{exact:true}).waitFor();
 assert(fixture.calls.some(c=>c.path==='/config/save'&&c.data.public_plan_ids?.[0]===101));
 await page.locator('#public_plan_enabled').click();await page.getByText('已保存',{exact:true}).waitFor();
 await page.reload({waitUntil:'networkidle'});
 await page.getByRole('tab',{name:'订阅',exact:true}).click();
 assert.equal(await page.locator('#public_plan_enabled').getAttribute('aria-checked'),'true');
 assert(await page.locator('.ant-select-selection-item').filter({hasText:'公开套餐 A'}).count()>0);
 await page.locator('#public_plan_ids').click();
 await page.locator('.ant-select-dropdown:visible').getByText('内部套餐 B（未上架，不公开）',{exact:true}).waitFor();
 await page.locator('.ant-select-dropdown:visible').getByText('内部套餐 B（未上架，不公开）',{exact:true}).click();
 await page.locator('.ant-select-dropdown:visible').getByText('很长的公开展示套餐名称，用于检查多选标签换行之后不会超出输入框和设置行',{exact:true}).click();
 await page.getByText('公开展示的套餐',{exact:true}).click();
 for(const width of [1920,1024,390]){
  await page.setViewportSize({width,height:900});
  await page.locator('#public_plan_ids').scrollIntoViewIfNeeded();
  const boxes=await page.locator('#public_plan_ids').evaluate(input=>{
   const select=input.closest('.ant-select'),selector=select.querySelector('.ant-select-selector'),row=select.closest('.ant-form-item');
   const box=e=>{const r=e.getBoundingClientRect();return {left:r.left,right:r.right,top:r.top,bottom:r.bottom,height:r.height};};
   return {selector:box(selector),row:box(row),items:[...select.querySelectorAll('.ant-select-selection-item')].map(box)};
  });
  assert.equal(boxes.items.length,3);
  for(const tag of boxes.items){assert(tag.bottom<=boxes.selector.bottom+1);assert(tag.right<=boxes.selector.right+1);assert(tag.left>=boxes.selector.left-1);}
  assert(boxes.selector.bottom<=boxes.row.bottom+1);
 }
 console.log('PASS Public plans are under Subscribe; persisted numeric selection, hidden annotations and wrapped tags fit at desktop, tablet and mobile widths');
}finally{await browser.close();await fixture.close();}
