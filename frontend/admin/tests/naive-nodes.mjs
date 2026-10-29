import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { startFixture } from './fixture.mjs';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
const fixture = await startFixture();
fixture.store.groups.push({ id: 1, name: 'Naive fixture group' });
const browser = await chromium.launch({ executablePath: process.env.ADMIN_BROWSER_PATH ?? 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
try {
 const page=await browser.newPage({viewport:{width:1440,height:1000}});
 await page.route('**/*',route=>new URL(route.request().url()).origin===fixture.origin?route.continue():route.abort());
 await page.goto(fixture.origin+'/#/login');
 await page.getByLabel('邮箱',{exact:true}).fill('admin@example.test');
 await page.getByLabel('密码',{exact:true}).fill('fixture');
 await page.getByRole('button',{name:'登录',exact:true}).click();await page.waitForURL('**#/dashboard');
 const choose=async(id,text)=>{await page.locator('.ant-select-selector').filter({has:page.locator(id)}).click();await page.locator('.ant-select-dropdown:visible').getByText(text,{exact:true}).click();await page.locator('.ant-drawer:visible').first().locator('.ant-drawer-title').click();};
 for(const type of ['naive','v2node']){
  console.log('Checking '+type+' editor');
  await page.goto(fixture.origin+'/#/server/manage',{waitUntil:'networkidle'});
  await page.getByLabel('添加节点',{exact:true}).click();
  await page.locator('.ant-dropdown:visible').getByText(type==='naive'?'Naive':'V2node',{exact:true}).click();
  await page.locator('.ant-drawer:visible #name').fill('Naive-'+type);
  await choose('#group_id','Naive fixture group');
  await page.locator('#host').fill('naive.example.test');
  await page.locator('#port').fill('443');await page.locator('#server_port').fill('8443');
  if(type==='v2node')await choose('#protocol','Naive');
  await choose('#network',type==='v2node'?'TCP + UDP':'UDP（QUIC）');
  await choose('#congestion_control','reno');
  assert.equal(await page.getByRole('button',{name:'编辑协议配置',exact:true}).count(),0);
  assert.equal(await page.locator('#padding_scheme, #flow, #cipher, #zero_rtt_handshake').count(),0);
  await page.getByRole('button',{name:'编辑安全性配置',exact:true}).click();
  assert.equal(await page.getByText('允许不安全',{exact:true}).count(),0);
  await page.getByLabel('安全性配置 JSON',{exact:true}).fill(JSON.stringify({server_name:'naive.example.test',cert_mode:'http'}));
  await page.locator('.ant-drawer:visible').last().getByRole('button',{name:'完成',exact:true}).click();
  const pending=page.waitForResponse(r=>r.url().endsWith('/server/'+type+'/save')&&r.request().method()==='POST',{timeout:10000}).catch(()=>null);
  await page.getByRole('button',{name:'保存',exact:true}).click();
  await page.waitForTimeout(300);
  const errors=await page.locator('.ant-form-item-explain-error, .ant-alert-message').allTextContents();
  if(errors.length){await mkdir(resolve(import.meta.dirname,'../test-results'),{recursive:true});await page.screenshot({path:resolve(import.meta.dirname,'../test-results/naive-validation.png')});throw new Error('Editor validation: '+JSON.stringify(errors));}
  const response=await pending;assert(response,'No save response; requests: '+fixture.calls.map(c=>c.path).slice(-8).join(','));assert(response.ok());const data=response.request().postDataJSON();
  assert.equal(data.network,type==='v2node'?'':'udp');assert.equal(data.tls,1);assert.equal(data.congestion_control,'reno');
  assert.equal(data.tls_settings.server_name,'naive.example.test');
  if(type==='v2node')assert.equal(data.protocol,'naive');
  assert(!Object.hasOwn(data,'padding_scheme'));assert(!Object.hasOwn(data,'network_settings'));
  await page.locator('.node-editor').waitFor({state:'hidden'});
 }
 assert.equal(fixture.store.nodes.filter(n=>n.type==='naive'||n.protocol==='naive').length,2);
 console.log('PASS Independent Naive and V2node Naive use native transport, TLS-only settings and clean save payloads');
} finally { await browser.close();await fixture.close(); }
