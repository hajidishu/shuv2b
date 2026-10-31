import assert from 'node:assert/strict';
import {chromium} from 'playwright';
import {startFixture} from './fixture.mjs';
const fixture=await startFixture();fixture.store.groups.push({id:1,name:'Mieru fixture group'});
const browser=await chromium.launch({executablePath:process.env.ADMIN_BROWSER_PATH??'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
try{
 const page=await browser.newPage({viewport:{width:1440,height:1000}});
 await page.route('**/*',route=>new URL(route.request().url()).origin===fixture.origin?route.continue():route.abort());
 await page.goto(fixture.origin+'/#/login');await page.getByLabel('邮箱',{exact:true}).fill('admin@example.test');await page.getByLabel('密码',{exact:true}).fill('fixture');await page.getByRole('button',{name:'登录',exact:true}).click();await page.waitForURL('**#/dashboard');
 const choose=async(id,text)=>{await page.locator('.ant-select-selector').filter({has:page.locator(id)}).click();await page.locator('.ant-select-dropdown:visible .ant-select-item-option-content').getByText(text,{exact:true}).click();await page.locator('.node-editor .ant-drawer-title').first().click();};
 const save=async(path)=>{const pending=page.waitForResponse(r=>r.url().endsWith(path)&&r.request().method()==='POST');await page.getByRole('button',{name:'保存',exact:true}).click();const r=await pending;assert(r.ok());await page.locator('.node-editor').waitFor({state:'hidden'});return r.request().postDataJSON();};
 const edit=async(node)=>{await page.locator(`tr[data-row-key="${node.type}:${node.id}"]`).getByText('操作',{exact:true}).click();await page.locator('.ant-dropdown:visible').getByText('编辑',{exact:true}).click();await page.locator('.node-editor #name').waitFor();};
 for(const type of ['mieru','v2node']){
  await page.goto(fixture.origin+'/#/server/manage',{waitUntil:'networkidle'});await page.getByLabel('添加节点',{exact:true}).click();await page.locator('.ant-dropdown:visible').getByText(type==='mieru'?'Mieru':'V2node',{exact:true}).click();
  await page.locator('#name').fill('Mieru-'+type);await choose('#group_id','Mieru fixture group');await page.locator('#host').fill('mieru.example.test');
  if(type==='v2node')await choose('#protocol','Mieru');
  await page.locator('#port').fill('2012-2022');await page.locator('#server_port').fill('3012-3022');await choose('#network','UDP');
  assert.equal(await page.locator('#tls,#tls_settings,#flow,#padding_scheme,#cipher,#congestion_control').count(),0);
  assert.equal(await page.locator('#node-install-command').count(),0);
  await page.getByRole('button',{name:'编辑Mieru 配置',exact:true}).click();
  const settings={mtu:1400,multiplexing:'MULTIPLEXING_HIGH',handshake_mode:'HANDSHAKE_NO_WAIT',traffic_pattern:'CAE=',server_traffic_pattern:'CCo=',user_hint_is_mandatory:1};
  await page.getByLabel('Mieru 配置 JSON',{exact:true}).fill(JSON.stringify(settings));await page.locator('.ant-drawer:visible').last().getByRole('button',{name:'完成',exact:true}).click();
  const data=await save('/server/'+type+'/save');assert.equal(data.network,'udp');assert.equal(data.server_port,'3012-3022');assert.equal(data.network_settings.multiplexing,'MULTIPLEXING_HIGH');assert.equal(data.network_settings.server_traffic_pattern,'CCo=');
  if(type==='v2node'){assert.equal(data.protocol,'mieru');assert.equal(data.tls,0);assert(!Object.hasOwn(data,'tls_settings'));}
  const node=fixture.store.nodes.find(n=>n.type===type&&n.name==='Mieru-'+type);assert(node);
  // Match the real API's integer primary port plus preserved range metadata.
  node.server_port=3012;node.network_settings.server_port_range='3012-3022';
  await page.reload({waitUntil:'networkidle'});await edit(node);assert.equal(await page.locator('#server_port').inputValue(),'3012-3022');await page.locator('#rate').fill('2');
  const update=await save('/server/manage/batch');assert.equal(update.changes.server_port,'3012-3022');assert.equal(update.changes.network_settings.server_traffic_pattern,'CCo=');
  await edit(node);await page.locator('#server_port').fill('3012');const single=await save('/server/manage/batch');assert.equal(single.changes.server_port,'3012');assert(!Object.hasOwn(single.changes.network_settings,'server_port_range'));
  if(type==='v2node'){
   await edit(node);await choose('#protocol','VMess');assert.equal(await page.getByRole('button',{name:'编辑Mieru 配置',exact:true}).count(),0);await choose('#protocol','Mieru');assert.equal(await page.locator('#tls').count(),0);
   const switched=await save('/server/manage/batch');assert.equal(switched.changes.protocol,'mieru');assert.equal(switched.changes.network,'tcp');assert(!Object.hasOwn(switched.changes.network_settings,'server_traffic_pattern'));
  }
  console.log('PASS '+type+' Mieru create/edit: native transports, no TLS, range round-trip, range removal and protocol isolation');
 }
}finally{await browser.close();await fixture.close();}
