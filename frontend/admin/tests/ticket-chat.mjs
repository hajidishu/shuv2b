import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { mkdir, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { startFixture } from './fixture.mjs';

const fixture = await startFixture();
const now = Math.floor(Date.now()/1000);
fixture.store.users.push({id:101,email:'ticket-owner@example.test',password:'fixture-password-hash',balance:5000,commission_balance:0,
    u:100,d:200,transfer_enable:100*1024**3,expired_at:null,plan_id:1,group_id:1,group_mode:0,banned:0,is_admin:0,is_staff:0});
fixture.store.plans.push({id:1,name:'工单套餐',group_id:1});
fixture.store.groups.push({id:1,name:'工单权限组'});
fixture.store.ticket.push({id:501,user_id:101,subject:'连接咨询',level:1,status:0,reply_status:0,created_at:now-100,updated_at:now-10,
    message:Array.from({length:25},(_,i)=>({id:i+1,created_at:now-100+i,is_me:i%2===1,message:i===24?'<img src=x onerror="window.ticketInjected=true">':`历史消息 ${i+1}`}))});
const traffic = Array.from({length:12},(_,i)=>({id:i+1,record_at:now-i*86400,u:1024**3,d:2*1024**3,server_rate:1}));
const calls = [];
let failReply = false;
let browser;
const output = resolve(import.meta.dirname,'../test-results/ticket-chat');
await mkdir(output,{recursive:true});
const report = {passed:[],failures:[]};

async function context(options={}) {
    const ctx = await browser.newContext({locale:'zh-CN',viewport:{width:1440,height:1000},...options});
    await ctx.addInitScript(origin=>{
        if(location.origin===origin)localStorage.setItem('v2board-admin-v1:'+location.host+':test-admin','Bearer fixture-admin');
    },fixture.origin);
    ctx.on('page',page=>page.on('pageerror',error=>report.failures.push({name:'runtime',message:error.message})));
    await ctx.route('**/*',async route=>{
        const request=route.request(),url=new URL(request.url());
        if(url.origin!==fixture.origin)return route.abort();
        const path=url.pathname.replace('/api/v1/test-admin','');
        if(!path.startsWith('/ticket/')&&path!=='/stat/getStatUser')return route.continue();
        const data=request.postDataJSON()??{};
        calls.push({path,method:request.method(),query:Object.fromEntries(url.searchParams),data});
        const row=fixture.store.ticket.find(r=>r.id===Number(data.id??url.searchParams.get('id')));
        const respond=(data,status=200)=>route.fulfill({status,contentType:'application/json',body:JSON.stringify(data)});
        if(path==='/stat/getStatUser') {
            const page=Number(url.searchParams.get('current')??1),size=Number(url.searchParams.get('pageSize')??10);
            return respond({data:traffic.slice((page-1)*size,page*size),total:traffic.length});
        }
        if(path==='/ticket/fetch')return respond({data:url.searchParams.has('id')?row:fixture.store.ticket.filter(r=>r.status===Number(url.searchParams.get('status')??0)),total:1});
        if(path==='/ticket/close') {row.status=1;return respond({data:true});}
        if(path==='/ticket/reply') {
            if(failReply) {failReply=false;return respond({message:'synthetic reply failure'},503);}
            if(row.status)return respond({message:'工单已关闭'},409);
            row.message.push({id:row.message.length+1,created_at:now,is_me:true,message:data.message});row.reply_status=1;
            return respond({data:true});
        }
        return route.continue();
    });
    return ctx;
}
async function check(name,fn) {
    try {await fn();report.passed.push(name);console.log('PASS '+name);}
    catch(error) {report.failures.push({name,message:error.message});throw error;}
}
let ctx, page, popup;
try {
    browser = await chromium.launch({executablePath:process.env.ADMIN_BROWSER_PATH??'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
    ctx=await context();page=await ctx.newPage();
    await page.goto(fixture.origin+'/#/ticket',{waitUntil:'networkidle'});
    await check('desktop-ticket-opens-an-independent-800x600-chat-window',async()=>{
        [popup]=await Promise.all([page.waitForEvent('popup'),page.locator('tr[data-row-key="501"]').getByText('查看',{exact:true}).click()]);
        await popup.waitForLoadState('networkidle');await popup.locator('.ticket-chat-bubble').last().waitFor();
        assert.equal(new URL(page.url()).hash,'#/ticket');
        assert.equal(new URL(popup.url()).hash,'#/ticket/501');
        assert.equal(await popup.locator('.admin-sidebar,.admin-header').count(),0);
        const dimensions=await popup.evaluate(()=>({width:innerWidth,height:innerHeight,overflow:document.documentElement.scrollWidth>innerWidth}));
        assert.ok(dimensions.width>=600&&dimensions.width<=820,JSON.stringify(dimensions));
        assert.ok(dimensions.height>=400&&dimensions.height<=650,JSON.stringify(dimensions));
        assert.equal(dimensions.overflow,false);
        assert.equal(await popup.locator('.ticket-chat-messages img').count(),0);
        assert.equal(await popup.evaluate(()=>!!window.ticketInjected),false);
        const user=await popup.getByRole('button',{name:'用户管理',exact:true}).boundingBox();
        const stats=await popup.getByRole('button',{name:'TA的流量记录',exact:true}).boundingBox();
        assert.ok(user.x<stats.x&&Math.abs(user.y-stats.y)<2);
        await popup.getByRole('textbox',{name:'工单回复'}).fill('您好，请问有什么可以帮您？');
        const send=popup.getByRole('button',{name:'发送',exact:true});
        await popup.waitForFunction(()=>{
            const button=document.querySelector('.ticket-chat-compose > button');
            return !button.disabled&&getComputedStyle(button).backgroundColor==='rgb(255, 255, 255)';
        });
        assert.equal(await popup.locator('.ticket-chat-subject .ant-tag-blue').count(),0);
        await send.hover();
        await popup.waitForFunction(()=>getComputedStyle(document.querySelector('.ticket-chat-compose > button')).color==='rgb(52, 58, 64)');
        await popup.mouse.move(300,250);
        await popup.screenshot({path:resolve(output,'chat-desktop.png')});
        await popup.getByRole('textbox',{name:'工单回复'}).fill('');
    });
    await check('user-management-opens-the-existing-editor-without-leaving-chat',async()=>{
        await popup.getByRole('button',{name:'用户管理',exact:true}).click();
        const editor=popup.locator('.user-editor');await editor.getByRole('textbox',{name:'邮箱',exact:true}).waitFor();
        assert.equal(await editor.getByRole('textbox',{name:'邮箱',exact:true}).inputValue(),'ticket-owner@example.test');
        assert.equal(await editor.locator('input[type=password]').inputValue(),'');
        await editor.getByRole('spinbutton',{name:'余额',exact:true}).fill('75.50');
        const saved=popup.waitForRequest(r=>new URL(r.url()).pathname.endsWith('/user/update'));
        await editor.getByRole('button',{name:'保存',exact:true}).click();
        const payload=(await saved).postDataJSON();assert.equal(payload.id,101);assert.equal(payload.balance,7550);assert.ok(!('password'in payload));
        await editor.waitFor({state:'hidden'});assert.equal(new URL(popup.url()).hash,'#/ticket/501');
    });
    await check('traffic-button-shows-user-records-and-paginates',async()=>{
        await popup.getByRole('button',{name:'TA的流量记录',exact:true}).click();
        const modal=popup.getByRole('dialog',{name:'流量记录'});await modal.locator('tbody tr.ant-table-row').last().waitFor();
        assert.equal(await modal.locator('tbody tr.ant-table-row').count(),10);
        assert.ok((await modal.innerText()).includes('1.00 GB'));
        await modal.locator('.ant-pagination-item-2').click();
        await popup.waitForFunction(()=>document.querySelectorAll('.ticket-traffic-modal tbody tr.ant-table-row').length===2);
        assert.ok(calls.some(c=>c.path==='/stat/getStatUser'&&c.query.user_id==='101'&&c.query.current==='2'));
        await popup.screenshot({path:resolve(output,'chat-traffic.png')});
        await modal.locator('.ant-modal-close').click();
    });
    await check('polling-updates-the-thread-without-clearing-drafts-or-resetting-reading-position',async()=>{
        await popup.bringToFront();const input=popup.getByRole('textbox',{name:'工单回复'});await input.fill('尚未发送的草稿');
        const thread=popup.getByRole('log',{name:'工单消息'});
        await thread.evaluate(el=>{el.scrollTop=0;el.dispatchEvent(new Event('scroll',{bubbles:true}));});
        fixture.store.ticket[0].message.push({id:26,created_at:now,is_me:false,message:'用户的新消息'});
        await popup.getByText('用户的新消息',{exact:true}).waitFor({timeout:8000});
        assert.equal(await input.inputValue(),'尚未发送的草稿');
        assert.ok(await thread.evaluate(el=>el.scrollTop<50));
    });
    await check('enter-sends-shift-enter-and-ime-do-not-and-failed-replies-keep-the-draft',async()=>{
        const input=popup.getByRole('textbox',{name:'工单回复'});await input.fill('管理员回复');
        let count=calls.filter(c=>c.path==='/ticket/reply').length;
        await input.dispatchEvent('keydown',{key:'Enter',isComposing:true});
        await input.press('Shift+Enter');assert.equal(calls.filter(c=>c.path==='/ticket/reply').length,count);
        assert.ok((await input.inputValue()).includes('\n'));
        await input.fill('管理员回复');
        const sent=popup.waitForRequest(r=>new URL(r.url()).pathname.endsWith('/ticket/reply'));
        await input.press('Enter');assert.deepEqual((await sent).postDataJSON(),{id:501,message:'管理员回复'});
        await popup.getByRole('log',{name:'工单消息'}).getByText('管理员回复',{exact:true}).waitFor();assert.equal(await input.inputValue(),'');
        failReply=true;await input.fill('保留失败草稿');await popup.getByRole('button',{name:'发送',exact:true}).click();
        await popup.getByText('synthetic reply failure',{exact:true}).waitFor();assert.equal(await input.inputValue(),'保留失败草稿');
        await popup.getByRole('button',{name:'发送',exact:true}).click();await popup.getByRole('log',{name:'工单消息'}).getByText('保留失败草稿',{exact:true}).waitFor();
        assert.equal(fixture.store.ticket[0].message.filter(m=>m.message==='保留失败草稿').length,1);
    });
    await check('closing-a-ticket-preserves-history-and-disables-replies',async()=>{
        await popup.getByRole('button',{name:'关闭工单',exact:true}).click();
        const confirm=popup.getByRole('dialog');await confirm.getByRole('button',{name:/确\s*定/}).click();
        await popup.getByText('已关闭',{exact:true}).waitFor();
        assert.ok(await popup.getByRole('textbox',{name:'工单回复'}).isDisabled());
        assert.ok(await popup.getByRole('button',{name:'发送',exact:true}).isDisabled());
        assert.ok(await popup.getByRole('button',{name:'关闭工单',exact:true}).isDisabled());
        await popup.getByText('保留失败草稿',{exact:true}).waitFor();
        await popup.close();
    });
    await check('blocked-popup-falls-back-to-the-chat-route',async()=>{
        fixture.store.ticket[0].status=0;
        await page.goto(fixture.origin+'/#/ticket',{waitUntil:'networkidle'});
        await page.evaluate(()=>{window.open=()=>null;});
        await page.locator('tr[data-row-key="501"]').getByText('查看',{exact:true}).click();
        await page.getByRole('main',{name:'工单对话'}).waitFor();
        assert.equal(new URL(page.url()).hash,'#/ticket/501');
        assert.equal(await page.locator('.admin-sidebar').count(),0);
        await page.getByRole('link',{name:'返回工单列表',exact:true}).click();
        await page.locator('.admin-sidebar').waitFor();
    });
    await check('mobile-ticket-uses-current-page-and-keeps-controls-visible',async()=>{
        const mobile=await context({viewport:{width:390,height:844},isMobile:true,hasTouch:true,userAgent:'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148'});
        const screen=await mobile.newPage();await screen.goto(fixture.origin+'/#/ticket',{waitUntil:'networkidle'});
        await screen.getByRole('link',{name:'连接咨询',exact:true}).click();await screen.getByRole('main',{name:'工单对话'}).waitFor();
        assert.equal(mobile.pages().length,1);await screen.getByRole('textbox',{name:'工单回复'}).waitFor();
        const bounds=await screen.getByRole('button',{name:'TA的流量记录',exact:true}).boundingBox();assert.ok(bounds.x+bounds.width<=390);
        assert.ok(await screen.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
        await screen.screenshot({path:resolve(output,'chat-mobile.png')});await mobile.close();
    });
    await check('ipad-desktop-user-agent-keeps-the-ticket-in-the-current-page',async()=>{
        const tablet=await context({viewport:{width:1024,height:768},hasTouch:true,
            userAgent:'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15'});
        await tablet.addInitScript(()=>Object.defineProperty(navigator,'maxTouchPoints',{get:()=>5}));
        const screen=await tablet.newPage();await screen.goto(fixture.origin+'/#/ticket',{waitUntil:'networkidle'});
        assert.ok(await screen.evaluate(()=>navigator.maxTouchPoints>1));
        await screen.getByRole('link',{name:'连接咨询',exact:true}).click();await screen.getByRole('main',{name:'工单对话'}).waitFor();
        assert.equal(tablet.pages().length,1);assert.equal(new URL(screen.url()).hash,'#/ticket/501');
        await tablet.close();
    });
    assert.deepEqual(report.failures,[]);
} catch(error) {
    if(popup&&!popup.isClosed())await popup.screenshot({path:resolve(output,'failure.png')});
    throw error;
} finally {
    try { await writeFile(resolve(output,'report.json'),JSON.stringify(report,null,2)); }
    finally {
        try { if(browser)await browser.close(); }
        finally { await fixture.close(); }
    }
}
