import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { mkdir, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { startFixture } from './fixture.mjs';

if (process.env.ADMIN_LIVE_URL) throw new Error('external-nodes.mjs requires the synthetic loopback fixture');
const fixture = await startFixture();
fixture.store.groups.push({ id: 1, name: '权限组 A', user_count: 0, server_count: 0 }, { id: 2, name: '权限组 B', user_count: 0, server_count: 0 });
const uuid = 'c0d71ce0-1d13-4c78-a329-a16e0521f061';
const initialConfig = { protocol: 'vless', host: 'external.example.test', port: 443, auth: { uuid }, network: 'tcp', tls: 2, tls_settings: { server_name: 'tls.example.test', public_key: 'fixture-public-key', short_id: 'ab12' }, network_settings: {}, flow: 'xtls-rprx-vision' };
const nodes = [{ id: 10, name: '现有外部节点', group_id: [1], show: 1, config: initialConfig }];
const calls = [], failures = [], passed = [];
let nextId = 11, rejectSave = false;
const output = resolve(import.meta.dirname, '../test-results/external-nodes'); await mkdir(output, { recursive: true });
const browser = await chromium.launch({ executablePath: process.env.ADMIN_BROWSER_PATH ?? 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe' });
const context = await browser.newContext({ viewport: { width: 1440, height: 1100 }, locale: 'zh-CN' });
const page = await context.newPage(); page.on('pageerror', e => failures.push({ name: 'runtime', message: e.message }));
await page.route('**/api/v1/test-admin/server/group/external/*', async route => {
    const request = route.request(), url = new URL(request.url()), action = url.pathname.split('/').at(-1), data = request.postDataJSON() ?? {};
    calls.push({ action, data });
    let result = true;
    if (action === 'fetch') result = nodes.filter(node => node.group_id.includes(Number(url.searchParams.get('group_id'))));
    if (action === 'parse') {
        if (data.uri.includes('unsupported')) return route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ message: 'The given data was invalid.', errors: { config: ['暂不支持链接参数：unsupported'] } }) });
        result = { name: '导入节点', config: structuredClone(initialConfig) };
    }
    if (action === 'save') {
        if (rejectSave) return route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ message: 'The given data was invalid.', errors: { config: ['保存失败，请重试'] } }) });
        const row = { ...data, id: data.id ?? nextId++ }, index = nodes.findIndex(node => node.id === row.id);
        if (index < 0) nodes.push(row); else nodes[index] = row;
        result = row;
    }
    if (action === 'update') Object.assign(nodes.find(node => node.id === data.id), { show: data.show });
    if (action === 'drop') nodes.splice(nodes.findIndex(node => node.id === data.id), 1);
    if (action === 'sort') nodes.sort((a, b) => data.ids.indexOf(a.id) - data.ids.indexOf(b.id));
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: result }) });
});
const manager = () => page.locator('.ant-drawer-open').filter({ has: page.locator('.group-external-nodes') });
const editor = () => page.locator('.ant-drawer-open').filter({ has: page.locator('.external-node-editor') });
const check = async (name, run) => { try { await run(); passed.push(name); console.log('PASS ' + name); } catch (e) { failures.push({ name, message: e.message }); await page.screenshot({ path: resolve(output, 'failure.png'), fullPage: true }); throw e; } };
const selectOption = async (scope, id, text) => {
    const select = scope.locator('#' + id);
    await select.locator('xpath=ancestor::div[contains(@class,"ant-select-selector")][1]').click();
    const list = await select.getAttribute('aria-controls');
    await page.locator('.ant-select-dropdown:visible').filter({ has: page.locator('[id="' + list + '"]') }).locator('.ant-select-item-option').filter({ hasText: new RegExp('^' + text + '$') }).click();
};
const configDrawer = title => page.locator('.ant-drawer-open').filter({ has: page.locator('.ant-drawer-title').filter({ hasText: new RegExp('^' + title + '$') }) });
try {
    await page.addInitScript(() => localStorage.setItem('v2board-admin-v1:' + location.host + ':test-admin', 'Bearer fixture-admin'));
    await page.goto(fixture.origin + '/#/server/group'); await page.reload({ waitUntil: 'networkidle' });
    await check('entry-is-per-permission-group-and-normal-node-management-is-unchanged', async () => {
        await page.locator('tr[data-row-key="1"]').getByText('外部节点', { exact: true }).click();
        await manager().getByText('权限组 A · 外部节点', { exact: true }).waitFor();
        await manager().getByText('现有外部节点', { exact: true }).waitFor();
        assert.equal(await manager().locator('.node-drag-handle').count(), 0);
        assert.equal(fixture.store.nodes.length, 0);
    });
    await check('import-populates-real-credentials-and-edits-without-reentering-them', async () => {
        await manager().getByRole('button', { name: '添加外部节点', exact: true }).click();
        await editor().locator('#external-node-uri').fill(`vless://${uuid}@external.example.test:443?unsupported=1`);
        await editor().getByRole('button', { name: '解析链接', exact: true }).click();
        await editor().getByText('暂不支持链接参数：unsupported', { exact: true }).waitFor();
        await editor().locator('#external-node-uri').fill(`vless://${uuid}@external.example.test:443?security=reality&pbk=fixture-public-key`);
        await editor().getByRole('button', { name: '解析链接', exact: true }).click();
        await editor().locator('#name').waitFor(); await page.waitForFunction(() => document.querySelector('.external-node-editor #name')?.value === '导入节点');
        assert.equal(await editor().locator('#auth_uuid').inputValue(), uuid);
        assert.equal(await editor().locator('#public_key').inputValue(), 'fixture-public-key');
        assert.ok((await editor().locator('.ant-select-selection-item').allTextContents()).some(t => t.includes('权限组 A')));
        await editor().locator('#name').fill('新增外部节点');
        rejectSave = true; await editor().getByRole('button', { name: '保存', exact: true }).click();
        await editor().getByText('保存失败，请重试', { exact: true }).waitFor();
        assert.equal(await editor().locator('#auth_uuid').inputValue(), uuid);
        rejectSave = false; await editor().getByRole('button', { name: '保存', exact: true }).click();
        await editor().waitFor({ state: 'hidden' }); await manager().getByText('新增外部节点', { exact: true }).waitFor();
        const save = calls.findLast(c => c.action === 'save').data;
        assert.deepEqual(save.group_id, [1]); assert.deepEqual(save.config.auth, { uuid });
        assert.equal(save.config.tls_settings.public_key, 'fixture-public-key');
        assert.equal(save.config.tls_settings.server_name, 'tls.example.test');
    });
    await check('editing-passwords-preserves-the-existing-value-and-hides-unrelated-protocol-fields', async () => {
        await manager().locator('tr[data-row-key="11"]').getByText('编辑', { exact: true }).click();
        const select = editor().locator('#protocol'); await select.locator('xpath=ancestor::div[contains(@class,"ant-select-selector")][1]').click();
        const list = await select.getAttribute('aria-controls');
        await page.locator('.ant-select-dropdown:visible').filter({ has: page.locator('[id="' + list + '"]') }).locator('.ant-select-item-option').filter({ hasText: /^trojan$/ }).click();
        await editor().locator('#auth_password').fill('external-fixed-password');
        assert.equal(await editor().locator('#public_key').count(), 0); assert.equal(await editor().locator('#auth_uuid').count(), 0);
        await editor().getByRole('button', { name: '保存', exact: true }).click(); await editor().waitFor({ state: 'hidden' });
        const save = calls.findLast(c => c.action === 'save').data;
        assert.deepEqual(save.config.auth, { password: 'external-fixed-password' }); assert.equal(save.config.tls ?? 1, 1);
        await manager().locator('tr[data-row-key="11"]').getByText('编辑', { exact: true }).click();
        assert.equal(await editor().locator('#auth_password').inputValue(), 'external-fixed-password');
        await editor().getByRole('button', { name: '保存', exact: true }).click(); await editor().waitFor({ state: 'hidden' });
        assert.equal(calls.findLast(c => c.action === 'save').data.config.auth.password, 'external-fixed-password');
    });
    await check('sort-handles-only-appear-in-edit-mode-and-save-group-scoped-order', async () => {
        await manager().getByRole('button', { name: '编辑排序', exact: true }).click();
        const handle = manager().getByRole('button', { name: '拖动排序 新增外部节点', exact: true });
        await handle.press('ArrowUp'); await manager().getByRole('button', { name: '保存排序', exact: true }).click();
        await manager().getByRole('button', { name: '编辑排序', exact: true }).waitFor();
        assert.deepEqual(calls.findLast(c => c.action === 'sort').data, { group_id: 1, ids: [11, 10] });
        assert.equal(await manager().locator('.node-drag-handle').count(), 0);
    });
    await check('visibility-delete-confirmation-and-group-switching', async () => {
        await manager().locator('tr[data-row-key="11"]').getByRole('switch').click();
        await page.waitForFunction(() => document.querySelector('.group-external-nodes tr[data-row-key="11"] button[role="switch"]')?.getAttribute('aria-checked') === 'false');
        await manager().locator('tr[data-row-key="11"]').getByText('删除', { exact: true }).click();
        const confirm = page.locator('.ant-modal-confirm'); await confirm.getByRole('button', { name: '取消', exact: true }).click();
        assert.equal(nodes.length, 2);
        await manager().locator('tr[data-row-key="11"]').getByText('删除', { exact: true }).click();
        await page.locator('.ant-modal-confirm').getByRole('button', { name: '确定', exact: true }).click();
        await manager().locator('tr[data-row-key="11"]').waitFor({ state: 'hidden' });
        await manager().locator('.ant-drawer-close').click(); await manager().waitFor({ state: 'hidden' });
        await page.locator('tr[data-row-key="2"]').getByText('外部节点', { exact: true }).click();
        await manager().getByText('权限组 B · 外部节点', { exact: true }).waitFor();
        assert.equal(await manager().getByText('现有外部节点', { exact: true }).count(), 0);
    });
    await check('manual-websocket-fields-edit-nested-json-and-roundtrip-with-multiple-groups', async () => {
        await manager().getByRole('button', { name: '添加外部节点', exact: true }).click();
        await selectOption(editor(), 'protocol', 'vmess');
        await editor().locator('#name').fill('manual-ws'); await editor().locator('#host').fill('node.example.test');
        await editor().locator('#port').fill('443'); await editor().locator('#auth_uuid').fill(uuid);
        await selectOption(editor(), 'network', 'ws'); await selectOption(editor(), 'tls', 'TLS');
        await editor().locator('#server_name').fill('tls.example.test');
        await editor().getByRole('button', { name: '编辑传输配置', exact: true }).click();
        const transport = configDrawer('编辑传输配置');
        await transport.getByText('路径', { exact: true }).locator('input').fill('/socket');
        await transport.getByRole('button', { name: '编辑请求头', exact: true }).click();
        const headers = configDrawer('编辑请求头');
        await headers.getByRole('textbox', { name: '请求头 JSON', exact: true }).fill('{"Host":');
        assert.equal(await headers.getByRole('button', { name: '完成', exact: true }).isDisabled(), true);
        await headers.getByRole('textbox', { name: '请求头 JSON', exact: true }).fill('{"Host":"front.example.test"}');
        await headers.getByRole('button', { name: '完成', exact: true }).click(); await headers.waitFor({ state: 'hidden' });
        const settings = { path: '/socket', headers: { Host: 'front.example.test' } };
        assert.deepEqual(JSON.parse(await transport.getByRole('textbox', { name: '传输配置 JSON', exact: true }).inputValue()), settings);
        await transport.getByRole('button', { name: '完成', exact: true }).click(); await transport.waitFor({ state: 'hidden' });
        await selectOption(editor(), 'group_id', '权限组 A'); await editor().locator('#name').click();
        await editor().getByRole('button', { name: '保存', exact: true }).click(); await editor().waitFor({ state: 'hidden' });
        let data = calls.findLast(c => c.action === 'save').data;
        assert.deepEqual([...data.group_id].sort(), [1, 2]); assert.deepEqual(data.config.network_settings, settings);
        const id = nodes.find(n => n.name === 'manual-ws').id;
        await manager().locator(`tr[data-row-key="${id}"]`).getByText('编辑', { exact: true }).click();
        await selectOption(editor(), 'tls', '无'); assert.equal(await editor().locator('#server_name').count(), 0);
        await selectOption(editor(), 'tls', 'TLS'); assert.equal(await editor().locator('#server_name').inputValue(), 'tls.example.test');
        await editor().getByRole('button', { name: '编辑传输配置', exact: true }).click();
        assert.deepEqual(JSON.parse(await transport.getByRole('textbox', { name: '传输配置 JSON', exact: true }).inputValue()), settings);
        await transport.getByRole('button', { name: '完成', exact: true }).click(); await transport.waitFor({ state: 'hidden' });
        await editor().getByRole('button', { name: '保存', exact: true }).click(); await editor().waitFor({ state: 'hidden' });
        data = calls.findLast(c => c.action === 'save').data;
        assert.deepEqual(data.config.network_settings, settings); assert.equal(data.config.tls_settings.server_name, 'tls.example.test');
    });
    await check('xhttp-extra-is-an-object-after-editing-and-reopening', async () => {
        const id = nodes.find(n => n.name === 'manual-ws').id;
        await manager().locator(`tr[data-row-key="${id}"]`).getByText('编辑', { exact: true }).click();
        await selectOption(editor(), 'protocol', 'vless'); await selectOption(editor(), 'network', 'xhttp');
        await editor().getByRole('button', { name: '编辑传输配置', exact: true }).click();
        const transport = configDrawer('编辑传输配置');
        await transport.getByText('路径', { exact: true }).locator('input').fill('/x');
        await transport.getByText('Host', { exact: true }).locator('input').fill('front.example.test');
        await transport.getByText('模式', { exact: true }).locator('input').fill('packet-up');
        await transport.getByRole('button', { name: '编辑Extra', exact: true }).click();
        const extra = configDrawer('编辑Extra'), value = { xmux: { maxConcurrency: 8 } };
        await extra.getByRole('textbox', { name: 'Extra JSON', exact: true }).fill(JSON.stringify(value));
        await extra.getByRole('button', { name: '完成', exact: true }).click(); await extra.waitFor({ state: 'hidden' });
        await transport.getByRole('button', { name: '完成', exact: true }).click(); await transport.waitFor({ state: 'hidden' });
        await editor().getByRole('button', { name: '保存', exact: true }).click(); await editor().waitFor({ state: 'hidden' });
        assert.deepEqual(calls.findLast(c => c.action === 'save').data.config.network_settings.extra, value);
        await manager().locator(`tr[data-row-key="${id}"]`).getByText('编辑', { exact: true }).click();
        await editor().getByRole('button', { name: '编辑传输配置', exact: true }).click();
        assert.deepEqual(JSON.parse(await transport.getByRole('textbox', { name: '传输配置 JSON', exact: true }).inputValue()).extra, value);
        await transport.getByRole('button', { name: '完成', exact: true }).click(); await transport.waitFor({ state: 'hidden' });
        await editor().getByRole('button', { name: '取消', exact: true }).click(); await editor().waitFor({ state: 'hidden' });
    });
    assert.deepEqual(failures, []);
} finally {
    await writeFile(resolve(output, 'report.json'), JSON.stringify({ passed, failures }, null, 2));
    await browser.close(); await fixture.close();
}
