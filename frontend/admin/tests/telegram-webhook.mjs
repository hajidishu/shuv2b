import assert from "node:assert/strict";
import { mkdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { chromium } from "playwright";
import { startFixture } from "./fixture.mjs";

const fixture = await startFixture();
const browser = await chromium.launch({ executablePath: process.env.ADMIN_BROWSER_PATH ?? "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe" });
const context = await browser.newContext({ serviceWorkers: "block", viewport: { width: 1440, height: 1000 } });
await context.route("**/*", route => new URL(route.request().url()).origin === fixture.origin ? route.continue() : route.abort());
const page = await context.newPage();
const report = { kind: "fixture", passed: [], failures: [] };
const check = async (name, action) => { await action(); report.passed.push(name); console.log("PASS " + name); };
try {
    await page.goto(fixture.origin + "/#/login");
    await page.getByLabel("邮箱", { exact: true }).fill("admin@example.test");
    await page.getByLabel("密码", { exact: true }).fill("fixture");
    await page.getByRole("button", { name: "登录", exact: true }).click();
    await page.waitForURL("**#/dashboard");
    await page.goto(fixture.origin + "/#/config/system", { waitUntil: "networkidle" });
    await page.reload({ waitUntil: "networkidle" });
    await page.waitForTimeout(500);
    await page.getByRole("tab", { name: "Telegram", exact: true }).click();
    await page.locator("#telegram_webhook_domain").waitFor({ timeout: 5000 });
    await check("domain-is-editable-before-registration", async () => {
        await page.locator("#telegram_webhook_domain").fill("https://saved.example.test");
        await page.locator("#telegram_bot_token").fill("fixture-bot-token");
        await page.getByText("已保存", { exact: true }).waitFor();
        assert.ok(fixture.calls.some(c => c.path === "/config/save" && c.data.telegram_webhook_domain === "https://saved.example.test"));
        assert.ok(!fixture.calls.some(c => c.path === "/config/setTelegramWebhook"));
    });
    await page.getByRole("button", { name: "一键设置", exact: true }).click();
    const dialog = page.getByRole("dialog", { name: "设置 Telegram Webhook", exact: true });
    await dialog.waitFor();
    await check("registration-prefills-saved-domain-and-requires-it", async () => {
        const input = dialog.locator("#telegram_webhook_domain");
        assert.equal(await input.inputValue(), "https://saved.example.test");
        await input.fill("");
        await dialog.getByRole("button", { name: "保存", exact: true }).click();
        await dialog.locator(".ant-form-item-explain-error").waitFor();
        assert.ok(!fixture.calls.some(c => c.path === "/config/setTelegramWebhook"));
    });
    await check("registration-persists-edited-domain-before-registering", async () => {
        await dialog.locator("#telegram_webhook_domain").fill("https://updated.example.test");
        await dialog.getByRole("button", { name: "保存", exact: true }).click();
        await dialog.waitFor({ state: "hidden" });
        const registration = fixture.calls.findIndex(c => c.path === "/config/setTelegramWebhook");
        assert.ok(registration > 0);
        assert.ok(fixture.calls.slice(0, registration).some(c => c.path === "/config/save" && c.data.telegram_webhook_domain === "https://updated.example.test"));
        assert.deepEqual(fixture.calls[registration].data, {});
        assert.equal(await page.locator(".settings-fields #telegram_webhook_domain").inputValue(), "https://updated.example.test");
    });
} catch (error) {
    report.failures.push({ message: error.message });
    report.inputs = await page.locator("input").evaluateAll(nodes => nodes.map(n => ({ id: n.id, type: n.type })));
    report.tabs = await page.getByRole("tab").evaluateAll(nodes => nodes.map(n => ({ text: n.textContent, selected: n.getAttribute("aria-selected") })));
    process.exitCode = 1;
} finally {
    await browser.close(); await fixture.close();
    const output = resolve(import.meta.dirname, "../test-results/telegram-webhook");
    await mkdir(output, { recursive: true });
    await writeFile(resolve(output, "fixture-report.json"), JSON.stringify(report, null, 2));
    console.log(JSON.stringify(report));
}
