import assert from "node:assert/strict";
import { chromium } from "playwright";
import { mkdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { startFixture } from "./fixture.mjs";

const fixture = await startFixture({ visual: true });
for (let id = 1; id <= 95; id++)
    fixture.store.logs.push({
        id,
        title: `Synthetic log ${id}`,
        level: id % 2 ? "ERROR" : "INFO",
        host: "https://panel.example.test",
        method: id % 2 ? "POST" : "GET",
        uri:
            id % 2
                ? "/api/v1/server/UniProxy/alive?node_id=1"
                : "/api/v1/user/info",
        ip: id % 3 ? "192.0.2.10" : "2001:db8::10",
        created_at: 1700000000,
    });
const output = resolve(import.meta.dirname, "../test-results/queue-logs");
await mkdir(output, { recursive: true });
const report = { passed: [], failures: [] };
const browser = await chromium.launch({
    executablePath:
        process.env.ADMIN_BROWSER_PATH ??
        "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe",
});
const page = await browser.newPage({
    viewport: { width: 1440, height: 1100 },
    locale: "zh-CN",
});
page.on("pageerror", (error) =>
    report.failures.push({ name: "runtime", message: error.message }),
);
const latest = () =>
    fixture.calls.findLast((call) => call.path === "/system/getSystemLog")
        .query;
const rows = () => page.locator(".system-log-table tbody tr.ant-table-row");
const waitRows = async (count) => {
    await page.waitForFunction(
        (n) =>
            document.querySelectorAll(
                ".system-log-table tbody tr.ant-table-row",
            ).length === n,
        count,
    );
    await page.locator(".ant-spin-spinning").waitFor({ state: "detached" });
};
const select = async (control, value) => {
    await control.locator(".ant-select-selector").click();
    const listId = await control.getByRole("combobox").getAttribute("aria-controls");
    await page
        .locator(".ant-select-dropdown")
        .filter({ has: page.locator(`[id="${listId}"]`) })
        .locator(".ant-select-item-option")
        .filter({ has: page.getByText(value, { exact: true }) })
        .click();
};
const size = async (value) => {
    await select(
        page.locator(".system-log-table .ant-pagination-options .ant-select"),
        `${value} 条/页`,
    );
    await waitRows(Math.min(value, 95));
    assert.equal(latest().page_size, String(value));
    assert.equal(latest().current, "1");
};
const addFilter = async (index, label, value) => {
    await page.getByRole("button", { name: "添加筛选条件" }).click();
    await select(
        page
            .locator(".ant-select")
            .filter({
                has: page.getByLabel(`筛选字段 ${index}`, { exact: true }),
            }),
        label,
    );
    await page.getByLabel(`筛选值 ${index}`, { exact: true }).fill(value);
};
const check = async (name, operation) => {
    try {
        await operation();
        report.passed.push(name);
        console.log("PASS " + name);
    } catch (error) {
        report.failures.push({ name, message: error.message });
        await page.screenshot({
            path: resolve(output, "failure.png"),
            fullPage: true,
        });
        throw error;
    }
};
try {
    await page.goto(fixture.origin + "/#/login");
    await page.getByLabel("邮箱", { exact: true }).fill("admin@example.test");
    await page.getByLabel("密码", { exact: true }).fill("fixture");
    await page.getByRole("button", { name: "登录", exact: true }).click();
    await page.waitForURL("**#/dashboard");
    await page.goto(fixture.origin + "/#/queue");
    await waitRows(20);
    await check("page-size-changes-and-page-navigation", async () => {
        await size(10);
        await page.locator(".system-log-table .ant-pagination-item-2").click();
        await page.waitForFunction(
            () =>
                document.querySelector(
                    ".system-log-table tbody tr.ant-table-row a",
                )?.textContent === "Synthetic log 85",
        );
        assert.equal(latest().current, "2");
        await size(50);
        await page.locator(".system-log-table .ant-pagination-item-2").click();
        await waitRows(45);
        await size(100);
        await size(20);
    });
    await check(
        "path-and-ip-filters-apply-on-server-and-reset-page",
        async () => {
            await page
                .locator(".system-log-table .ant-pagination-item-2")
                .click();
            await page.waitForFunction(
                () =>
                    document.querySelector(
                        ".system-log-table tbody tr.ant-table-row a",
                    )?.textContent === "Synthetic log 75",
            );
            await page.getByRole("button", { name: "日志过滤器" }).click();
            await addFilter(1, "请求地址 / URL Path", "/UniProxy/alive");
            await addFilter(2, "请求 IP", "2001:db8::10");
            await page
                .getByRole("button", { name: "检索", exact: true })
                .click();
            await waitRows(16);
            assert.equal(latest().current, "1");
            assert.equal(latest()["filter[0][condition]"], "like");
            for (const text of await rows().allTextContents()) {
                assert.ok(text.includes("/UniProxy/alive"));
                assert.ok(text.includes("2001:db8::10"));
            }
            await page.getByRole("button", { name: "刷新日志" }).click();
            await waitRows(16);
            assert.equal(latest()["filter[1][value]"], "2001:db8::10");
        },
    );
    await check("filter-reset-and-log-detail-remain-usable", async () => {
        await page.getByRole("button", { name: "日志过滤器" }).click();
        await page.getByRole("button", { name: "重置", exact: true }).click();
        await waitRows(20);
        assert.ok(
            !Object.keys(latest()).some((key) => key.startsWith("filter[")),
        );
        await rows().first().locator("a").click();
        const detail = page
            .locator(".ant-drawer-open")
            .filter({ hasText: "日志详情" });
        await detail.waitFor();
        assert.ok((await detail.textContent()).includes("192.0.2.10"));
        await detail.locator(".ant-drawer-close").click();
    });
    assert.deepEqual(report.failures, []);
} finally {
    await writeFile(
        resolve(output, "report.json"),
        JSON.stringify(report, null, 2),
    );
    await browser.close();
    await fixture.close();
}
