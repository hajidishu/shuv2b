import assert from "node:assert/strict";
import { mkdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { chromium } from "playwright";
import { encodeQuery } from "../src/domain.mjs";

const origin = process.env.ADMIN_LIVE_URL;
if (origin !== "http://192.168.198.137" || !process.env.ADMIN_LIVE_PASSWORD) throw new Error("Set the authorized VM and test password");
const prefix = "audit-token-" + Date.now(), email = prefix + "@example.test";
const output = resolve(import.meta.dirname, "../test-results/subscription-token-live");
await mkdir(output, { recursive: true });
const report = { host: "192.168.198.137", prefix, email, passed: [], failures: [], cleanup: [], records: [] };
const browser = await chromium.launch({ executablePath: process.env.ADMIN_BROWSER_PATH ?? "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", args: ["--no-proxy-server"] });
const context = await browser.newContext({ serviceWorkers: "block", viewport: { width: 1440, height: 1000 } });
await context.route("**/*", route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
const page = await context.newPage();
let admin, user, userAuth;
const request = (path, data, method = "GET", token = admin) => {
    const url = new URL("/api/v1/" + path, origin);
    if (data && method === "GET") url.search = encodeQuery(data);
    assert.equal(url.origin, origin);
    return context.request.fetch(url.href, { method, maxRedirects: 0, headers: { Accept: "application/json", Authorization: token ?? "" }, ...(method === "POST" ? { data } : {}) });
};
const call = async (...args) => { const r = await request(...args); assert.ok(r.ok(), args[0] + " HTTP " + r.status()); return r.json(); };
const check = async (name, action) => { await action(); report.passed.push(name); console.log("PASS " + name); };
const filter = [{ key: "email", condition: "=", value: email }];
let recordId, originalToken;
try {
    admin = (await call("passport/auth/login", { email: "admin@v2b.test", password: process.env.ADMIN_LIVE_PASSWORD }, "POST", "")).data.auth_data;
    const password = "AuditTokenFixture!2026";
    await call("test-admin/user/generate", { email_prefix: prefix, email_suffix: "example.test", password }, "POST");
    user = (await call("test-admin/user/fetch", { filter })).data.find(u => u.email === email);
    assert.ok(user); report.user_id = user.id;
    await call("test-admin/user/update", { id: user.id, email, banned: 0, is_admin: 0, is_staff: 0, transfer_enable: 1 }, "POST");
    userAuth = (await call("passport/auth/login", { email, password }, "POST", "")).data.auth_data;
    originalToken = user.token;
    const url = new URL("/api/v1/client/subscribe", origin);
    url.search = new URLSearchParams({ token: originalToken, flag: "clash.meta" }).toString();
    const pulled = await context.request.get(url.href, { maxRedirects: 0, headers: {
        "User-Agent": "AuditTokenVerify/1 " + prefix, Authorization: "Bearer fixture-hidden-login", Cookie: "session=fixture-hidden-cookie", "X-Api-Key": "fixture-hidden-key",
    } });
    assert.equal(pulled.status(), 200);
    let listed;
    for (let i = 0; i < 50; i++) {
        listed = (await call("test-admin/subscribe-audit/fetch", { filter })).data;
        if (listed.length) break;
        await new Promise(r => setTimeout(r, 250));
    }
    assert.equal(listed.length, 1); recordId = listed[0].id; report.records.push(recordId);
    await check("list-keeps-http-details-out-of-bulk-response", async () => {
        assert.ok(!JSON.stringify(listed).includes(originalToken));
        assert.ok(!Object.hasOwn(listed[0], "request_details"));
        assert.ok(!Object.hasOwn(listed[0], "current_subscribe_url"));
    });
    await check("detail-keeps-actual-token-and-url-masks-other-credentials", async () => {
        const detail = (await call("test-admin/subscribe-audit/detail", { id: recordId })).data;
        assert.equal(detail.request_details.query.token, originalToken);
        assert.equal(new URL(detail.request_details.url).searchParams.get("token"), originalToken);
        assert.equal(new URL(detail.current_subscribe_url).searchParams.get("token"), originalToken);
        const encoded = JSON.stringify(detail);
        for (const secret of ["fixture-hidden-login", "fixture-hidden-cookie", "fixture-hidden-key"]) assert.ok(!encoded.includes(secret));
    });
    await check("browser-shows-full-token-and-copyable-current-subscription", async () => {
        await page.goto(origin + "/test-admin#/login");
        await page.getByLabel("邮箱", { exact: true }).fill("admin@v2b.test");
        await page.getByLabel("密码", { exact: true }).fill(process.env.ADMIN_LIVE_PASSWORD);
        await page.getByRole("button", { name: "登录", exact: true }).click();
        await page.waitForURL("**#/dashboard");
        await page.evaluate(filters => sessionStorage.setItem("subscription-audit-filters", JSON.stringify(filters)), filter);
        await page.goto(origin + "/test-admin?audit-token=" + Date.now() + "#/subscribe-audit", { waitUntil: "networkidle" });
        await page.locator(".audit-records .ant-table-row").filter({ hasText: email }).getByText("详情", { exact: true }).click();
        const drawer = page.locator(".audit-detail-drawer");
        await drawer.getByText("HTTP 请求", { exact: true }).waitFor();
        assert.ok((await drawer.innerText()).includes(originalToken));
        assert.equal(await drawer.locator(".ant-typography-copy").count(), 1);
        await page.screenshot({ path: resolve(output, "detail.png"), fullPage: true });
    });
    await check("token-reset-updates-current-link-keeps-historical-request", async () => {
        const reset = await call("user/resetSecurity", {}, "GET", userAuth);
        const newToken = new URL(reset.data).searchParams.get("token");
        assert.ok(newToken && newToken !== originalToken);
        const detail = (await call("test-admin/subscribe-audit/detail", { id: recordId })).data;
        assert.equal(new URL(detail.current_subscribe_url).searchParams.get("token"), newToken);
        assert.equal(detail.request_details.query.token, originalToken);
    });
    await check("detail-still-requires-admin", async () => {
        for (const token of ["", userAuth]) assert.equal((await request("test-admin/subscribe-audit/detail", { id: recordId }, "GET", token)).status(), 403);
    });
} catch (error) { report.failures.push({ error: error.message }); process.exitCode = 1; }
finally {
    if (user) {
        try { await call("test-admin/user/delUser", { id: user.id }, "POST"); report.cleanup.push({ kind: "user", id: user.id, pass: true }); }
        catch (error) { report.cleanup.push({ kind: "user", pass: false, error: error.message }); process.exitCode = 1; }
    }
    await browser.close();
    await writeFile(resolve(output, "report.json"), JSON.stringify(report, null, 2));
}
console.log(JSON.stringify({ passed: report.passed.length, failures: report.failures, cleanup: report.cleanup }));
