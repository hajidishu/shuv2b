import assert from "node:assert/strict";
import { chromium } from "playwright";
import { mkdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { startFixture } from "./fixture.mjs";

// Live checks only open editors and query lists; no payments or settings are saved.
const live = !!process.env.ADMIN_LIVE_URL;
if (live && !process.env.ADMIN_LIVE_PASSWORD)
    throw new Error("ADMIN_LIVE_PASSWORD is required");
const fixture = live ? null : await startFixture({ visual: true });
const origin = process.env.ADMIN_LIVE_URL ?? fixture.origin;
const entry = live ? "/test-admin" : "/";
const output = resolve(
    import.meta.dirname,
    "../test-results",
    process.env.ADMIN_UI_REPORT_NAME ??
        "filter-payment-ui-" + (live ? "live" : "fixture"),
);
await mkdir(output, { recursive: true });
const report = {
    kind: live ? "live-read-only" : "fixture",
    passed: [],
    failures: [],
    metrics: [],
};
let browser, page;
const check = async (name, action) => {
    try {
        await action();
        report.passed.push(name);
        console.log("PASS " + name);
    } catch (error) {
        report.failures.push({ name, error: error.stack ?? error.message });
        console.log("FAIL " + name + ": " + error.message);
        await page?.screenshot({
            path: resolve(output, "failure-" + name + ".png"),
            fullPage: true,
        });
    }
};
const choose = async (combo, label) => {
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
        .getByText(label, { exact: true })
        .click();
};
const nav = async (route, width = 1440) => {
    await page.setViewportSize({ width, height: 1000 });
    await page.goto(origin + entry + "#" + route);
    await page.reload({ waitUntil: "networkidle" });
    await page.waitForFunction(
        () => !document.querySelector(".ant-spin-spinning"),
    );
};
try {
    browser = await chromium.launch({
        executablePath:
            process.env.ADMIN_BROWSER_PATH ??
            "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe",
    });
    const context = await browser.newContext({
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

    for (const route of ["user", "order"]) {
        for (const width of [2560, 1440, 390]) {
            await check(`${route}-filter-${width}`, async () => {
                await nav("/" + route, width);
                await page
                    .getByRole("button", { name: "过滤器", exact: true })
                    .click();
                const drawer = page.locator(`.${route}-filter-drawer`);
                await drawer.waitFor();
                await page.waitForTimeout(350);
                await drawer
                    .getByRole("button", { name: "添加筛选条件" })
                    .click();
                await drawer
                    .getByRole("textbox", { name: "筛选值 1", exact: true })
                    .fill("example.test");
                await drawer
                    .getByRole("button", { name: "添加筛选条件" })
                    .click();
                await choose(
                    drawer.getByRole("combobox", {
                        name: "筛选字段 2",
                        exact: true,
                    }),
                    route === "user" ? "账号状态" : "订单状态",
                );
                await choose(
                    drawer.getByRole("combobox", {
                        name: "筛选值 2",
                        exact: true,
                    }),
                    route === "user" ? "正常" : "未支付",
                );
                await drawer
                    .getByRole("button", { name: "添加筛选条件" })
                    .click();
                await choose(
                    drawer.getByRole("combobox", {
                        name: "筛选字段 3",
                        exact: true,
                    }),
                    route === "user" ? "到期时间" : "佣金金额",
                );
                const geometry = await drawer.evaluate((node) => {
                    const body = node.querySelector(".ant-drawer-body"),
                        style = getComputedStyle(body);
                    const rect = body.getBoundingClientRect();
                    const left = rect.left + parseFloat(style.paddingLeft),
                        right = rect.right - parseFloat(style.paddingRight);
                    const bounds = (el) => {
                        const r = el.getBoundingClientRect();
                        return { left: r.left, right: r.right, width: r.width };
                    };
                    return {
                        available: { left, right, width: right - left },
                        controls: [
                            ...node.querySelectorAll(
                                ".filter-control > .ant-select, .filter-control > .ant-input, .filter-control > .ant-picker",
                            ),
                        ].map(bounds),
                        add: bounds(
                            node.querySelector('[aria-label="添加筛选条件"]'),
                        ),
                    };
                });
                report.metrics.push({ route, width, ...geometry });
                assert.equal(geometry.controls.length, 9);
                for (const rect of [...geometry.controls, geometry.add]) {
                    assert.ok(
                        Math.abs(rect.width - geometry.available.width) < 2,
                        `Control width ${rect.width.toFixed(1)} should fill ${geometry.available.width.toFixed(1)}px`,
                    );
                    assert.ok(
                        Math.abs(rect.left - geometry.available.left) < 2,
                        "Fields must share the left edge",
                    );
                    assert.ok(
                        rect.right <= geometry.available.right + 1,
                        "Controls must not overflow the drawer",
                    );
                }
                await drawer
                    .getByRole("button", { name: "移除条件 2", exact: true })
                    .click();
                assert.equal(
                    await drawer.locator(".filter-condition").count(),
                    2,
                );
                await drawer
                    .getByRole("button", { name: "添加筛选条件" })
                    .scrollIntoViewIfNeeded();
                await page.screenshot({
                    path: resolve(output, `${route}-${width}.png`),
                    fullPage: true,
                });
                await drawer.locator(".ant-drawer-close").click();
            });
        }
    }

    for (const method of ["Paytaro", "PaytaroQR"]) {
        await check(`${method}-configuration-without-advertising`, async () => {
            await nav("/config/payment");
            await page
                .getByRole("button", { name: "添加支付方式", exact: true })
                .click();
            const modal = page.getByRole("dialog");
            const formResponse = page.waitForResponse(
                (response) =>
                    response.url().includes("/payment/getPaymentForm") &&
                    response.request().postDataJSON()?.payment === method,
            );
            await choose(
                modal.getByRole("combobox", { name: "编辑支付网关" }),
                method,
            );
            const response = await formResponse;
            assert.ok(response.ok());
            const metadata = (await response.json()).data;
            assert.deepEqual(
                Object.keys(metadata).sort(),
                (method === "PaytaroQR"
                    ? ["pid", "key", "method_uuid"]
                    : ["pid", "key"]
                ).sort(),
            );
            await page.waitForFunction(
                () => !document.querySelector(".ant-modal .ant-spin-spinning"),
            );
            await modal.getByLabel("App ID", { exact: true }).fill("test-app");
            await modal
                .getByLabel("App Secret", { exact: true })
                .fill("test-secret");
            if (method === "PaytaroQR")
                await modal
                    .getByLabel("支付方式 UUID", { exact: true })
                    .fill("test-method-uuid");
            await modal.locator(".ant-modal-title").click();
            await page.waitForTimeout(250);
            const text = await modal.innerText();
            assert.doesNotMatch(text, /客服|机器人|官方网站|@paytaro|@smogate/);
            assert.equal(
                await modal
                    .locator(
                        'a[href*="t.me"], a[href*="paytaro.com"], .ant-alert-warning',
                    )
                    .count(),
                0,
            );
            await page.screenshot({
                path: resolve(output, method + ".png"),
                fullPage: true,
            });
            if (!live) {
                await modal
                    .getByLabel("显示名称", { exact: true })
                    .fill(method + " regression");
                const saved = page.waitForResponse((response) =>
                    response.url().endsWith("/payment/save"),
                );
                await modal
                    .getByRole("button", { name: "保存", exact: true })
                    .click();
                const response = await saved;
                assert.ok(response.ok());
                assert.deepEqual(response.request().postDataJSON().config, {
                    pid: "test-app",
                    key: "test-secret",
                    ...(method === "PaytaroQR"
                        ? { method_uuid: "test-method-uuid" }
                        : {}),
                });
            } else
                await modal
                    .getByRole("button", { name: "取消", exact: true })
                    .click();
        });
    }
} catch (error) {
    report.failures.push({
        name: "setup",
        error: error.stack ?? error.message,
    });
} finally {
    await browser?.close();
    await fixture?.close();
    await writeFile(
        resolve(output, "report.json"),
        JSON.stringify(report, null, 2),
    );
}
if (report.failures.length) process.exitCode = 1;
