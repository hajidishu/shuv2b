import assert from "node:assert/strict";
import { chromium } from "playwright";
import { mkdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { startFixture } from "./fixture.mjs";

if (process.env.ADMIN_LIVE_URL)
    throw new Error("user-action-scope.mjs is fixture-only");

const output = resolve(import.meta.dirname, "../test-results/user-action-scope");
await mkdir(output, { recursive: true });
const report = { kind: "fixture", passed: [], failures: [] };
const prefix = "scope-" + Date.now();
const fixture = await startFixture();
let browser;
try {
    fixture.store.users.push(
        ...[
            { id: 901, email: `${prefix}-a@example.test` },
            { id: 902, email: `${prefix}-b@example.test` },
            { id: 903, email: "unmatched@example.test" },
        ].map((user) => ({
            ...user,
            is_admin: 0,
            is_staff: 0,
            banned: 0,
            balance: 0,
            commission_balance: 0,
            u: 0,
            d: 0,
            transfer_enable: 0,
            created_at: 1700000000,
        })),
    );

    const executablePath = process.env.ADMIN_BROWSER_PATH;
    if (!executablePath)
        throw new Error("ADMIN_BROWSER_PATH must point to isolated Linux Chromium");
    browser = await chromium.launch({ executablePath, headless: true });
    const context = await browser.newContext({
        locale: "zh-CN",
        viewport: { width: 1440, height: 1100 },
    });
    const page = await context.newPage();
    page.on("pageerror", (error) =>
        report.failures.push({ name: "runtime", message: error.message }),
    );
    await page.addInitScript(() =>
        localStorage.setItem(
            "v2board-admin-v1:" + location.host + ":test-admin",
            "Bearer fixture-admin",
        ),
    );
    await page.goto(fixture.origin + "/#/user");
    await page.reload({ waitUntil: "networkidle" });

    const table = page.locator(".user-table-context-menu .ant-table");
    const matchedRows = table.locator("tr[data-row-key]");
    await page.locator(".table-toolbar").getByRole("button", { name: "过滤器" }).click();
    const drawer = page.locator(".user-filter-drawer");
    await drawer.getByRole("button", { name: "添加筛选条件" }).click();
    await drawer.getByLabel("筛选值 1").fill(prefix);
    await drawer.getByRole("button", { name: "筛选用户" }).click();
    await page.waitForFunction(
        () => document.querySelectorAll(".user-table-context-menu .ant-table tr[data-row-key]").length === 2,
    );
    assert.deepEqual(
        await matchedRows.evaluateAll((rows) => rows.map((row) => row.dataset.rowKey)),
        ["901", "902"],
    );

    const actionMenu = async (label) => {
        await table.locator('tr[data-row-key="901"] .ant-checkbox-input').check();
        await page.locator(".table-toolbar").getByRole("button", { name: "操作" }).click();
        const menu = page.locator(".ant-dropdown:visible");
        await menu.getByText(label, { exact: true }).click();
        return menu;
    };
    const assertScope = (data) => {
        assert.deepEqual(data.ids, [901]);
        assert.deepEqual(data.filter, [{ key: "email", condition: "模糊", value: prefix }]);
        assert.equal(data.sort, "created_at");
        assert.equal(data.sort_type, "DESC");
    };
    const latest = (path) =>
        fixture.calls.filter((call) => call.path === path).at(-1);
    // Keep the action pending after the fixture mutates its data, as a slow API can.
    await page.route("**/user/ban", async (route) => {
        const response = await route.fetch();
        await new Promise((resolve) => setTimeout(resolve, 200));
        await route.fulfill({ response });
    });

    const download = page.waitForRequest((request) => request.url().endsWith("/user/dumpCSV"));
    const downloadResponse = page.waitForResponse((response) => response.url().endsWith("/user/dumpCSV"));
    await actionMenu("导出CSV");
    const downloadRequest = await download;
    const csv = await (await downloadResponse).text();
    const exportCall = latest("/user/dumpCSV");
    assertScope(exportCall.data);
    assert.equal(downloadRequest.postDataJSON().ids[0], 901);
    assert.ok(csv.includes(`${prefix}-a@example.test`));
    assert.ok(!csv.includes(`${prefix}-b@example.test`));
    assert.ok(!csv.includes("unmatched@example.test"));
    report.passed.push("export-preserves-filter-sort-and-selected-id");

    await actionMenu("发送邮件");
    const mailModal = page.locator(".user-mail-modal");
    await mailModal.getByLabel("主题").fill("Fixture scope check");
    await mailModal.getByLabel("内容").fill("Intercepted by local fixture");
    const mailResponse = page.waitForResponse((response) => response.url().endsWith("/user/sendMail"));
    await mailModal.getByRole("button", { name: "确定" }).click();
    assert.ok((await mailResponse).ok());
    await mailModal.waitFor({ state: "hidden" });
    assertScope(latest("/user/sendMail").data);
    assert.equal(latest("/user/sendMail").data.subject, "Fixture scope check");
    report.passed.push("mail-preserves-filter-sort-and-selected-id-with-fixture-only-request");

    await actionMenu("批量封禁");
    const banConfirm = page.locator(".ant-modal-confirm");
    const banResponse = page.waitForResponse((response) => response.url().endsWith("/user/ban"));
    await banConfirm.getByRole("button", { name: "确定" }).click();
    assert.ok((await banResponse).ok());
    await banConfirm.waitFor({ state: "hidden" });
    assertScope(latest("/user/ban").data);
    assert.equal(fixture.store.users.find((user) => user.id === 901).banned, 1);
    assert.equal(fixture.store.users.find((user) => user.id === 902).banned, 0);
    assert.equal(fixture.store.users.find((user) => user.id === 903).banned, 0);
    report.passed.push("ban-is-limited-to-selected-user-and-preserves-scope");

    fixture.store.users.find((user) => user.id === 901).banned = 0;
    await actionMenu("批量删除");
    const deleteConfirm = page.locator(".ant-modal-confirm");
    const deleteResponse = page.waitForResponse((response) => response.url().endsWith("/user/allDel"));
    await deleteConfirm.getByRole("button", { name: "确定" }).click();
    assert.ok((await deleteResponse).ok());
    await deleteConfirm.waitFor({ state: "hidden" });
    assertScope(latest("/user/allDel").data);
    assert.deepEqual(
        fixture.store.users.map((user) => user.id).filter((id) => [901, 902, 903].includes(id)),
        [902, 903],
    );
    report.passed.push("delete-is-limited-to-selected-user-and-preserves-scope");
    assert.deepEqual(report.failures, []);
} catch (error) {
    report.failures.push({ name: "user-action-scope", message: error.message });
    throw error;
} finally {
    await writeFile(resolve(output, "report.json"), JSON.stringify(report, null, 2));
    await browser?.close();
    await fixture.close();
}
