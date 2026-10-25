import assert from "node:assert/strict";
import { mkdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { chromium } from "playwright";
import { liveTarget } from "./live-target.mjs";

const origin = liveTarget();
const output = resolve(import.meta.dirname, "../test-results/telegram-webhook");
await mkdir(output, { recursive: true });
const browser = await chromium.launch({ executablePath: process.env.ADMIN_BROWSER_PATH || undefined, args: ["--no-proxy-server"] });
const context = await browser.newContext({ serviceWorkers: "block", viewport: { width: 1440, height: 1000 } });
await context.route("**/*", route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
const page = await context.newPage();
const report = { host: new URL(origin).host, passed: [], failures: [], cleanup: [] };
let admin, original;
const request = async (path, data, method = "POST") => {
    const url = new URL("/api/v1/" + path, origin); assert.equal(url.origin, origin);
    return context.request.fetch(url.href, { method, maxRedirects: 0, headers: { Accept: "application/json", Authorization: admin ?? "" }, ...(method === "POST" ? { data } : {}) });
};
const call = async (...args) => { const r = await request(...args); assert.ok(r.ok(), args[0] + " HTTP " + r.status()); return r.json(); };
const check = async (name, action) => { await action(); report.passed.push(name); console.log("PASS " + name); };
try {
    admin = (await call("passport/auth/login", { email: "admin@v2b.test", password: process.env.ADMIN_LIVE_PASSWORD })).data.auth_data;
    original = (await call("test-admin/config/fetch", null, "GET")).data.telegram;
    await check("missing-domain-rejected-before-telegram-request", async () => {
        await call("test-admin/config/save", { telegram_webhook_domain: null });
        const r = await request("test-admin/config/setTelegramWebhook", {});
        assert.equal(r.status(), 422);
        assert.ok((await r.json()).message.includes("Webhook 域名"));
    });
    await check("http-and-path-values-rejected", async () => {
        for (const domain of ["http://bot.example.test", "https://bot.example.test/path"]) {
            assert.equal((await request("test-admin/config/save", { telegram_webhook_domain: domain })).status(), 422);
        }
    });
    await page.goto(origin + "/test-admin#/login");
    await page.getByLabel("邮箱", { exact: true }).fill("admin@v2b.test");
    await page.getByLabel("密码", { exact: true }).fill(process.env.ADMIN_LIVE_PASSWORD);
    await page.getByRole("button", { name: "登录", exact: true }).click();
    await page.waitForURL("**#/dashboard");
    await page.goto(origin + "/test-admin#/config/system", { waitUntil: "networkidle" });
    await page.reload({ waitUntil: "networkidle" });
    await page.waitForTimeout(500);
    await page.getByRole("tab", { name: "Telegram", exact: true }).click();
    await check("https-domain-autosaves-and-survives-reload", async () => {
        await page.locator(".settings-fields #telegram_webhook_domain").fill("https://bot.example.test");
        const saved = await page.waitForResponse(r => r.url().endsWith("/config/save") && r.request().method() === "POST");
        assert.equal(saved.status(), 200);
        await page.getByText("已保存", { exact: true }).waitFor();
        await page.reload({ waitUntil: "networkidle" });
        await page.waitForTimeout(500);
        await page.getByRole("tab", { name: "Telegram", exact: true }).click();
        assert.equal(await page.locator(".settings-fields #telegram_webhook_domain").inputValue(), "https://bot.example.test");
        await page.screenshot({ path: resolve(output, "live-settings.png"), fullPage: true });
    });
    await check("other-telegram-settings-retained", async () => {
        const current = (await call("test-admin/config/fetch", null, "GET")).data.telegram;
        for (const key of Object.keys(original).filter(k => k !== "telegram_webhook_domain")) assert.ok(JSON.stringify(current[key]) === JSON.stringify(original[key]), "Another Telegram setting changed");
    });
} catch (error) { report.failures.push({ message: error.message }); process.exitCode = 1; }
finally {
    await page.close();
    if (original) {
        try {
            await call("test-admin/config/save", { telegram_webhook_domain: original.telegram_webhook_domain ?? null });
            const current = (await call("test-admin/config/fetch", null, "GET")).data.telegram;
            assert.ok((current.telegram_webhook_domain ?? null) === (original.telegram_webhook_domain ?? null), "Domain restore mismatch");
            report.cleanup.push({ kind: "original-domain", pass: true });
        } catch (error) { report.cleanup.push({ kind: "original-domain", pass: false, error: error.message }); process.exitCode = 1; }
    }
    await browser.close();
    await writeFile(resolve(output, "live-report.json"), JSON.stringify(report, null, 2));
}
console.log(JSON.stringify(report));
