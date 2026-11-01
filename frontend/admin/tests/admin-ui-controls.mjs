import assert from "node:assert/strict";
import { chromium } from "playwright";
import { mkdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { startFixture } from "./fixture.mjs";

const output = resolve(import.meta.dirname, "../test-results/admin-ui-controls");
await mkdir(output, { recursive: true });
const report = { kind: "admin-ui-controls", startedAt: new Date().toISOString(), passed: [], failures: [] };
let fixture, browser, context, page, apiCalls;
const pause = (ms = 150) => new Promise((resolve) => setTimeout(resolve, ms));
const goto = async (route) => {
    await page.goto(fixture.origin + "/#" + route);
    await page.reload({ waitUntil: "networkidle" });
    await page.locator(".ant-spin-spinning").waitFor({ state: "detached" });
};
const assertNoOverlap = async (locator, label) => {
    const box = await locator.boundingBox();
    assert.ok(box, `${label} should be visible`);
    const text = await locator.evaluate((el) => ({
        whiteSpace: getComputedStyle(el).whiteSpace,
        scrollWidth: el.scrollWidth,
        clientWidth: el.clientWidth,
    }));
    assert.equal(text.whiteSpace, "nowrap", `${label} must not wrap`);
    assert.ok(text.scrollWidth <= text.clientWidth + 1, `${label} text must fit its cell`);
};

try {
    fixture = await startFixture({
        visual: true,
        settings: {
            title: "A Very Long Fixture Brand Name for Centering Checks",
            logo: "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Ccircle cx='16' cy='16' r='15' fill='%23fff'/%3E%3C/svg%3E",
        },
    });
    apiCalls = fixture.calls;
    assert.ok(Array.isArray(apiCalls), "fixture should expose its recorded calls array");
    browser = await chromium.launch({
        executablePath:
            process.env.ADMIN_BROWSER_PATH ??
            "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe",
    });
    context = await browser.newContext({ viewport: { width: 1360, height: 950 }, locale: "zh-CN" });
    page = await context.newPage();
    fixture.store.coupon.push(
        { id: 101, name: "Long coupon fixture", code: "CPN-101", type: 1, value: 1250, limit_use: 99, started_at: 1791993637, ended_at: 1794585637, show: 1 },
        { id: 102, name: "Percent coupon fixture", code: "CPN-102", type: 2, value: 15, limit_use: 99, started_at: 1791993637, ended_at: 1794585637, show: 1 },
    );
    fixture.store.giftcard.push(
        { id: 201, name: "Long gift card fixture", code: "GFT-201", type: 1, value: 2500, limit_use: 99, started_at: 1791993637, ended_at: 1794585637 },
        { id: 202, name: "Second gift card fixture", code: "GFT-202", type: 2, value: 30, limit_use: 99, started_at: 1791993637, ended_at: 1794585637 },
    );
    fixture.store.users.push({
        id: 301, email: "ui-controls@example.test", plan_id: null, group_id: 1,
        balance: 0, transfer_enable: 0, u: 0, d: 0, total_used: 0,
        device_limit: 1, alive_ip: 3,
        ips: "203.0.113.10_node-a, 2001:db8::10_node-b, 203.0.113.10_node-c",
        created_at: Math.floor(Date.now() / 1000), expired_at: null, banned: 0,
    });
    fixture.store.users.push(...Array.from({ length: 24 }, (_, index) => ({
        id: 400 + index, email: `pagination-${index}@example.test`, plan_id: null,
        group_id: 1, balance: 0, transfer_enable: 0, u: 0, d: 0,
        total_used: 0, device_limit: 2, alive_ip: index % 4,
        ips: "", created_at: Math.floor(Date.now() / 1000) - index, expired_at: null, banned: 0,
    })));
    fixture.store.nodes.push(
        { id: 41, type: "vmess", name: "same-id-vmess", host: "vmess.example.test", server_port: 443, port: "443", group_id: [], show: 1, rate: 1, tls: 0, network: "tcp" },
        { id: 41, type: "trojan", name: "same-id-trojan", host: "trojan.example.test", server_port: 443, port: "443", group_id: [], show: 1, rate: 1 },
        { id: 42, type: "vless", name: "hidden-vless", host: "hidden.example.test", server_port: 8443, port: "8443", group_id: [], show: 0, rate: 1, tls: 0, network: "tcp" },
        { id: 43, type: "vmess", parent_id: 41, name: "child-vmess", host: "child.example.test", server_port: 443, port: "443", group_id: [], show: 1, rate: 1, tls: 0, network: "tcp" },
    );

    const login = await context.request.post(fixture.origin + "/api/v1/passport/auth/login", {
        data: { email: "admin@example.test", password: "fixture" },
    });
    assert.ok(login.ok(), "fixture admin login must succeed");
    await page.addInitScript(() => {
        window.__clipboardWrites = [];
        Object.defineProperty(navigator, "clipboard", {
            configurable: true,
            value: { writeText: async (text) => window.__clipboardWrites.push(text) },
        });
    });
    await page.goto(fixture.origin + "/#/login");
    await page.getByLabel("邮箱", { exact: true }).fill("admin@example.test");
    await page.getByLabel("密码", { exact: true }).fill("fixture");
    await page.getByRole("button", { name: "登录", exact: true }).click();
    await page.waitForURL("**#/dashboard");

    // Narrow viewport action cells: visible controls must remain on one line and fit.
    for (const width of [720, 480]) {
        await page.setViewportSize({ width, height: 900 });
        for (const path of ["/coupon", "/giftcard"]) {
            await goto(path);
            const rows = page.locator(".ant-table-tbody tr").filter({ hasText: path === "/coupon" ? "CPN-101" : "GFT-201" });
            await rows.waitFor({ state: "visible" });
            const actions = rows.locator(".inline-row-actions");
            await assertNoOverlap(actions, `${path} inline edit/delete actions at ${width}px`);
            const toggle = rows.locator(".ant-switch");
            if (await toggle.count()) {
                const alignment = await toggle.evaluate((el) => {
                    const td = el.closest("td");
                    const style = getComputedStyle(td);
                    const cell = td.getBoundingClientRect();
                    const control = el.getBoundingClientRect();
                    const contentLeft = cell.left + parseFloat(style.paddingLeft);
                    return { delta: control.left - contentLeft, paddingLeft: style.paddingLeft, textAlign: style.textAlign };
                });
                assert.ok(Math.abs(alignment.delta) < 1,
                    `${path} table switch should start at cell content edge (padding ${alignment.paddingLeft}; delta ${alignment.delta}px)`);
                assert.notEqual(alignment.textAlign, "right", `${path} table switch cell must not be right aligned`);
            }
            const cells = rows.locator("td");
            const count = await cells.count();
            const actionBox = await actions.boundingBox();
            const cellBox = await cells.nth(count - 1).boundingBox();
            assert.ok(actionBox.x >= cellBox.x - 1 && actionBox.x + actionBox.width <= cellBox.x + cellBox.width + 1,
                `${path} actions must stay inside the operation cell at ${width}px`);
        }
    }
    report.passed.push("resource action rows and table toggles at narrow widths");

    // Settings switch sits at the left edge of its content; saving exposes loading and success.
    await page.setViewportSize({ width: 1360, height: 950 });
    await goto("/config/system");
    const settingsSwitchField = page.locator(".settings-fields .settings-switch-field").first();
    await settingsSwitchField.waitFor({ state: "visible" });
    const settingSwitch = settingsSwitchField.locator(".ant-switch");
    await settingSwitch.waitFor({ state: "visible" });
    await settingSwitch.scrollIntoViewIfNeeded();
    const settingAlignment = await settingsSwitchField.evaluate((field) => {
        const control = field.querySelector(".ant-switch").getBoundingClientRect();
        const label = field.querySelector(".settings-label").getBoundingClientRect();
        return { delta: control.left - label.left };
    });
    assert.ok(Math.abs(settingAlignment.delta) < 1,
        `settings switch should align with its label left edge (delta ${settingAlignment.delta}px)`);
    let saveCount = 0;
    await page.route("**/config/save", async (route) => {
        saveCount++;
        if (saveCount === 1) {
            await pause(700);
            await route.fulfill({ status: 500, contentType: "application/json", body: JSON.stringify({ message: "fixture save failure" }) });
        } else {
            const response = await route.fetch();
            await pause(250);
            await route.fulfill({ response });
        }
    });
    await settingSwitch.click();
    await page.getByRole("status").getByText("保存中").waitFor({ state: "visible" });
    await page.getByText("正在保存设置", { exact: true }).waitFor({ state: "visible" });
    await page.locator(".settings-save-state .anticon-loading").waitFor({ state: "visible" });
    await page.getByRole("status").getByText("保存失败").waitFor({ state: "visible", timeout: 5000 });
    const valueAfterFailure = await settingSwitch.getAttribute("aria-checked");
    await page.getByRole("button", { name: "重试保存" }).click();
    await page.getByRole("status").getByText("已保存").waitFor({ state: "visible", timeout: 5000 });
    assert.equal(await settingSwitch.getAttribute("aria-checked"), valueAfterFailure, "retry must preserve the changed setting value");
    assert.ok(saveCount >= 2, "failed save should be retried");
    await page.getByText("设置已保存", { exact: true }).waitFor({ state: "visible" });
    report.passed.push("settings switch alignment, delayed save indicator, failure retry, success feedback");

    // Brand centering remains correct with both a long title and a logo.
    const brand = page.locator("a.brand");
    await brand.waitFor({ state: "visible" });
    assert.equal(await brand.locator("img").count(), 1, "brand fixture should include a logo");
    const brandMetrics = await brand.evaluate((el) => {
        const box = el.getBoundingClientRect(), img = el.querySelector("img").getBoundingClientRect();
        const title = el.querySelector("span").getBoundingClientRect();
        return { center: box.left + box.width / 2, groupCenter: (img.left + title.right) / 2, titleOverflow: el.querySelector("span").scrollWidth > el.querySelector("span").clientWidth };
    });
    assert.ok(Math.abs(brandMetrics.groupCenter - brandMetrics.center) < 1, "logo and long title group should be centered");
    assert.ok(brandMetrics.titleOverflow, "long brand title should be clipped without expanding the brand bar");
    report.passed.push("brand centering with long title and logo");

    // Device count popover remains reachable, selectable, and copies unique IPs.
    await goto("/user");
    const userRow = page.locator(".ant-table-tbody tr").filter({ hasText: "ui-controls@example.test" });
    await userRow.waitFor({ state: "visible" });
    const deviceCount = userRow.locator(".user-device-count");
    await deviceCount.hover();
    const popover = page.locator(".ant-popover").filter({ hasText: "在线 IP" });
    await popover.waitFor({ state: "visible" });
    const ipText = popover.locator("code").nth(1);
    await ipText.evaluate((el) => {
        const range = document.createRange();
        range.selectNodeContents(el);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);
    });
    assert.equal(await page.evaluate(() => window.getSelection().toString()), "2001:db8::10", "IP text should be selectable");
    assert.equal(await page.evaluate(() => getComputedStyle(document.querySelector(".user-device-ips")).userSelect), "text");
    await popover.getByRole("button", { name: "复制 IP 203.0.113.10" }).first().click();
    await popover.getByRole("button", { name: "复制全部 IP" }).click();
    assert.deepEqual(await page.evaluate(() => window.__clipboardWrites.slice(-2)), ["203.0.113.10", "203.0.113.10\n2001:db8::10"]);
    report.passed.push("device IP popover selection and single/all copy");

    // Server side sort request uses alive_ip, not device_limit, and resets to page 1.
    const pageTwo = page.locator(".ant-pagination-item").filter({ hasText: "2" }).first();
    if (await pageTwo.count()) {
        await pageTwo.click();
        await page.waitForFunction(() => document.querySelector(".ant-pagination-item-active")?.textContent === "2");
    }
    const beforeSort = apiCalls.filter((call) => call.path === "/user/fetch").length;
    const firstSortResponse = page.waitForResponse((response) => response.url().includes("/user/fetch") && response.url().includes("sort=alive_ip") && response.url().includes("sort_type=ASC"));
    await page.getByRole("columnheader", { name: /设备数/ }).click();
    await firstSortResponse;
    await page.locator(".ant-spin-spinning").waitFor({ state: "detached" });
    const userFetches = apiCalls.filter((call) => call.path === "/user/fetch");
    const sortedFetch = userFetches.at(-1);
    assert.equal(sortedFetch.query.sort, "alive_ip", "device sort must use the online IP count field");
    assert.equal(sortedFetch.query.sort_type, "ASC", "first device sort should request ASC");
    assert.notEqual(sortedFetch.query.sort, "device_limit", "device limit is not the online count");
    assert.equal(sortedFetch.query.current, "1", "sort change should reset pagination to page one");
    const secondSortResponse = page.waitForResponse((response) => response.url().includes("/user/fetch") && response.url().includes("sort_type=DESC"));
    await page.getByRole("columnheader", { name: /设备数/ }).click();
    await secondSortResponse;
    await page.locator(".ant-spin-spinning").waitFor({ state: "detached" });
    assert.equal(apiCalls.filter((call) => call.path === "/user/fetch").at(-1).query.sort_type, "DESC", "second click should request DESC");
    report.passed.push("alive_ip server sort direction and page reset");

    // Node drag handles work without entering edit-sort first; preserve filtered-out nodes
    // and distinguish same numeric IDs by protocol. Failed sort remains retryable.
    await goto("/server/manage");
    assert.equal(await page.locator(".nodes-table .node-drag-handle").count(), 0, "sorting handles should be hidden before editing sort");
    assert.equal(await page.getByRole("columnheader", { name: "排序", exact: true }).count(), 0, "sorting column should be hidden before editing sort");
    const childRow = page.locator(".nodes-table tr.ant-table-row").filter({ hasText: "child-vmess" });
    const parentRow = page.locator(".nodes-table tr.ant-table-row").filter({ hasText: "same-id-vmess" });
    await childRow.waitFor({ state: "visible" });
    assert.ok(await childRow.evaluate((row) => row.classList.contains("child_node")));
    assert.equal(await parentRow.evaluate((row) => row.classList.contains("child_node")), false);
    assert.equal(await childRow.evaluate((row) => getComputedStyle(row).backgroundColor), "rgb(238, 238, 238)");
    assert.equal(await childRow.evaluate((row) => getComputedStyle(row).opacity), "1");
    assert.equal(await childRow.locator("td").last().evaluate((cell) => getComputedStyle(cell).backgroundColor), "rgb(238, 238, 238)", "fixed operation cell must retain the child background");
    await page.screenshot({ path: resolve(output, "node-parent-current.png"), fullPage: true });
    assert.ok(await childRow.getByRole("switch").isEnabled());
    await childRow.getByRole("checkbox").check();
    assert.ok(await childRow.evaluate((row) => row.classList.contains("ant-table-row-selected")));
    assert.notEqual(await childRow.locator("td").last().evaluate((cell) => getComputedStyle(cell).backgroundColor), "rgb(238, 238, 238)", "selection must keep its highlight");
    await childRow.getByRole("checkbox").uncheck();
    await childRow.getByText("child-vmess", { exact: true }).click();
    const childEditor = page.locator(".ant-drawer-open:visible");
    await childEditor.waitFor();
    await childEditor.getByRole("button", { name: "取消", exact: true }).click();
    await childEditor.waitFor({ state: "hidden" });
    report.passed.push("original child-node background with working selection and editor");
    await page.getByPlaceholder("输入任意关键字搜索").fill("same-id");
    await page.waitForFunction(() => document.querySelectorAll(".nodes-table tr.ant-table-row").length === 2);
    await page.getByRole("button", { name: "编辑排序", exact: true }).click();
    await page.getByRole("columnheader", { name: "排序", exact: true }).waitFor({ state: "visible" });
    const vmessRow = page.locator(".nodes-table tr.ant-table-row").filter({ hasText: "same-id-vmess" });
    const vmessHandle = vmessRow.getByRole("button", { name: "拖动排序 same-id-vmess" });
    await vmessHandle.waitFor({ state: "visible" });
    const trojanRow = page.locator(".nodes-table tr.ant-table-row").filter({ hasText: "same-id-trojan" });
    await vmessHandle.waitFor({ state: "visible" });
    await vmessHandle.dragTo(trojanRow);
    const initialSortResponse = page.waitForResponse((response) => response.url().includes("/server/manage/sort"));
    await page.getByRole("button", { name: "保存排序" }).click();
    await initialSortResponse;
    await page.getByText("排序已保存", { exact: true }).waitFor({ state: "visible" });
    await page.locator(".ant-spin-spinning").waitFor({ state: "detached" });
    await page.getByRole("columnheader", { name: "排序", exact: true }).waitFor({ state: "detached" });
    assert.equal(await page.locator(".nodes-table .node-drag-handle").count(), 0, "sorting handles should disappear after saving");
    const sortCall = apiCalls.filter((call) => call.path === "/server/manage/sort").at(-1);
    assert.ok(sortCall?.data.vmess?.[41] && sortCall?.data.trojan?.[41], "sort payload must keep protocol/id pairs separate");
    assert.ok(sortCall.data.vless?.[42], "filtered/hidden node must remain in the submitted sort payload");
    assert.ok(sortCall.data.vmess?.[43], "filtered child node must remain in the submitted sort payload");
    assert.ok(sortCall.data.trojan[41] < sortCall.data.vmess[41], "dragging vmess onto trojan should place trojan first");

    // Exercise retry without losing the already rearranged full ordering.
    let sortRequests = 0;
    const interceptedSortPayloads = [];
    await page.route("**/server/manage/sort", async (route) => {
        sortRequests++;
        interceptedSortPayloads.push(route.request().postDataJSON());
        if (sortRequests === 1) await route.fulfill({ status: 500, contentType: "application/json", body: JSON.stringify({ message: "fixture sort failure" }) });
        else await route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ data: true }) });
    });
    await page.getByRole("button", { name: "编辑排序", exact: true }).click();
    await page.getByRole("columnheader", { name: "排序", exact: true }).waitFor({ state: "visible" });
    const firstHandle = page.locator(".nodes-table tr.ant-table-row .node-drag-handle").first();
    const secondRow = page.locator(".nodes-table tr.ant-table-row").nth(1);
    await firstHandle.dragTo(secondRow);
    const failedSortResponse = page.waitForResponse((response) => response.url().includes("/server/manage/sort"));
    await page.getByRole("button", { name: "保存排序" }).click();
    assert.equal((await failedSortResponse).status(), 500);
    await page.getByText("fixture sort failure", { exact: true }).waitFor({ state: "visible" });
    assert.equal(await page.getByRole("button", { name: "保存排序" }).count(), 1, "failed sort must remain pending and retryable");
    const failedPayload = interceptedSortPayloads[0];
    assert.ok(failedPayload?.vmess?.[41] && failedPayload?.trojan?.[41] && failedPayload?.vless?.[42], "failed sort should capture the complete mixed/hidden-node payload");
    assert.ok(failedPayload.trojan[41] < failedPayload.vmess[41], "failed payload should contain the adjusted order");
    const retrySortResponse = page.waitForResponse((response) => response.url().includes("/server/manage/sort"));
    await page.getByRole("button", { name: "保存排序" }).click();
    assert.equal((await retrySortResponse).status(), 200);
    await page.getByText("排序已保存", { exact: true }).waitFor({ state: "visible" });
    const retriedPayload = interceptedSortPayloads[1];
    assert.equal(interceptedSortPayloads.length, 2, "failure and retry should both reach the intercepting route");
    assert.deepEqual(retriedPayload, failedPayload, "retry must submit the preserved pending order");
    await page.getByRole("columnheader", { name: "排序", exact: true }).waitFor({ state: "detached" });
    assert.equal(await page.locator(".nodes-table .node-drag-handle").count(), 0, "sorting handles should disappear after retry succeeds");

    // Adding a patch field keeps the batch editor mounted and preserves values.
    await page.getByPlaceholder("输入任意关键字搜索").fill("");
    const batchParent = page.locator(".nodes-table tr.ant-table-row").filter({ hasText: "same-id-vmess" });
    const batchChild = page.locator(".nodes-table tr.ant-table-row").filter({ hasText: "child-vmess" });
    await batchParent.getByRole("checkbox").check();
    await batchChild.getByRole("checkbox").check();
    await page.getByRole("button", { name: "批量编辑", exact: true }).click();
    const batchDrawer = page.locator(".ant-drawer-open:visible").filter({ has: page.locator(".original-editor-drawer") }).last();
    await batchDrawer.waitFor({ state: "visible" });
    await batchDrawer.locator(".ant-drawer-content-wrapper").evaluate(async (wrapper) => {
        await Promise.all(wrapper.getAnimations().map((animation) => animation.finished.catch(() => {})));
    });
    const rateField = batchDrawer.getByLabel("流量倍率");
    await rateField.click();
    await rateField.fill("2.75");
    await batchDrawer.evaluate((drawer) => {
        const wrapper = drawer.querySelector(".ant-drawer-content-wrapper");
        window.__batchDrawerNode = drawer;
        window.__batchWrapperNode = wrapper;
        window.__batchOpenTransitions = 0;
        wrapper.addEventListener("transitionrun", (event) => {
            if (event.target === wrapper && event.propertyName === "transform") window.__batchOpenTransitions++;
        });
        wrapper.addEventListener("animationstart", (event) => {
            if (event.target === wrapper) window.__batchOpenTransitions++;
        });
    });
    const patchSelect = batchDrawer.getByRole("combobox", { name: "批量修改字段", exact: true });
    await patchSelect.locator("xpath=ancestor::div[contains(concat(' ',normalize-space(@class),' '),' ant-select ')][1]").locator(".ant-select-selector").click();
    await page.locator(".ant-select-dropdown:visible .ant-select-item-option-content").getByText("节点名称", { exact: true }).click();
    const batchName = batchDrawer.getByLabel("节点名称");
    await batchName.waitFor({ state: "visible" });
    const editorState = await page.evaluate(() => {
        const drawer = document.querySelector(".original-editor-drawer")?.closest(".ant-drawer-open");
        return {
            sameDrawer: window.__batchDrawerNode === drawer,
            sameWrapper: window.__batchWrapperNode === drawer?.querySelector(".ant-drawer-content-wrapper"),
            openTransitions: window.__batchOpenTransitions,
        };
    });
    assert.ok(editorState.sameDrawer && editorState.sameWrapper, "batch field updates must preserve Drawer DOM identity");
    assert.equal(editorState.openTransitions, 0, "adding a batch field must not replay the Drawer opening animation");
    assert.equal(await rateField.inputValue(), "2.75", "existing rate value should survive adding another field");
    await batchName.fill("batch-preserved");
    const batchResponse = page.waitForResponse((response) => response.url().endsWith("/server/manage/batch"));
    await batchDrawer.getByRole("button", { name: "保存", exact: true }).click();
    await batchResponse;
    await batchDrawer.waitFor({ state: "hidden" });
    const batchCall = apiCalls.filter((call) => call.path === "/server/manage/batch").at(-1);
    assert.deepEqual(Object.keys(batchCall.data.changes).sort(), ["name", "rate"], "batch payload should contain only checked fields");
    assert.deepEqual(batchCall.data.changes, { name: "batch-preserved", rate: 2.75 });
    assert.deepEqual(batchCall.data.nodes.map((node) => node.type + ":" + node.id).sort(), ["vmess:41", "vmess:43"]);
    report.passed.push("batch editor field expansion preserves drawer/value and submits only selected fields");
    report.passed.push("node drag ordering, mixed protocol IDs, hidden row retention, failed sort retry");

    console.log("admin-ui-controls: all fixture browser checks passed");
} catch (error) {
    const failure = { message: error?.message ?? String(error), stack: error?.stack ?? null };
    if (page) {
        try {
            failure.screenshot = "failure.png";
            await page.screenshot({ path: resolve(output, failure.screenshot), fullPage: true });
        } catch (screenshotError) {
            failure.screenshotError = screenshotError?.message ?? String(screenshotError);
        }
    }
    report.failures.push(failure);
    console.error(error);
    process.exitCode = 1;
} finally {
    if (browser) await browser.close();
    if (fixture) await fixture.close();
    report.finishedAt = new Date().toISOString();
    await writeFile(resolve(output, "report.json"), JSON.stringify(report, null, 2) + "\n");
}
