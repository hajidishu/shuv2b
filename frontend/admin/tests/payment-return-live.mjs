import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { mkdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { chromium } from "playwright";
import { encodeQuery } from "../src/domain.mjs";

const origin = process.env.ADMIN_LIVE_URL;
if (origin !== "http://192.168.198.137" || !process.env.ADMIN_LIVE_PASSWORD)
    throw new Error("This integration test requires the authorized VM and its test password");
const prefix = "return-url-" + Date.now();
const output = resolve(import.meta.dirname, "../test-results/payment-return-live");
await mkdir(output, { recursive: true });
const report = { host: "192.168.198.137", prefix, passed: [], failures: [], cleanup: [], users: [], gateways: [] };
const browser = await chromium.launch({ executablePath: process.env.ADMIN_BROWSER_PATH ?? "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", args: ["--no-proxy-server"] });
const context = await browser.newContext({ serviceWorkers: "block", viewport: { width: 1440, height: 1000 } });
await context.route("**/*", route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
const page = await context.newPage();
let admin, original, account, gateway, tradeNo;
const request = async (path, data, token = admin, method = "POST", extraHeaders = {}) => {
    const url = new URL("/api/v1/" + path, origin);
    if (data && method === "GET") url.search = encodeQuery(data);
    assert.equal(url.origin, origin);
    return context.request.fetch(url.href, { method, maxRedirects: 0,
        headers: { Accept: "application/json", Authorization: token ?? "", Origin: origin, ...extraHeaders },
        ...(method === "POST" ? { data } : {}) });
};
const call = async (...args) => {
    const result = await request(...args);
    assert.ok(result.ok(), args[0] + " HTTP " + result.status());
    return result.json();
};
const check = async (name, action) => {
    await action(); report.passed.push(name); console.log("PASS " + name);
};
const save = values => call("test-admin/config/save", values);
const checkout = async (headerOrigin = origin) => call("user/order/checkout", { trade_no: tradeNo, method: gateway.id }, account.auth, "POST", { Origin: headerOrigin });
const query = result => new URL(result.data).searchParams;
try {
    admin = (await call("passport/auth/login", { email: "admin@v2b.test", password: process.env.ADMIN_LIVE_PASSWORD }, "")).data.auth_data;
    const site = (await call("test-admin/config/fetch", null, admin, "GET")).data.site;
    original = Object.fromEntries(["payment_return_url", "payment_return_path", "payment_return_origins"].map(key => [key, site[key] ?? null]));
    await page.goto(origin + "/test-admin#/login", { waitUntil: "networkidle" });
    await page.getByLabel("邮箱", { exact: true }).fill("admin@v2b.test");
    await page.getByLabel("密码", { exact: true }).fill(process.env.ADMIN_LIVE_PASSWORD);
    await page.getByRole("button", { name: "登录", exact: true }).click();
    await page.waitForURL("**#/dashboard");
    await page.goto(origin + "/test-admin#/config/system", { waitUntil: "networkidle" });
    await page.reload({ waitUntil: "networkidle" });
    await check("settings-controls-visible", async () => {
        await page.locator("#payment_return_url").waitFor();
        assert.equal(await page.locator("#payment_return_path").inputValue(), original.payment_return_path);
        assert.equal(await page.locator("#payment_return_origins").count(), 1);
    });
    const frontend = origin + "/customer";
    const template = "/#/payment?trade_no={trade_no}";
    await check("settings-autosave-and-reload", async () => {
        await page.locator("#payment_return_url").fill(frontend);
        await page.locator("#payment_return_path").fill(template);
        const saved = await page.waitForResponse(r => r.url().endsWith("/config/save") && r.request().method() === "POST");
        assert.equal(saved.status(), 200);
        await page.reload({ waitUntil: "networkidle" });
        assert.equal(await page.locator("#payment_return_url").inputValue(), frontend);
        assert.equal(await page.locator("#payment_return_path").inputValue(), template);
        await page.screenshot({ path: resolve(output, "settings.png"), fullPage: true });
    });
    await page.close(); // Flush UI activity before subsequent API configuration changes.
    await check("invalid-config-rejected-without-overwriting-settings", async () => {
        for (const data of [{ payment_return_url: "javascript:alert(1)" }, { payment_return_path: "//unapproved.example.test/{trade_no}" }, { payment_return_origins: ["https://*.example.test"] }]) {
            assert.equal((await request("test-admin/config/save", data)).status(), 422);
        }
        const saved = (await call("test-admin/config/fetch", null, admin, "GET")).data.site;
        assert.equal(saved.payment_return_url, frontend); assert.equal(saved.payment_return_path, template);
    });
    const email = prefix + "@example.test", password = "ReturnFixture!2026";
    await call("test-admin/user/generate", { email_prefix: prefix, email_suffix: "example.test", password });
    account = (await call("test-admin/user/fetch", { filter: [{ key: "email", condition: "=", value: email }] }, admin, "GET")).data.find(u => u.email === email);
    assert.ok(account); report.users.push({ id: account.id, email });
    account.auth = (await call("passport/auth/login", { email, password }, "")).data.auth_data;
    const config = { pid: "fixture-return", key: "fixture-key-" + prefix, url: "https://gateway.example.test", type: "alipay" };
    await call("test-admin/payment/save", { name: prefix, payment: "EPay", config, handling_fee_fixed: 0, handling_fee_percent: null });
    gateway = (await call("test-admin/payment/fetch", null, admin, "GET")).data.find(p => p.name === prefix);
    assert.ok(gateway); report.gateways.push({ id: gateway.id, name: gateway.name });
    await call("test-admin/payment/show", { id: gateway.id });
    tradeNo = (await call("user/order/save", { plan_id: 0, period: "deposit", deposit_amount: 10000 }, account.auth)).data;
    let first;
    await check("ez-theme-subdirectory-and-original-order-id", async () => {
        first = await checkout();
        assert.equal(query(first).get("return_url"), frontend + "/#/payment?trade_no=" + tradeNo);
        assert.notEqual(query(first).get("out_trade_no"), tradeNo);
        const notify = new URL(query(first).get("notify_url"));
        assert.equal(notify.origin, origin); assert.ok(notify.pathname.startsWith("/api/v1/guest/payment/notify/"));
    });
    await check("same-destination-reuses-checkout", async () => assert.deepEqual(await checkout(), first));
    await check("route-change-invalidates-checkout", async () => {
        await save({ payment_return_path: "/#/order/{trade_no}" });
        const next = await checkout();
        assert.notEqual(query(next).get("out_trade_no"), query(first).get("out_trade_no"));
        assert.equal(query(next).get("return_url"), frontend + "/#/order/" + tradeNo);
    });
    await check("allowed-origin-switch-and-unapproved-origin-fallback", async () => {
        const alternate = origin + ":8443";
        await save({ payment_return_origins: [alternate] });
        const allowed = await checkout(alternate), denied = await checkout("https://unapproved.example.test");
        assert.equal(query(allowed).get("return_url"), alternate + "/#/order/" + tradeNo);
        assert.equal(query(denied).get("return_url"), frontend + "/#/order/" + tradeNo);
        assert.notEqual(query(allowed).get("out_trade_no"), query(denied).get("out_trade_no"));
    });
    await check("old-checkout-notification-still-settles-once", async () => {
        const params = { pid: config.pid, trade_status: "TRADE_SUCCESS", out_trade_no: query(first).get("out_trade_no"), trade_no: prefix + "-receipt", money: query(first).get("money") };
        const encoded = new URLSearchParams(Object.entries(params).sort(([a], [b]) => a.localeCompare(b)));
        params.sign = createHash("md5").update(decodeURIComponent(encoded.toString().replace(/\+/g, " ")).replace(/\\/g, "") + config.key).digest("hex");
        params.sign_type = "MD5";
        const notify = new URL(query(first).get("notify_url"));
        assert.equal(notify.origin, origin);
        for (let i = 0; i < 2; i++) {
            const result = await context.request.post(notify.href, { data: params, maxRedirects: 0 });
            assert.equal(result.status(), 200); assert.equal(await result.text(), "success");
        }
        for (let i = 0; i < 50; i++) {
            const detail = (await call("user/order/detail", { trade_no: tradeNo }, account.auth, "GET")).data;
            if (Number(detail.status) === 3) break;
            if (i === 49) assert.fail("Queue did not complete");
            await new Promise(r => setTimeout(r, 250));
        }
        const user = (await call("test-admin/user/getUserInfoById", { id: account.id }, admin, "GET")).data;
        assert.equal(Number(user.balance), 10000);
    });
} catch (error) {
    report.failures.push({ error: error.message }); process.exitCode = 1;
} finally {
    if (!page.isClosed()) await page.close();
    for (const user of report.users) {
        try { await call("test-admin/user/delUser", { id: user.id }); report.cleanup.push({ kind: "user", id: user.id, pass: true }); }
        catch (error) { report.cleanup.push({ kind: "user", id: user.id, pass: false, error: error.message }); process.exitCode = 1; }
    }
    if (original) {
        try {
            await save(original);
            const current = (await call("test-admin/config/fetch", null, admin, "GET")).data.site;
            for (const [key, value] of Object.entries(original)) assert.deepEqual(current[key], value);
            report.cleanup.push({ kind: "original-settings", pass: true });
        } catch (error) { report.cleanup.push({ kind: "original-settings", pass: false, error: error.message }); process.exitCode = 1; }
    }
    await browser.close();
    await writeFile(resolve(output, "report.json"), JSON.stringify(report, null, 2));
}
console.log(JSON.stringify({ passed: report.passed.length, failures: report.failures, cleanup: report.cleanup }));
