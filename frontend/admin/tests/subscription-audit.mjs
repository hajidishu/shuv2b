import assert from "node:assert/strict";
import { chromium } from "playwright";
import { mkdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { startFixture } from "./fixture.mjs";
import { encodeQuery } from "../src/domain.mjs";

const live = !!process.env.ADMIN_LIVE_URL;
if (live && !process.env.ADMIN_LIVE_PASSWORD)
    throw new Error("ADMIN_LIVE_PASSWORD is required");
const fixture = live ? null : await startFixture({ visual: true });
const origin = process.env.ADMIN_LIVE_URL ?? fixture.origin,
    entry = live ? "/test-admin" : "/";
const out = resolve(
    import.meta.dirname,
    "../test-results/subscription-audit-" + (live ? "live" : "fixture"),
);
await mkdir(out, { recursive: true });
const prefix = "audit-regression-" + Date.now(),
    email = prefix + "@example.com",
    ua = "Clash.Meta/1.18 " + prefix;
const report = {
    kind: live ? "live" : "fixture",
    passed: [],
    failures: [],
    cleanup: [],
};
let browser, context, page, token, user, originalSettings;
const api = async (path, data, method = "GET", authorization = token) => {
    const response = await context.request.fetch(
        origin +
            "/api/v1/test-admin" +
            path +
            (method === "GET" && data ? "?" + encodeQuery(data) : ""),
        {
            method,
            headers: {
                Authorization: authorization ?? "",
                Accept: "application/json",
            },
            ...(method === "POST" ? { data } : {}),
        },
    );
    assert.ok(response.ok(), path + " returned " + response.status());
    return response.json();
};
const check = async (name, fn) => {
    try {
        await fn();
        report.passed.push(name);
        console.log("PASS " + name);
    } catch (error) {
        report.failures.push({ name, error: error.stack ?? error.message });
        await page?.screenshot({
            path: resolve(out, "failure-" + name + ".png"),
            fullPage: true,
        });
        throw error;
    }
};
const choose = async (combo, text) => {
    await combo
        .locator(
            "xpath=ancestor::div[contains(concat(' ',normalize-space(@class),' '),' ant-select ')][1]",
        )
        .locator(".ant-select-selector")
        .click();
    const id = await combo.getAttribute("aria-controls");
    await page
        .locator(
            `.ant-select-dropdown:has(#${id}) .ant-select-item-option-content`,
        )
        .getByText(text, { exact: true })
        .click();
};
const nav = async () => {
    await page.goto(origin + entry + "#/subscribe-audit");
    await page.reload({ waitUntil: "networkidle" });
};
const settleDetails = async () => {
    await page.waitForFunction(() => {
        const panel = document.querySelector(".audit-detail-drawer");
        const wrapper =
            panel?.closest(".ant-drawer-content-wrapper") ??
            panel?.querySelector(".ant-drawer-content-wrapper");
        const rect = wrapper?.getBoundingClientRect();
        return (
            rect &&
            Math.abs(rect.right - innerWidth) < 1 &&
            rect.left >= -1 &&
            Math.abs(rect.width - Math.min(800, innerWidth)) < 2
        );
    });
};
const closeDetails = async () => {
    await page.locator(".audit-detail-drawer .ant-drawer-close").click();
    await page.locator(".audit-detail-drawer").waitFor({ state: "hidden" });
    await page.mouse.move(200, 200);
};
try {
    browser = await chromium.launch({
        executablePath:
            process.env.ADMIN_BROWSER_PATH ??
            "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe",
    });
    context = await browser.newContext({
        viewport: { width: 1440, height: 1000 },
        locale: "zh-CN",
    });
    page = await context.newPage();
    page.on("pageerror", (error) =>
        report.failures.push({ name: "pageerror", error: error.message }),
    );
    await page.goto(origin + entry + "#/login");
    await page
        .getByLabel("邮箱", { exact: true })
        .fill(live ? "admin@v2b.test" : "admin@example.test");
    await page
        .getByLabel("密码", { exact: true })
        .fill(process.env.ADMIN_LIVE_PASSWORD ?? "fixture");
    await page.getByRole("button", { name: "登录", exact: true }).click();
    await page.waitForURL("**#/dashboard");
    token = (
        await (
            await context.request.post(origin + "/api/v1/passport/auth/login", {
                data: {
                    email: live ? "admin@v2b.test" : "admin@example.test",
                    password: process.env.ADMIN_LIVE_PASSWORD ?? "fixture",
                },
            })
        ).json()
    ).data.auth_data;
    if (live)
        await check("audit-and-business-queues-have-consumers", async () => {
            const queues = (await api("/system/getQueueWorkload")).data;
            for (const name of [
                "order_handle",
                "traffic_fetch",
                "stat",
                "send_email",
                "send_email_mass",
                "send_telegram",
                "subscription_audit",
            ]) {
                assert.ok(
                    queues.some(
                        (queue) =>
                            queue.name === name && Number(queue.processes) >= 1,
                    ),
                    name + " must have a consumer",
                );
            }
        });
    if (live)
        await check(
            "actual-subscriptions-are-audited-asynchronously",
            async () => {
                await api(
                    "/user/generate",
                    {
                        email_prefix: prefix,
                        email_suffix: "example.com",
                        password: "AuditRegression!2026",
                        expired_at: Math.floor(Date.now() / 1000) + 86400,
                    },
                    "POST",
                );
                user = (
                    await api("/user/fetch", {
                        filter: [
                            { key: "email", condition: "=", value: email },
                        ],
                    })
                ).data.find((row) => row.email === email);
                assert.ok(
                    user?.token,
                    "Own test user's subscription token is required",
                );
                await api(
                    "/user/update",
                    {
                        id: user.id,
                        email: user.email,
                        is_admin: 0,
                        is_staff: 0,
                        transfer_enable: 1,
                        banned: 0,
                    },
                    "POST",
                );
                for (let i = 0; i < 2; i++) {
                    const response = await context.request.get(
                        origin +
                            "/api/v1/client/subscribe?" +
                            new URLSearchParams({
                                token: user.token,
                                flag: "clash.meta",
                                api_key: "sensitive-" + prefix,
                            }),
                        {
                            headers: {
                                "User-Agent": ua + "-" + i,
                                Authorization: "Bearer sensitive-" + prefix,
                                Cookie: "session=sensitive-" + prefix,
                            },
                        },
                    );
                    assert.ok(response.ok(), "Subscription response failed");
                }
                await api(
                    "/user/update",
                    {
                        id: user.id,
                        email: user.email,
                        is_admin: 0,
                        is_staff: 0,
                        banned: 1,
                    },
                    "POST",
                );
                const denied = await context.request.get(
                    origin +
                        "/api/v1/client/subscribe?" +
                        new URLSearchParams({ token: user.token }),
                    { headers: { "User-Agent": ua + "-denied" } },
                );
                assert.ok(denied.ok());
                let events = [];
                for (let i = 0; i < 40; i++) {
                    events = (
                        await api("/subscribe-audit/fetch", {
                            filter: [
                                { key: "email", condition: "=", value: email },
                            ],
                        })
                    ).data;
                    if (events.length >= 3) break;
                    await page.waitForTimeout(500);
                }
                assert.equal(events.length, 3);
                assert.equal(
                    events.filter((event) => event.result === "success").length,
                    2,
                );
                assert.equal(
                    events.filter((event) => event.result === "unavailable")
                        .length,
                    1,
                );
                for (const event of events) {
                    assert.ok(event.ip);
                    assert.ok(event.ua.startsWith(ua));
                    assert.ok(event.requested_at > 0);
                    assert.ok(!JSON.stringify(event).includes(user.token));
                }
                const noToken = await context.request.get(
                    origin + "/api/v1/client/subscribe",
                );
                assert.equal(noToken.status(), 403);
            },
        );
    await check("audit-menu-position-and-record-columns", async () => {
        await nav();
        const labels = await page.locator(".ant-menu-item").allTextContents();
        const knowledge = labels.findIndex(
            (text) => text.trim() === "知识库管理",
        );
        assert.ok(knowledge >= 0);
        assert.equal(labels[knowledge + 1].trim(), "订阅审计");
        const headers = await page
            .locator(".audit-records .ant-table-thead th")
            .allTextContents();
        assert.deepEqual(headers, [
            "拉取时间",
            "用户邮箱",
            "IP地址",
            "客户端 UA",
            "订阅格式",
            "结果",
            "HTTP状态",
            "操作",
        ]);
        assert.ok(await page.locator(".audit-records .ant-table-row").count());
        await page.screenshot({
            path: resolve(out, "audit-desktop.png"),
            fullPage: true,
        });
    });
    await check(
        "record-details-show-email-and-actual-subscription-token",
        async () => {
            const recordRow = live
                ? page
                      .locator(".audit-records .ant-table-row")
                      .filter({ hasText: email })
                      .first()
                : page.locator('.audit-records tr[data-row-key="1"]');
            await recordRow.getByText("详情", { exact: true }).click();
            const drawer = page.locator(".audit-detail-drawer");
            await drawer.getByText("HTTP 请求", { exact: true }).waitFor();
            await settleDetails();
            const text = await drawer.innerText();
            assert.ok(text.includes(live ? email : "audit-alpha@example.test"));
            assert.ok(text.includes("[redacted]"));
            assert.ok(!text.includes("用户ID"));
            assert.ok(!text.includes("sensitive-" + prefix));
            assert.ok(text.includes(live ? user.token : "audit-fixture-token"));
            assert.ok(text.includes("/api/v1/client/subscribe"));
            assert.ok(text.includes("GET"));
            assert.equal(await drawer.locator("img").count(), 0);
            assert.equal(await page.evaluate(() => window.auditXss), undefined);
            await page.screenshot({
                path: resolve(out, "audit-http-detail.png"),
                fullPage: true,
            });
            await closeDetails();
            if (!live) {
                await page
                    .locator('.audit-records tr[data-row-key="2"]')
                    .getByText("详情", { exact: true })
                    .click();
                await drawer.getByText(/此记录未保存 HTTP 请求详情/).waitFor();
                await closeDetails();
            }
        },
    );
    await check(
        "statistics-switch-between-ip-ua-and-user-with-email-labels",
        async () => {
            for (const [label, heading] of [
                ["IP排行", "IP地址"],
                ["UA排行", "客户端 UA"],
                ["用户排行", "用户邮箱"],
            ]) {
                await page
                    .locator(".audit-stats-heading .ant-segmented-item-label")
                    .getByText(label, { exact: true })
                    .click();
                await page.waitForLoadState("networkidle");
                assert.ok(
                    (
                        await page
                            .locator(".audit-ranking th")
                            .allTextContents()
                    ).includes(heading),
                );
                const first = page
                    .locator(".audit-ranking .ant-table-row")
                    .first();
                assert.ok(await first.count());
                if (label === "用户排行")
                    assert.match(await first.innerText(), /@/);
                if (!live) {
                    assert.ok((await first.innerText()).includes("13"));
                    assert.equal(
                        await page
                            .locator(
                                ".audit-statistics .ant-statistic-content-value",
                            )
                            .first()
                            .innerText(),
                        "25",
                    );
                }
            }
            await page.screenshot({
                path: resolve(out, "audit-user-ranking.png"),
                fullPage: true,
            });
        },
    );
    await check("email-ua-ip-filters-combine-and-reset", async () => {
        await page.getByRole("button", { name: "过滤器", exact: true }).click();
        const drawer = page.locator(".subscription-audit-filter-drawer");
        for (const [index, field, value] of [
            [1, "用户邮箱", live ? email : "audit-alpha"],
            [2, "客户端 UA", live ? prefix : "audit-client"],
            [3, "IP地址", live ? "" : "203.0.113.10"],
        ]) {
            if (live && index === 3) continue;
            await drawer.getByRole("button", { name: "添加筛选条件" }).click();
            await choose(
                drawer.getByRole("combobox", {
                    name: `筛选字段 ${index}`,
                    exact: true,
                }),
                field,
            );
            await drawer
                .getByRole("textbox", { name: `筛选值 ${index}`, exact: true })
                .fill(value);
        }
        const fetched = page.waitForResponse(
            (response) =>
                response.url().includes("/subscribe-audit/fetch") &&
                response.url().includes("filter"),
        );
        await drawer.getByRole("button", { name: "检索", exact: true }).click();
        const result = await (await fetched).json();
        assert.equal(result.total, live ? 3 : 13);
        await page.waitForLoadState("networkidle");
        assert.equal(
            await page
                .locator(".audit-statistics .ant-statistic-content-value")
                .first()
                .innerText(),
            String(live ? 3 : 13),
        );
        assert.ok(
            result.data.every((row) =>
                row.email.includes(live ? email : "audit-alpha"),
            ),
        );
        if (live) {
            const match = (
                await api("/subscribe-audit/fetch", {
                    filter: [{ key: "email", condition: "=", value: email }],
                })
            ).data[0];
            const ipMatch = await api("/subscribe-audit/fetch", {
                filter: [
                    { key: "email", condition: "=", value: email },
                    { key: "ip", condition: "=", value: match.ip },
                ],
            });
            assert.equal(ipMatch.total, 3);
        }
        await page.getByRole("button", { name: /过滤器/ }).click();
        await drawer.getByRole("button", { name: "重置", exact: true }).click();
        await page.waitForLoadState("networkidle");
        if (!live) {
            assert.equal(
                await page.locator(".audit-records .ant-table-row").count(),
                20,
            );
            await page.locator(".ant-pagination-item-2").click();
            await page.waitForLoadState("networkidle");
            assert.equal(
                await page.locator(".audit-records .ant-table-row").count(),
                5,
            );
        }
    });
    await check(
        "retention-settings-persist-and-default-to-thirty-days",
        async () => {
            originalSettings = (await api("/subscribe-audit/settings")).data;
            assert.equal(originalSettings.retention_days, 30);
            assert.equal(originalSettings.max_records, 1000000);
            await page
                .getByRole("button", { name: "保留设置", exact: true })
                .click();
            const modal = page.getByRole("dialog");
            await modal.getByLabel("保留天数", { exact: true }).fill("31");
            await modal
                .getByLabel("最大记录条数", { exact: true })
                .fill("1000001");
            const saved = page.waitForResponse(
                (response) =>
                    response.url().endsWith("/subscribe-audit/settings") &&
                    response.request().method() === "POST",
            );
            await modal
                .getByRole("button", { name: "保存", exact: true })
                .click();
            assert.ok((await saved).ok());
            assert.deepEqual((await api("/subscribe-audit/settings")).data, {
                retention_days: 31,
                max_records: 1000001,
            });
            await api("/subscribe-audit/settings", originalSettings, "POST");
            originalSettings = null;
        },
    );
    await check("mobile-table-scrolls-without-page-overflow", async () => {
        await page.setViewportSize({ width: 390, height: 844 });
        await nav();
        assert.ok(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth + 1,
            ),
        );
        assert.ok(
            await page
                .locator(".audit-records .ant-table-content")
                .evaluate((node) => node.scrollWidth > node.clientWidth),
        );
        await page.screenshot({
            path: resolve(out, "audit-mobile.png"),
            fullPage: true,
        });
        await page
            .locator(".audit-records .ant-table-row")
            .first()
            .getByText("详情", { exact: true })
            .click();
        const mobileDetail = page.locator(".audit-detail-drawer");
        await mobileDetail.getByText("基本信息", { exact: true }).waitFor();
        await settleDetails();
        const bounds = await mobileDetail
            .locator(".ant-drawer-body")
            .boundingBox();
        assert.ok(bounds.width <= 391, JSON.stringify(bounds));
        assert.ok(
            await mobileDetail
                .locator(".ant-drawer-body")
                .evaluate((node) => node.scrollWidth <= node.clientWidth + 1),
        );
        await page.screenshot({
            path: resolve(out, "audit-http-mobile.png"),
            fullPage: true,
        });
        await closeDetails();
    });
    if (live)
        await check(
            "audit-api-rejects-non-admin-and-invalid-queries",
            async () => {
                const unauthorized = await context.request.get(
                    origin + "/api/v1/test-admin/subscribe-audit/fetch",
                );
                assert.equal(unauthorized.status(), 403);
                await api(
                    "/user/update",
                    {
                        id: user.id,
                        email: user.email,
                        is_admin: 0,
                        is_staff: 0,
                        banned: 0,
                    },
                    "POST",
                );
                const ordinaryLogin = await context.request.post(
                    origin + "/api/v1/passport/auth/login",
                    {
                        data: { email, password: "AuditRegression!2026" },
                    },
                );
                assert.ok(ordinaryLogin.ok());
                const ordinaryToken = (await ordinaryLogin.json()).data
                    .auth_data;
                const forbidden = await context.request.get(
                    origin + "/api/v1/test-admin/subscribe-audit/fetch",
                    {
                        headers: { Authorization: ordinaryToken },
                    },
                );
                assert.equal(forbidden.status(), 403);
                for (const endpoint of ["stats", "detail?id=1"]) {
                    const protectedResponse = await context.request.get(
                        origin +
                            "/api/v1/test-admin/subscribe-audit/" +
                            endpoint,
                        { headers: { Authorization: ordinaryToken } },
                    );
                    assert.equal(protectedResponse.status(), 403);
                }
                const bad = await context.request.get(
                    origin +
                        "/api/v1/test-admin/subscribe-audit/fetch?pageSize=100000",
                    { headers: { Authorization: token } },
                );
                assert.equal(bad.status(), 422);
                const invalid = await context.request.post(
                    origin + "/api/v1/test-admin/subscribe-audit/settings",
                    {
                        headers: { Authorization: token },
                        data: { retention_days: 0, max_records: -1 },
                    },
                );
                assert.equal(invalid.status(), 422);
            },
        );
} catch (error) {
    if (!report.failures.length)
        report.failures.push({
            name: "setup",
            error: error.stack ?? error.message,
        });
} finally {
    if (originalSettings)
        try {
            await api("/subscribe-audit/settings", originalSettings, "POST");
        } catch (error) {
            report.cleanup.push({ settings: false, error: error.message });
        }
    if (user)
        try {
            await api("/user/delUser", { id: user.id }, "POST");
            report.cleanup.push({ user: user.id, deleted: true });
        } catch (error) {
            report.cleanup.push({
                user: user.id,
                deleted: false,
                error: error.message,
            });
        }
    await browser?.close();
    await fixture?.close();
    await writeFile(
        resolve(out, "report.json"),
        JSON.stringify(report, null, 2),
    );
}
if (
    report.failures.length ||
    report.cleanup.some(
        (item) => item.deleted === false || item.settings === false,
    )
)
    process.exitCode = 1;
