import assert from "node:assert/strict";
import { chromium } from "playwright";
import { startFixture } from "./fixture.mjs";

if (process.env.ADMIN_LIVE_URL)
    throw new Error("telegram-management.mjs is fixture-only");

const fixture = await startFixture();
let browser;
try {
    browser = await chromium.launch({
        executablePath: process.env.ADMIN_BROWSER_PATH ?? "/usr/bin/chromium",
        headless: true,
    });
    const context = await browser.newContext({ locale: "zh-CN" });
    const page = await context.newPage();
    const login = await context.request.post(
        fixture.origin + "/api/v1/passport/auth/login",
        { data: { email: "admin@example.test", password: "fixture" } },
    );
    assert.ok(login.ok());
    const token = (await login.json()).data.auth_data;
    const headers = { Authorization: token, Accept: "application/json" };
    const users = [
        { id: 101, email: "telegram-admin@example.test", is_admin: 1, is_staff: 0, telegram_id: "111111111" },
        { id: 102, email: "telegram-staff@example.test", is_admin: 0, is_staff: 1, telegram_id: "222222222" },
        { id: 103, email: "telegram-user@example.test", is_admin: 0, is_staff: 0, telegram_id: "333333333" },
    ];
    for (const user of users) {
        const response = await context.request.post(
            fixture.origin + "/api/v1/test-admin/user/save",
            { headers, data: user },
        );
        assert.ok(response.ok(), await response.text());
    }
    await page.addInitScript(() => {
        localStorage.setItem(
            "v2board-admin-v1:" + location.host + ":test-admin",
            "Bearer fixture-admin",
        );
    });

    const editor = page.locator(".ant-drawer:visible").last();
    const openEditor = async (id) => {
        await page.goto(fixture.origin + "/#/user");
        await page.waitForLoadState("networkidle");
        const row = page.locator(`tr[data-row-key="${id}"]`);
        try {
            await row.waitFor({ timeout: 8000 });
        } catch {
            throw new Error("test user row missing; rows=" + JSON.stringify(await page.locator("tr[data-row-key]").evaluateAll((rows) => rows.map((item) => item.dataset.rowKey))) + "; body=" + (await page.locator("body").innerText()).slice(0, 800) + "; fixtureUsers=" + JSON.stringify(fixture.store.users) + "; calls=" + JSON.stringify(fixture.calls.slice(-8)));
        }
        await row.locator(".row-menu-link").click();
        await page.locator(".ant-dropdown:visible").last()
            .locator(".ant-dropdown-menu-item").first().click();
        await editor.waitFor();
        await editor.getByLabel("Telegram 管理账号 ID", { exact: true }).waitFor();
    };
    const telegramId = () => editor.getByLabel("Telegram 管理账号 ID", { exact: true });
    const save = async () => {
        const saved = page.waitForResponse((response) =>
            response.url().endsWith("/user/update") && response.request().method() === "POST",
        );
        await editor.locator(".ant-drawer-footer .ant-btn-primary").click();
        assert.ok(await saved.then((response) => response.ok()));
        await editor.waitFor({ state: "hidden" });
    };

    for (const id of [101, 102]) {
        await openEditor(id);
        assert.equal(await telegramId().isDisabled(), false);
        assert.equal(await telegramId().inputValue(), "", "legacy telegram_id must not prefill the management ID");
        await telegramId().fill("3456789012345678");
        await save();
        const payload = fixture.calls.filter((call) => call.path === "/user/update").at(-1).data;
        assert.equal(payload.telegram_admin_id, "3456789012345678");
        assert.equal(typeof payload.telegram_admin_id, "string");
        await openEditor(id);
        assert.equal(await telegramId().inputValue(), "3456789012345678", "saved ID must persist when reopened");
        await telegramId().fill("");
        await save();
        assert.equal(fixture.store.users.find((user) => user.id === id).telegram_admin_id, null, "empty ID must unbind");
    }

    await openEditor(103);
    assert.equal(await telegramId().isDisabled(), true);
    const adminSwitch = editor.getByLabel("是否管理员", { exact: true });
    await adminSwitch.click();
    assert.equal(await telegramId().isDisabled(), false, "enabling administrator should enable the field");
    await telegramId().fill("123abc");
    await editor.locator(".ant-drawer-footer .ant-btn-primary").click();
    await editor.locator(".ant-form-item-explain-error").filter({ hasText: "请输入有效的 Telegram 数字用户 ID" }).waitFor();
    assert.equal(fixture.store.users.find((user) => user.id === 103).telegram_admin_id, undefined);

    console.log("PASS telegram management ID fixture regression");
    console.log(JSON.stringify({ cases: ["admin enable/save/reopen/unbind", "staff enable/save/reopen/unbind", "ordinary disabled", "role switch enables", "nondigit rejected", "legacy telegram_id not copied"] }));
} finally {
    await browser?.close();
    await fixture.close();
}
