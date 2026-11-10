import assert from 'node:assert/strict';
import {chromium} from 'playwright';
import {startFixture} from './fixture.mjs';

if (process.env.ADMIN_LIVE_URL) throw new Error('user-registration-ip.mjs is fixture-only');
const fixture = await startFixture();
fixture.store.users = [
    {id: 901, email: 'registered@example.test', register_ip: '2001:db8::5', balance: 0, commission_balance: 0},
    {id: 902, email: 'legacy@example.test', register_ip: null, balance: 0, commission_balance: 0},
];
const browser = await chromium.launch({executablePath: process.env.ADMIN_BROWSER_PATH ?? 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'});
try {
    const context = await browser.newContext({locale: 'zh-CN', permissions: ['clipboard-read', 'clipboard-write']});
    const page = await context.newPage(), errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.addInitScript(() => localStorage.setItem('v2board-admin-v1:' + location.host + ':test-admin', 'Bearer fixture-admin'));
    await page.goto(fixture.origin + '/#/user', {waitUntil: 'networkidle'});
    await page.getByText('registered@example.test', {exact: true}).click();
    const dialog = page.getByRole('dialog');
    const ip = dialog.locator('.key-values > div').filter({has: page.locator('strong', {hasText: '注册 IP'})});
    await ip.getByText('2001:db8::5', {exact: true}).waitFor();
    await ip.locator('.ant-typography-copy').click();
    assert.equal(await page.evaluate(() => navigator.clipboard.readText()), '2001:db8::5');
    await dialog.locator('.ant-drawer-close').click();
    await page.getByText('legacy@example.test', {exact: true}).click();
    await dialog.locator('.key-values > div').filter({has: page.locator('strong', {hasText: '注册 IP'})}).getByText('未知', {exact: true}).waitFor();
    await dialog.locator('.ant-drawer-close').click();
    await page.getByRole('button', {name: '过滤器', exact: true}).click();
    if (!await page.getByRole('combobox', {name: '筛选字段 1'}).count()) {
        await page.getByRole('button', {name: '添加筛选条件'}).click();
    }
    await page.getByRole('combobox', {name: '筛选字段 1'}).locator('xpath=ancestor::div[contains(@class,"ant-select")][1]').click();
    await page.locator('.ant-select-dropdown:visible').getByText('注册 IP', {exact: true}).click();
    await page.getByPlaceholder('请输入欲检索内容').fill('2001:db8::5');
    const fetched = page.waitForResponse(response => {
        const url = new URL(response.url());
        return url.pathname.endsWith('/user/fetch') && url.searchParams.get('filter[0][key]') === 'register_ip';
    });
    await page.getByRole('button', {name: '筛选用户', exact: true}).click();
    const response = await fetched;
    const url = new URL(response.url());
    assert.equal(url.searchParams.get('filter[0][condition]'), '=');
    assert.equal(url.searchParams.get('filter[0][value]'), '2001:db8::5');
    assert.deepEqual((await response.json()).data.map(row => row.id), [901]);
    await page.getByText('registered@example.test', {exact: true}).waitFor();
    assert.equal(await page.getByText('legacy@example.test', {exact: true}).count(), 0);
    assert.deepEqual(errors, []);
    console.log('PASS registration IP display, copying, unknown legacy value and exact user filtering');
} finally {
    await browser.close();
    await fixture.close();
}
