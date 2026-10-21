import assert from "node:assert/strict";
import { chromium } from "playwright";
import { mkdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { startFixture } from "./fixture.mjs";

const fixture = await startFixture({ visual: true });
const output = resolve(import.meta.dirname, "../test-results/resource-parity");
const reportPath = resolve(
    import.meta.dirname,
    "../test-results/resource-parity.json",
);
await mkdir(output, { recursive: true });
let browser;
let authToken;
const report = {
    kind: "resource-parity",
    startedAt: new Date().toISOString(),
    passed: [],
    failures: [],
};
const callsFor = (path) => fixture.calls.filter((call) => call.path === path);
const settle = () => new Promise((resolve) => setTimeout(resolve, 400));

try {
    fixture.store.groups.push(
        { id: 1, name: "Group One", user_count: 0, server_count: 1 },
        { id: 2, name: "Group Two", user_count: 0, server_count: 1 },
    );
    fixture.store.nodes.push(
        {
            id: 101,
            type: "vmess",
            name: "Node Group One",
            host: "one.example.test",
            port: "443",
            server_port: 443,
            group_id: [1],
            rate: 1,
            show: 1,
            tls: 0,
            network: "tcp",
            available_status: 1,
        },
        {
            id: 102,
            type: "vmess",
            name: "Node Group Two",
            host: "two.example.test",
            port: "443",
            server_port: 443,
            group_id: [2],
            rate: 1,
            show: 1,
            tls: 0,
            network: "tcp",
            available_status: 2,
        },
    );
    fixture.store.plans.push({
        id: 1,
        name: "Parity Plan",
        group_id: 1,
        transfer_enable: 100,
        device_limit: 2,
        speed_limit: 50,
        force_update: 0,
        show: 1,
        renew: 1,
    });
    fixture.store.coupon.push({
        id: 11,
        name: "Money Coupon",
        type: 1,
        value: 1999,
        started_at: 1791993637,
        ended_at: 1794585637,
        show: 1,
    });
    fixture.store.giftcard.push({
        id: 21,
        name: "Money Gift",
        type: 1,
        value: 1999,
        started_at: 1791993637,
        ended_at: 1794585637,
        show: 1,
    });
    fixture.store.ticket.push({
        id: 31,
        subject: "Parity ticket",
        user_id: 7,
        email: "customer@example.test",
        level: 1,
        status: 0,
        reply_status: 0,
        created_at: 1791993637,
        updated_at: 1791993637,
        message: [],
    });
    fixture.store.users.push({
        id: 41,
        email: "resource-parity@example.test",
        plan_id: null,
        group_id: 1,
        balance: 1999,
        commission_balance: 0,
        transfer_enable: 1073741824,
        u: "536870912",
        d: 536870912,
        total_used: 1073741824,
        device_limit: null,
        alive_ip: null,
        ips: "192.0.2.41",
        t: Math.floor(Date.now() / 1000),
        last_login_at: Math.floor(Date.now() / 1000) - 86400 * 4,
        created_at: Math.floor(Date.now() / 1000) - 86400,
        expired_at: null,
        subscribe_url: "https://example.test/subscription/41",
        banned: 0,
        is_admin: 0,
        is_staff: 0,
    });

    browser = await chromium.launch({
        headless: true,
        executablePath:
            process.env.ADMIN_BROWSER_PATH ??
            "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe",
    });
    const context = await browser.newContext({
        viewport: { width: 1440, height: 1000 },
        locale: "zh-CN",
        timezoneId: "Asia/Shanghai",
    });
    await context.addInitScript(() => {
        window.__clipboardWrites = [];
        Object.defineProperty(navigator, "clipboard", {
            configurable: true,
            value: {
                writeText: async (text) => window.__clipboardWrites.push(text),
            },
        });
    });
    const page = await context.newPage();
    page.on("pageerror", (error) => {
        report.failures.push({ name: "pageerror", message: error.message });
    });
    const login = await context.request.post(
        fixture.origin + "/api/v1/passport/auth/login",
        { data: { email: "admin@example.test", password: "fixture-password" } },
    );
    assert.ok(login.ok(), "fixture admin login");
    authToken = (await login.json()).data.auth_data;
    await page.goto(fixture.origin + "/#/login", { waitUntil: "networkidle" });
    await page.getByLabel("邮箱", { exact: true }).fill("admin@example.test");
    await page.getByLabel("密码", { exact: true }).fill("fixture-password");
    await page.getByRole("button", { name: "登录", exact: true }).click();
    await page.waitForURL("**#/dashboard");
    await page.waitForLoadState("networkidle");

    const navigate = async (path) => {
        await page.goto(fixture.origin + "/#" + path, {
            waitUntil: "networkidle",
        });
        await page.reload({ waitUntil: "networkidle" });
        await page.waitForFunction(
            () => !document.querySelector(".ant-spin-spinning"),
        );
        await settle();
    };
    const editor = () =>
        page.locator(".ant-drawer:visible,.ant-modal:visible").last();
    const field = (label) =>
        editor()
            .locator(".ant-form-item")
            .filter({ has: page.getByText(label, { exact: true }) })
            .last();
    const fieldContaining = (label) =>
        editor().locator(".ant-form-item").filter({ hasText: label }).last();
    const input = (label) =>
        editor()
            .getByLabel(label, { exact: true })
            .and(page.locator("input,textarea"))
            .or(field(label).locator("input,textarea"))
            .first();
    const select = async (label, option) => {
        const combo =
            label === "类型"
                ? editor().getByRole("combobox", { name: "类型" })
                : field(label).getByRole("combobox");
        await combo.focus();
        await combo.press("ArrowDown");
        await page
            .locator(".ant-select-dropdown:visible")
            .getByText(option, { exact: true })
            .last()
            .click();
        await editor().locator(".ant-modal-title,.ant-drawer-title").click();
        await settle();
    };
    const fillRange = async () => {
        const start = editor().getByPlaceholder("Start Time", { exact: true }),
            end = editor().getByPlaceholder("End Time", { exact: true });
        await start.fill("2026-10-01 12:34");
        await start.press("Enter");
        await end.fill("2026-11-01 15:45");
        await end.press("Enter");
    };
    const fetchRecords = async (path, id) => {
        const response = await context.request.get(
            fixture.origin +
                "/api/v1/test-admin/" +
                path +
                "/fetch" +
                (id ? "?id=" + id : ""),
            { headers: { Authorization: authToken } },
        );
        assert.ok(response.ok(), path + " fetch returned " + response.status());
        return (await response.json()).data;
    };
    const save = async (path) => {
        const before = callsFor(path).length;
        const response = page.waitForResponse(
            (r) =>
                r.url().includes("/api/v1/test-admin" + path) &&
                r.request().method() === "POST",
        );
        await editor()
            .getByRole("button", { name: /^(提交|保存)$/ })
            .last()
            .click();
        const res = await response;
        assert.ok(res.ok(), path + " returned " + res.status());
        await page
            .locator(".ant-drawer:visible,.ant-modal:visible")
            .last()
            .waitFor({ state: "hidden" });
        assert.equal(
            callsFor(path).length,
            before + 1,
            path + " POST captured",
        );
        return callsFor(path).at(-1).data;
    };
    const rowMenu = async (rowName, item) => {
        const row = page.locator("tr").filter({ hasText: rowName }).last();
        if (item === "编辑" || item === "删除") {
            const inline = row.getByText(item, { exact: true }).last();
            if (await inline.count()) {
                await inline.click();
                await settle();
                return;
            }
        }
        await row.getByText("操作", { exact: true }).click();
        await page
            .locator(".ant-dropdown:visible")
            .getByText(item, { exact: true })
            .click();
        await settle();
    };
    const run = async (name, fn) => {
        try {
            await fn();
            report.passed.push(name);
            await page.screenshot({
                path: resolve(output, name + ".png"),
                fullPage: true,
            });
            console.log("PASS " + name);
        } catch (error) {
            report.failures.push({
                name,
                message: error?.stack ?? String(error),
            });
            try {
                await page.screenshot({
                    path: resolve(output, "failure-" + name + ".png"),
                    fullPage: true,
                });
            } catch {}
            console.log("FAIL " + name + ": " + (error?.message ?? error));
        }
    };

    await run("node-permission-group-filter", async () => {
        await navigate("/server/manage");
        assert.equal(
            await page.getByText("Node Group One", { exact: true }).count(),
            1,
        );
        assert.equal(
            await page.getByText("Node Group Two", { exact: true }).count(),
            1,
        );
        assert.equal(
            await page
                .locator("tr")
                .filter({ hasText: "Node Group One" })
                .locator(".ant-badge-status-warning")
                .count(),
            1,
        );
        assert.equal(
            await page
                .locator("tr")
                .filter({ hasText: "Node Group Two" })
                .locator(".ant-badge-status-processing")
                .count(),
            1,
        );
        await page
            .locator("tr")
            .filter({ hasText: "Node Group One" })
            .getByRole("button", { name: "one.example.test" })
            .click();
        await page.waitForFunction(
            () => window.__clipboardWrites.at(-1) === "one.example.test:443",
        );
        await page
            .getByRole("columnheader", { name: /权限组/ })
            .getByRole("button")
            .click();
        const menu = page.locator(".ant-dropdown:visible").last();
        await menu.getByText("Group One", { exact: true }).click();
        await menu.getByRole("button", { name: "确定" }).click();
        await settle();
        assert.equal(
            await page.getByText("Node Group One", { exact: true }).count(),
            1,
        );
        assert.equal(
            await page.getByText("Node Group Two", { exact: true }).count(),
            0,
        );
        const one = page.locator("tr").filter({ hasText: "Node Group One" });
        assert.equal(await one.locator(".ant-badge-status-warning").count(), 1);
    });

    await run("plan-force-update-edit-only-and-nullable-fields", async () => {
        await navigate("/plan");
        await page
            .getByRole("button", { name: "添加订阅", exact: true })
            .click();
        await settle();
        assert.equal(
            await editor().getByText("强制更新到用户", { exact: true }).count(),
            0,
        );
        await editor()
            .getByRole("button", { name: "取消", exact: true })
            .click();
        await settle();
        await rowMenu("Parity Plan", "编辑");
        const force = editor().getByRole("checkbox", {
            name: "强制更新到用户",
        });
        assert.equal(await force.isChecked(), false);
        await force.click();
        await input("设备限制").fill("");
        await input("速度限制 Mbps").fill("");
        const payload = await save("/plan/save");
        assert.equal(payload.force_update, 1);
        assert.equal(payload.device_limit, null);
        assert.equal(payload.speed_limit, null);
    });

    await run("coupon-money-create-edit", async () => {
        await navigate("/coupon");
        await page
            .getByRole("button", { name: "添加优惠券", exact: true })
            .click();
        await settle();
        await select("类型", "按金额优惠");
        await input("名称").fill("Created money coupon");
        await input("数值").fill("19.99");
        await fillRange();
        const create = await save("/coupon/generate");
        assert.equal(create.type, 1);
        assert.equal(create.value, 1999);
        const coupon = (await fetchRecords("coupon")).find(
            (row) => row.name === "Created money coupon",
        );
        assert.ok(coupon);
        assert.equal(coupon.value, 1999);
        assert.equal(
            coupon.started_at,
            Math.floor(Date.parse("2026-10-01T12:34:00+08:00") / 1000),
        );
        assert.equal(
            coupon.ended_at,
            Math.floor(Date.parse("2026-11-01T15:45:00+08:00") / 1000),
        );
        await rowMenu("Money Coupon", "编辑");
        assert.equal(await input("数值").inputValue(), "19.99");
        assert.equal(
            await editor().getByPlaceholder("Start Time").inputValue(),
            "2026-10-01 12:34",
        );
    });

    await run("giftcard-type-conditional-fields-and-money", async () => {
        await navigate("/giftcard");
        await page
            .getByRole("button", { name: "添加礼品卡", exact: true })
            .click();
        await settle();
        await select("类型", "兑换订阅套餐");
        assert.equal(await field("指定订阅").count(), 1);
        await select("类型", "重置套餐流量");
        assert.equal(await field("指定订阅").count(), 0);
        assert.equal(await input("数值").isDisabled(), true);
        assert.equal(await input("数值").inputValue(), "0");
        await select("类型", "增加账户余额");
        await input("名称").fill("Created money gift");
        await input("数值").fill("19.99");
        await fillRange();
        const create = await save("/giftcard/generate");
        assert.equal(create.type, 1);
        assert.equal(create.value, 1999);
        const gift = (await fetchRecords("giftcard")).find(
            (row) => row.name === "Created money gift",
        );
        assert.ok(gift);
        assert.equal(gift.value, 1999);
        assert.equal(
            gift.started_at,
            Math.floor(Date.parse("2026-10-01T12:34:00+08:00") / 1000),
        );
        assert.equal(
            gift.ended_at,
            Math.floor(Date.parse("2026-11-01T15:45:00+08:00") / 1000),
        );
        await rowMenu("Money Gift", "编辑");
        assert.equal(await input("数值").inputValue(), "19.99");
        assert.equal(
            await editor().getByPlaceholder("Start Time").inputValue(),
            "2026-10-01 12:34",
        );
    });

    await run("route-dns-default-out-validation", async () => {
        await navigate("/server/route");
        await page
            .getByRole("button", { name: "添加路由", exact: true })
            .click();
        await settle();
        await input("备注").fill("DNS parity");
        await select("动作", "指定DNS服务器进行解析");
        assert.equal(await field("DNS服务器").count(), 1);
        await input("匹配值").fill("example.com\ndomain:example.com");
        await input("DNS服务器").fill("1.1.1.1");
        const dns = await save("/server/route/save");
        assert.equal(dns.action, "dns");
        assert.equal(dns.action_value, "1.1.1.1");
        assert.deepEqual(dns.match, ["example.com", "domain:example.com"]);

        await page
            .getByRole("button", { name: "添加路由", exact: true })
            .click();
        await settle();
        await input("备注").fill("Default outbound parity");
        await select("动作", "自定义默认出站");
        assert.equal(await fieldContaining("Xray出站配置").count(), 1);
        assert.equal(
            await editor()
                .locator(".ant-form-item")
                .filter({ hasText: "匹配值" })
                .count(),
            0,
        );
        const routeCallCount = callsFor("/server/route/save").length;
        await fieldContaining("Xray出站配置").locator("textarea").fill("{");
        await editor()
            .getByRole("button", { name: /^(提交|保存)$/ })
            .last()
            .click();
        await settle();
        assert.equal(callsFor("/server/route/save").length, routeCallCount);
        assert.ok(
            await editor()
                .getByText("Xray 出站配置必须是有效的 JSON 对象", {
                    exact: true,
                })
                .count(),
        );
        await fieldContaining("Xray出站配置")
            .locator("textarea")
            .fill('{"protocol":"freedom","settings":{}}');
        const outbound = await save("/server/route/save");
        assert.equal(outbound.action, "default_out");
        assert.deepEqual(JSON.parse(outbound.action_value), {
            protocol: "freedom",
            settings: {},
        });
    });

    await run("tickets-created-at-column", async () => {
        await navigate("/ticket");
        assert.equal(
            await page
                .locator("thead")
                .getByText("创建时间", { exact: true })
                .count(),
            1,
        );
        assert.equal(
            await page.getByText("Parity ticket", { exact: true }).count(),
            1,
        );
    });

    await run("user-table-parity-and-subscription-copy", async () => {
        await navigate("/user");
        const headers = page.locator("thead");
        for (const title of ["权限组", "加入时间"]) {
            assert.equal(
                await headers.getByText(title, { exact: true }).count(),
                1,
            );
        }
        const userRow = page.locator("tr").filter({
            hasText: "resource-parity@example.test",
        });
        assert.equal(
            await userRow.getByText("Group One", { exact: true }).count(),
            1,
        );
        assert.equal(await userRow.getByText("-", { exact: true }).count(), 1);
        assert.equal(
            await userRow.getByText("0 / ∞", { exact: true }).count(),
            1,
        );
        assert.equal(await userRow.locator('[title^="最后在线"]').count(), 1);
        assert.equal(
            await userRow.locator(".ant-badge-status-success").count(),
            1,
        );
        await page.evaluate(() => (window.__clipboardWrites = []));
        await rowMenu("resource-parity@example.test", "复制订阅URL");
        assert.deepEqual(await page.evaluate(() => window.__clipboardWrites), [
            "https://example.test/subscription/41",
        ]);
        assert.equal(callsFor("/user/getUserInfoById").length, 0);
    });
} catch (error) {
    report.failures.push({
        name: "setup",
        message: error?.stack ?? String(error),
    });
    console.error(error);
} finally {
    report.finishedAt = new Date().toISOString();
    report.fixtureCalls = fixture.calls.length;
    await writeFile(reportPath, JSON.stringify(report, null, 2) + "\n", "utf8");
    await browser?.close();
    await fixture.close();
}

if (report.failures.length) process.exitCode = 1;
