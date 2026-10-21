import assert from "node:assert/strict";
import { chromium } from "playwright";
import { mkdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { startFixture } from "./fixture.mjs";

const fixture = await startFixture({
    visual: true,
    settings: {
        logo:
            "data:image/svg+xml," +
            encodeURIComponent(
                '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="40"><rect width="100" height="40" fill="#0665d0"/></svg>',
            ),
    },
});
const out = resolve(import.meta.dirname, "../test-results"),
    screens = resolve(out, "ui-parity-screens");
let browser;
const report = {
    kind: "fixture-ui-parity",
    passed: [],
    failures: [],
    notes: [
        "Uses isolated populated fixture data; configured logo and password recovery guidance are checked without contacting external services.",
    ],
};
let fixtureClosed = false;

const callsFor = (path) => fixture.calls.filter((call) => call.path === path);
const waitForCall = async (path, after = 0, timeout = 6000) => {
    const started = Date.now();
    while (Date.now() - started < timeout) {
        const match = callsFor(path)[after];
        if (match) return match;
        await new Promise((resolve) => setTimeout(resolve, 25));
    }
    throw new Error(`Timed out waiting for ${path}`);
};
const latestOrderFetch = () => callsFor("/order/fetch").at(-1);
const filterFromCall = (call, index = 0) => {
    if (call?.data?.filter) return call.data.filter[index];
    const query = call?.query ?? {};
    return {
        key: query[`filter[${index}][key]`],
        condition: query[`filter[${index}][condition]`],
        value: query[`filter[${index}][value]`],
    };
};
const caseCheck = async (name, fn) => {
    try {
        await fn();
        report.passed.push(name);
        console.log(`PASS ${name}`);
    } catch (error) {
        report.failures.push({ name, message: error?.stack ?? String(error) });
        console.log(`FAIL ${name}: ${error?.message ?? error}`);
        if (browser) {
            try {
                await browser
                    .contexts()[0]
                    ?.pages()[0]
                    ?.screenshot({
                        path: resolve(screens, `failure-${name}.png`),
                        fullPage: true,
                    });
            } catch {}
        }
    }
};

try {
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
        }),
        page = await context.newPage();
    page.on("pageerror", (error) => {
        report.failures.push({
            name: "uncaught-pageerror",
            message: error.message,
        });
    });
    const origin = fixture.origin;
    await mkdir(screens, { recursive: true });
    const navigate = async (path, width = 1440, height = 1000) => {
        await page.setViewportSize({ width, height });
        await page.goto(`${origin}/#${path}`, { waitUntil: "networkidle" });
        // Force a fresh route mount so hash navigation cannot leave assertions on the prior page.
        await page.reload({ waitUntil: "networkidle" });
        await page
            .locator(".admin-content")
            .waitFor({ state: "visible" })
            .catch(() => {});
        await page
            .waitForFunction(
                () =>
                    !document.querySelector(".checking") &&
                    !document.querySelector(
                        ".admin-content .ant-spin-spinning",
                    ),
                null,
                { timeout: 10000 },
            )
            .catch(() => {});
        await page.mouse.move(width - 2, height - 2);
    };
    const screenshot = async (name, fullPage = true) =>
        page.screenshot({ path: resolve(screens, `${name}.png`), fullPage });
    const waitStableModal = async (modal) => {
        await modal.waitFor({ state: "visible" });
        await page.waitForFunction(
            (selector) => {
                const el = document.querySelector(selector);
                if (!el) return false;
                return Number.parseFloat(getComputedStyle(el).opacity) >= 0.99;
            },
            ".ant-modal-wrap",
            { timeout: 5000 },
        );
        await page.waitForTimeout(350);
    };
    const chooseSelect = async (combo, option) => {
        await combo.click();
        const dropdown = page.locator(".ant-select-dropdown:visible").last();
        await dropdown.waitFor({ state: "visible" });
        // Wait for popup placement/motion before clicking; a stale popup rectangle
        // can otherwise send the click to the button underneath on Linux Chromium.
        await dropdown.evaluate(async (element) => {
            await Promise.all(
                element.getAnimations({ subtree: true }).map((animation) =>
                    animation.finished.catch(() => {}),
                ),
            );
        });
        await dropdown.getByText(option, { exact: true }).last().click();
        await dropdown.waitFor({ state: "hidden" });
    };
    const orderFilters = () => page.locator(".order-filter-drawer");
    const openOrderFilters = async () => {
        await page.getByRole("button", { name: "过滤器", exact: true }).click();
        await orderFilters().waitFor({ state: "visible" });
    };
    const addOrderFilter = async (keyLabel, condition, value) => {
        const drawer = orderFilters();
        await drawer.getByRole("button", { name: "添加筛选条件" }).click();
        await chooseSelect(
            drawer.getByRole("combobox", { name: "筛选字段 1" }),
            keyLabel,
        );
        const conditionBox = drawer.getByRole("combobox", {
            name: "比较条件 1",
        });
        if (conditionBox) await chooseSelect(conditionBox, condition);
        const valueSelect = drawer.getByRole("combobox", {
            name: "筛选值 1",
        });
        if ((await valueSelect.count()) > 0)
            await chooseSelect(valueSelect, value);
        else
            await drawer.getByRole("textbox", { name: "筛选值 1" }).fill(value);
    };
    const applyOrderFilter = async () => {
        const before = callsFor("/order/fetch").length;
        await orderFilters().getByRole("button", { name: "筛选订单" }).click();
        await orderFilters().waitFor({ state: "hidden" });
        await page.waitForLoadState("networkidle");
        return latestOrderFetch();
    };
    const openUserRowMenu = async () => {
        const row = page
            .locator(".ant-table-row")
            .filter({ hasText: "demo@example.test" })
            .first();
        await row.getByText("操作", { exact: false }).click();
        return page.locator(".ant-dropdown:visible");
    };

    await caseCheck("login-recovery-modal", async () => {
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.goto(`${origin}/#/login`, { waitUntil: "networkidle" });
        assert.ok(
            await page
                .locator(".login-logo")
                .evaluate((img) => img.complete && img.naturalWidth === 100),
        );
        await page
            .getByRole("button", { name: "忘记密码", exact: true })
            .click();
        const modal = page.getByRole("dialog", {
            name: "忘记密码",
            exact: true,
        });
        await waitStableModal(modal);
        await assert.doesNotReject(async () => {
            await modal
                .getByText("php artisan reset:password 管理员邮箱", {
                    exact: true,
                })
                .waitFor();
        });
        await screenshot("login-recovery");
        await modal.locator(".ant-modal-close").click();
        await modal.waitFor({ state: "hidden" });
        await page
            .getByLabel("邮箱", { exact: true })
            .fill("admin@example.test");
        await page.getByLabel("密码", { exact: true }).fill("fixture-password");
        await page.getByRole("button", { name: "登录", exact: true }).click();
        await page.waitForURL("**#/dashboard");
    });

    await caseCheck("dashboard-desktop-chart-parity", async () => {
        await navigate("/dashboard", 2560, 1400);
        await page.getByText("在线人数", { exact: true }).waitFor();
        const stats = await page
            .locator(".overview-stat .ant-statistic-content-value")
            .evaluateAll((els) =>
                els.map((el) => getComputedStyle(el).fontSize),
            );
        assert.equal(stats.length, 3);
        assert.ok(
            stats.every((size) => size === "56px"),
            JSON.stringify(stats),
        );
        const chart = page.locator(".overview-chart .echarts-view");
        await chart.locator("svg").waitFor({ state: "visible" });
        const drawing = await chart.evaluate((host) => {
            const svg = host.querySelector("svg"),
                hostRect = host.getBoundingClientRect(),
                svgRect = svg.getBoundingClientRect(),
                paths = [...svg.querySelectorAll("path")];
            return {
                hostWidth: hostRect.width,
                svgWidth: svgRect.width,
                curves: paths.some((path) =>
                    /[CcSs]/.test(path.getAttribute("d") ?? ""),
                ),
                colors: [
                    ...new Set(
                        paths.flatMap((path) => [
                            path.getAttribute("stroke"),
                            path.getAttribute("fill"),
                        ]),
                    ),
                ],
            };
        });
        assert.ok(
            Math.abs(drawing.hostWidth - drawing.svgWidth) < 2,
            JSON.stringify(drawing),
        );
        assert.ok(
            drawing.curves,
            "line paths should contain cubic/smooth SVG curves",
        );
        for (const color of [
            "#5470c6",
            "#91cc75",
            "#fac858",
            "#ee6666",
            "#73c0de",
        ])
            assert.ok(
                drawing.colors.some((value) => value?.toLowerCase() === color),
                `missing chart color ${color}`,
            );
        await screenshot("dashboard-desktop");
    });

    await caseCheck("dashboard-mobile-no-overflow", async () => {
        await navigate("/dashboard", 390, 844);
        await page.locator(".overview-chart svg").waitFor({ state: "visible" });
        const size = await page.evaluate(() => ({
            inner: window.innerWidth,
            document: document.documentElement.scrollWidth,
            body: document.body.scrollWidth,
        }));
        assert.ok(
            size.document <= size.inner && size.body <= size.inner,
            JSON.stringify(size),
        );
        await screenshot("dashboard-mobile");
    });

    await caseCheck(
        "orders-table-commission-actions-and-assignment",
        async () => {
            await navigate("/order");
            assert.equal(
                await page
                    .locator(".orders-table .ant-table-thead")
                    .first()
                    .locator("th")
                    .count(),
                9,
            );
            const rows = page.locator(".orders-table .ant-table-row");
            const beforeUpdate = callsFor("/order/update").length;
            const pendingCommission = rows
                .filter({ hasText: "待确认" })
                .first();
            await pendingCommission.locator(".order-mark-link").click();
            await page
                .locator(".ant-dropdown:visible")
                .getByText("无效", { exact: true })
                .click();
            const update = await waitForCall("/order/update", beforeUpdate);
            assert.equal(update.data.commission_status, 3);

            const issued = rows.filter({ hasText: "已发放" }).first();
            assert.ok(
                (await issued.count()) > 0,
                "fixture should include an issued commission",
            );
            assert.equal(
                await issued
                    .locator("td")
                    .nth(7)
                    .locator(".order-mark-link")
                    .count(),
                0,
            );

            const add = page.getByRole("button", {
                name: "分配订单",
                exact: true,
            });
            await add.click();
            const assign = page.getByRole("dialog", {
                name: "订单分配",
                exact: true,
            });
            await waitStableModal(assign);
            await assign.getByLabel("用户邮箱", { exact: true }).waitFor();
            await assign
                .getByRole("combobox", { name: "订阅计划", exact: true })
                .waitFor();
            await assign
                .getByRole("combobox", { name: "支付周期", exact: true })
                .waitFor();
            const amount = assign.getByLabel("分配金额", { exact: true });
            await amount.waitFor();
            const currency = assign
                .locator(".ant-input-group-addon")
                .filter({ hasText: "¥" });
            assert.equal(await currency.count(), 1);
            const [amountBox, currencyBox] = await Promise.all([
                amount.boundingBox(),
                currency.boundingBox(),
            ]);
            assert.ok(
                amountBox && currencyBox && currencyBox.x > amountBox.x,
                "¥ addon should appear on the right",
            );
            await screenshot("order-assignment");
            await assign.locator(".ant-modal-close").click();
            await assign.waitFor({ state: "hidden" });
        },
    );

    await caseCheck("order-detail-and-email-user-filter", async () => {
        await navigate("/order");
        const beforeDetail = callsFor("/order/detail").length;
        await page
            .locator(".orders-table .ant-table-row")
            .first()
            .locator("td")
            .first()
            .getByRole("link")
            .click();
        const detail = page.getByRole("dialog", {
            name: "订单信息",
            exact: true,
        });
        await waitStableModal(detail);
        await waitForCall("/order/detail", beforeDetail);
        const detailCall = callsFor("/order/detail").at(-1);
        assert.equal(detailCall.data.id, -1);
        const userInfo = await waitForCall("/user/getUserInfoById");
        assert.equal(userInfo.query.id, "-1");
        await detail
            .getByRole("link", { name: "demo@example.test", exact: true })
            .click();
        await page.waitForURL("**#/user");
        const userFetch = await waitForCall("/user/fetch");
        const filter = filterFromCall(userFetch);
        assert.equal(filter.key, "email");
        assert.equal(filter.condition, "=");
        assert.equal(filter.value, "demo@example.test");
        await screenshot("order-detail-user-filter");
    });

    await caseCheck("order-commission-filter-idempotent-cents", async () => {
        await navigate("/order");
        await openOrderFilters();
        await addOrderFilter("佣金金额", "=", "1.23");
        const first = await applyOrderFilter();
        assert.deepEqual(filterFromCall(first), {
            key: "commission_balance",
            condition: "=",
            value: "123",
        });
        await openOrderFilters();
        assert.equal(
            await orderFilters()
                .getByRole("textbox", { name: "筛选值 1" })
                .inputValue(),
            "1.23",
        );
        const second = await applyOrderFilter();
        assert.deepEqual(filterFromCall(second), {
            key: "commission_balance",
            condition: "=",
            value: "123",
        });
        await screenshot("order-commission-filter");
    });

    await caseCheck("order-type-filter-option", async () => {
        await navigate("/order");
        await openOrderFilters();
        await addOrderFilter("订单类型", "=", "新购");
        const call = await applyOrderFilter();
        assert.deepEqual(filterFromCall(call), {
            key: "type",
            condition: "=",
            value: "1",
        });
        await screenshot("order-type-filter");
    });

    await caseCheck(
        "user-order-assignment-shortcut-prefills-email",
        async () => {
            await navigate("/user");
            const menu = await openUserRowMenu();
            await menu.getByText("分配订单", { exact: true }).click();
            await page.waitForURL("**#/order");
            const assign = page.getByRole("dialog", {
                name: "订单分配",
                exact: true,
            });
            await waitStableModal(assign);
            assert.equal(
                await assign
                    .getByLabel("用户邮箱", { exact: true })
                    .inputValue(),
                "demo@example.test",
            );
            await screenshot("user-order-assignment-shortcut");
            await assign.locator(".ant-modal-close").click();
        },
    );

    await caseCheck("user-orders-shortcut-filter", async () => {
        await navigate("/user");
        const menu = await openUserRowMenu(),
            before = callsFor("/order/fetch").length;
        await menu.getByText("TA的订单", { exact: true }).click();
        await page.waitForURL("**#/order");
        const call = await waitForCall("/order/fetch", before);
        assert.deepEqual(filterFromCall(call), {
            key: "user_id",
            condition: "=",
            value: "-1",
        });
        await screenshot("user-orders-shortcut");
    });

    await caseCheck("user-invites-shortcut-filter", async () => {
        await navigate("/user");
        const menu = await openUserRowMenu(),
            before = callsFor("/user/fetch").length;
        await menu.getByText("TA的邀请", { exact: true }).click();
        const call = await waitForCall("/user/fetch", before);
        assert.deepEqual(filterFromCall(call), {
            key: "invite_user_id",
            condition: "=",
            value: "-1",
        });
        await screenshot("user-invites-shortcut");
    });

    await caseCheck("user-traffic-shortcut", async () => {
        await navigate("/user");
        const menu = await openUserRowMenu(),
            before = callsFor("/stat/getStatUser").length;
        await menu.getByText("TA的流量记录", { exact: true }).click();
        await page
            .getByRole("tab", { name: "流量记录", exact: true })
            .waitFor();
        const call = await waitForCall("/stat/getStatUser", before);
        assert.equal(call.query.user_id, "-1");
        await screenshot("user-traffic-shortcut");
    });

    await caseCheck(
        "queue-polls-every-three-seconds-and-stops-on-navigation",
        async () => {
            await navigate("/queue");
            await page
                .getByRole("heading", { name: "队列监控", exact: true })
                .waitFor({ state: "attached" });
            await screenshot("queue-polling");
            const endpoints = [
                "/system/getQueueStats",
                "/system/getQueueWorkload",
                "/system/getSystemStatus",
                "/system/getQueueMasters",
            ];
            const initial = Object.fromEntries(
                endpoints.map((path) => [path, callsFor(path).length]),
            );
            await page.waitForTimeout(3400);
            for (const path of endpoints)
                assert.ok(
                    callsFor(path).length >= initial[path] + 1,
                    `${path} did not poll within 3s`,
                );
            await navigate("/dashboard");
            const afterLeaving = Object.fromEntries(
                endpoints.map((path) => [path, callsFor(path).length]),
            );
            await page.waitForTimeout(3400);
            assert.deepEqual(
                Object.fromEntries(
                    endpoints.map((path) => [path, callsFor(path).length]),
                ),
                afterLeaving,
                "queue polling should stop after leaving the queue page",
            );
        },
    );
} finally {
    try {
        if (browser) await browser.close();
    } finally {
        if (!fixtureClosed) {
            fixtureClosed = true;
            await fixture.close();
        }
        await mkdir(out, { recursive: true });
        await writeFile(
            resolve(out, "ui-parity.json"),
            JSON.stringify(report, null, 2) + "\n",
            "utf8",
        );
    }
}

if (report.failures.length) process.exitCode = 1;
