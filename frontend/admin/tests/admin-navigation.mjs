import assert from "node:assert/strict";
import { chromium } from "playwright";
import { mkdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { startFixture } from "./fixture.mjs";
import { encodeQuery } from "../src/domain.mjs";

const output = resolve(import.meta.dirname, "../test-results/admin-navigation");
await mkdir(output, { recursive: true });
const report = { kind: "admin-navigation", startedAt: new Date().toISOString(), passed: [], failures: [] };
let fixture, browser, context, page, token;
const apiCalls = () => fixture.calls;
const hash = (route, values = {}) => {
    const query = new URLSearchParams();
    for (const [key, value] of Object.entries(values)) query.set(key, String(value));
    return "#" + route + (query.size ? "?" + query.toString() : "");
};
const queryFromHref = (href) => new URLSearchParams(href.split("?")[1] ?? "");
const gotoHash = async (targetPage, targetHash) => {
    await targetPage.goto(fixture.origin + "/" + targetHash);
    await targetPage.reload({ waitUntil: "networkidle" });
    await targetPage.locator(".ant-spin-spinning").waitFor({ state: "detached" });
    await targetPage.mouse.move(260, 60);
    await targetPage.locator(".ant-dropdown:visible").waitFor({ state: "hidden" });
    await targetPage.locator(".ant-popover:visible").waitFor({ state: "hidden" });
    await targetPage.locator(".ant-tooltip:visible").waitFor({ state: "hidden" });
};
const filterCall = (path) => apiCalls().findLast((call) => call.path === path);
const assertFilterCall = (path, key, value) => {
    const call = filterCall(path);
    const indexes = call
        ? Object.keys(call.query)
              .map((name) => name.match(/^filter\[(\d+)\]\[key\]$/)?.[1])
              .filter((index) => index !== undefined)
        : [];
    const filters = indexes.map((index) => ({
        key: call.query[`filter[${index}][key]`],
        condition: call.query[`filter[${index}][condition]`],
        value: call.query[`filter[${index}][value]`],
    }));
    assert.ok(
        filters.some((item) => item.key === key && item.value === String(value)),
        `${path}'s latest request should filter ${key}=${value}; latest: ${JSON.stringify(call)}, recent: ${JSON.stringify(apiCalls().filter((item) => item.path === path).slice(-3))}`,
    );
    return call;
};
const fixtureApi = async (path, data = {}, method = "GET") => {
    const query = method === "GET" && Object.keys(data).length ? "?" + encodeQuery(data).toString() : "";
    const response = await context.request.fetch(fixture.origin + "/api/v1/test-admin" + path + query, {
        method,
        headers: { Authorization: token, Accept: "application/json" },
        ...(method === "POST" ? { data } : {}),
    });
    assert.ok(response.ok(), `${path} returned HTTP ${response.status()}`);
    return response.json();
};
const check = async (name, run) => {
    try {
        await run();
        report.passed.push(name);
        console.log("PASS " + name);
    } catch (error) {
        report.failures.push({ name, message: error?.message ?? String(error), stack: error?.stack ?? null });
        if (page) {
            try { await page.screenshot({ path: resolve(output, "failure.png"), fullPage: true }); }
            catch (screenshotError) { report.failures.at(-1).screenshotError = screenshotError.message; }
        }
        console.error("FAIL " + name + ": " + (error?.message ?? error));
        throw error;
    }
};

try {
    fixture = await startFixture({ visual: true });
    assert.ok(new URL(fixture.origin).hostname === "127.0.0.1", "fixture must bind only to local loopback");
    const stamp = Date.now();
    const ordinaryEmail = `nav-user-${stamp}@example.test`;
    const specialEmail = `nav+東京&tag-${stamp}@example.test`;
    const tradeNo = `NAV/订单+${stamp}`;
    const userId = 701, specialUserId = 702, planId = 711, groupId = 811, secondGroupId = 812;
    const now = Math.floor(Date.now() / 1000);
    fixture.store.groups.push(
        { id: groupId, name: `Nav group ${stamp}`, user_count: 1, server_count: 1 },
        { id: secondGroupId, name: `Other group ${stamp}`, user_count: 0, server_count: 0 },
    );
    fixture.store.plans.push(
        { id: planId, name: `Nav plan ${stamp}`, group_id: groupId, transfer_enable: 100, device_limit: 2, speed_limit: 10, month_price: 1000, show: 1, renew: 1 },
        { id: planId + 1, name: `Other plan ${stamp}`, group_id: groupId, transfer_enable: 100, device_limit: 2, speed_limit: 10, month_price: 2000, show: 1, renew: 1 },
    );
    const user = (id, email, group) => ({
        id, email, invite_user_id: userId,
        plan_id: planId, plan_name: `Nav plan ${stamp}`, group_id: group,
        balance: 1234, commission_balance: 0, transfer_enable: 100 * 1024 ** 3,
        u: 10, d: 20, total_used: 30, device_limit: 2, alive_ip: 1,
        ips: "192.0.2.11", last_login_at: now, created_at: now - 60,
        expired_at: now + 86400, banned: 0, is_admin: 0, is_staff: 0,
        subscribe_url: `https://subscription.example.test/${id}`, token: `token-${id}`,
    });
    fixture.store.users.push(user(userId, ordinaryEmail, groupId), user(specialUserId, specialEmail, secondGroupId));
    fixture.store.orders.push({
        id: 901, user_id: userId, email: ordinaryEmail, user_email: ordinaryEmail,
        trade_no: tradeNo, plan_id: planId, plan_name: `Nav plan ${stamp}`,
        type: 1, period: "month_price", total_amount: 1000, status: 1,
        created_at: now - 30, paid_at: now - 20, updated_at: now - 20,
    });
    fixture.store.nodes.push(
        { id: 911, type: "vmess", name: `Nav VMess ${stamp}`, host: "vmess.example.test", port: "443", server_port: 443, group_id: [groupId], show: 1, rate: 1, tls: 0, network: "tcp" },
        { id: 911, type: "trojan", name: `Nav Trojan ${stamp}`, host: "trojan.example.test", port: "443", server_port: 443, group_id: [groupId], show: 1, rate: 1 },
        { id: 912, type: "vmess", name: `Nav VMess spare ${stamp}`, host: "spare.example.test", port: "443", server_port: 443, group_id: [groupId], show: 1, rate: 1, tls: 0, network: "tcp" },
    );
    fixture.store["subscribe-audit"].splice(0, fixture.store["subscribe-audit"].length,
        { id: 9901, user_id: null, email: specialEmail, ip: "203.0.113.91", ua: `Special ${stamp}`, format: "ClashMeta", result: "success", status: 200, requested_at: now - 5, request_details: { method: "GET", protocol: "HTTP/1.1", url: "https://panel.example.test/sub", scheme: "https", host: "panel.example.test", path: "/sub", remote_ip: "203.0.113.91", duration_ms: 7, query: {}, headers: {} } },
        { id: 9902, user_id: userId, email: ordinaryEmail, ip: "203.0.113.92", ua: `Ordinary ${stamp}`, format: "Singbox", result: "success", status: 200, requested_at: now - 4, request_details: { method: "GET", protocol: "HTTP/1.1", url: "https://panel.example.test/sub", scheme: "https", host: "panel.example.test", path: "/sub", remote_ip: "203.0.113.92", duration_ms: 8, query: {}, headers: {} } },
    );

    browser = await chromium.launch({ executablePath: process.env.ADMIN_BROWSER_PATH ?? "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe" });
    context = await browser.newContext({ viewport: { width: 1360, height: 1000 }, locale: "zh-CN" });
    page = await context.newPage();
    page.on("pageerror", (error) => report.failures.push({ name: "pageerror", message: error.message, stack: error.stack }));
    await page.goto(fixture.origin + "/#/login");
    await page.getByLabel("邮箱", { exact: true }).fill("admin@example.test");
    await page.getByLabel("密码", { exact: true }).fill("fixture");
    await page.getByRole("button", { name: "登录", exact: true }).click();
    await page.waitForURL("**#/dashboard");
    const login = await context.request.post(fixture.origin + "/api/v1/passport/auth/login", { data: { email: "admin@example.test", password: "fixture" } });
    assert.ok(login.ok(), "fixture admin API login should succeed");
    token = (await login.json()).data.auth_data;
    await fixtureApi("/leak-probe/nodes", { action: "register", node_type: "vmess", node_id: 911 }, "POST");
    await fixtureApi("/leak-probe/nodes", { action: "register", node_type: "vmess", node_id: 912 }, "POST");
    const createdLeak = await fixtureApi("/leak-probe/create", {
        name: `Navigation task ${stamp}`, scope: "emails", emails: `${ordinaryEmail}\n${specialEmail}`,
        observation_hours: 24, a_node_id: 1, b_node_id: 2, branch: "a",
    }, "POST");
    const leakTaskId = createdLeak.data.id;
    assert.ok(leakTaskId, "leak fixture setup should return an owned task id");

    await check("sidebar scrolls with hidden scrollbar and reaches queue item", async () => {
        await page.setViewportSize({ width: 1360, height: 470 });
        const sidebar = page.locator(".admin-sidebar");
        await sidebar.waitFor({ state: "visible" });
        const style = await sidebar.evaluate((el) => ({
            overflowY: getComputedStyle(el).overflowY,
            scrollbarWidth: getComputedStyle(el).scrollbarWidth,
            webkitScrollbarWidth: getComputedStyle(el, "::-webkit-scrollbar").width,
            scrollHeight: el.scrollHeight,
            clientHeight: el.clientHeight,
        }));
        assert.equal(style.overflowY, "auto");
        assert.equal(style.scrollbarWidth, "none");
        assert.ok(style.webkitScrollbarWidth === "0px" || style.webkitScrollbarWidth === "0");
        assert.ok(style.scrollHeight > style.clientHeight, "sidebar content should exceed the short viewport");
        await page.mouse.move(120, 430);
        await page.mouse.wheel(0, 1400);
        await page.waitForFunction(() => document.querySelector(".admin-sidebar")?.scrollTop > 0);
        const queue = page.getByRole("menuitem", { name: "队列监控" });
        await queue.waitFor({ state: "visible" });
        await queue.click();
        await page.waitForURL("**#/queue");
        await page.locator(".admin-header").getByText("队列监控", { exact: true }).waitFor({ state: "visible" });
        await page.setViewportSize({ width: 1360, height: 1000 });
    });

    await check("audit list and detail links preserve special email and exact user ID", async () => {
        const auditSpecial = hash("/subscribe-audit", { email: specialEmail });
        await page.evaluate(() => sessionStorage.setItem("subscription-audit-filters", JSON.stringify([{ key: "email", condition: "=", value: "stale@example.test" }])));
        await gotoHash(page, auditSpecial);
        const initialCall = assertFilterCall("/subscribe-audit/fetch", "email", specialEmail);
        assert.equal(initialCall.query["filter[0][condition]"], "=");
        assert.equal(await page.evaluate(() => sessionStorage.getItem("subscription-audit-filters")), null, "legacy stored filters should be consumed once");
        const specialRow = page.locator(".audit-records tr.ant-table-row").filter({ hasText: specialEmail });
        await specialRow.waitFor({ state: "visible" });
        const specialUserLink = specialRow.locator("a").filter({ hasText: specialEmail });
        const specialHref = await specialUserLink.getAttribute("href");
        assert.ok(specialHref.startsWith("#/user?email="));
        const specialParams = queryFromHref(specialHref);
        assert.equal(specialParams.get("email"), specialEmail);
        assert.ok(specialHref.includes("%2B") && specialHref.includes("%26") && specialHref.includes("%E6"), "plus, ampersand, and Unicode must be encoded in the link");
        await Promise.all([
            page.waitForResponse((response) => new URL(response.url()).pathname.endsWith("/user/fetch")),
            page.waitForURL((url) => url.hash === specialHref),
            specialUserLink.click(),
        ]);
        assertFilterCall("/user/fetch", "email", specialEmail);

        await page.goBack();
        await page.waitForURL((url) => url.hash === auditSpecial);
        const detailRow = page.locator(".audit-records tr.ant-table-row").filter({ hasText: specialEmail });
        await Promise.all([
            page.waitForResponse((response) => new URL(response.url()).pathname.endsWith("/subscribe-audit/detail")),
            detailRow.getByText("详情", { exact: true }).click(),
        ]);
        const detailDrawer = page.locator(".audit-detail-drawer");
        await detailDrawer.waitFor({ state: "visible" });
        assert.equal(await detailDrawer.locator(`a[href="${specialHref}"]`).count(), 1, "audit detail should link the idless row by exact email");
        await detailDrawer.locator(".ant-drawer-close").click();
        await detailDrawer.waitFor({ state: "hidden" });

        await gotoHash(page, hash("/subscribe-audit", { user_id: userId }));
        const ordinaryRow = page.locator(".audit-records tr.ant-table-row").filter({ hasText: ordinaryEmail });
        await ordinaryRow.waitFor({ state: "visible" });
        const idHref = hash("/user", { id: userId });
        assert.equal(await ordinaryRow.locator(`a[href="${idHref}"]`).count(), 1, "audit list should link a known user by exact ID");
        await Promise.all([
            page.waitForResponse((response) => new URL(response.url()).pathname.endsWith("/subscribe-audit/detail")),
            ordinaryRow.getByText("详情", { exact: true }).click(),
        ]);
        const ordinaryDrawer = page.locator(".audit-detail-drawer");
        await ordinaryDrawer.waitFor({ state: "visible" });
        assert.equal(await ordinaryDrawer.locator(`a[href="${idHref}"]`).count(), 1, "audit detail should link a known user by exact ID");
        await ordinaryDrawer.locator(".ant-drawer-close").click();
        await ordinaryDrawer.waitFor({ state: "hidden" });
    });

    await check("audit user ranking links by exact ID and linked filter", async () => {
        const auditUser = hash("/subscribe-audit", { user_id: userId });
        await gotoHash(page, auditUser);
        assertFilterCall("/subscribe-audit/fetch", "user_id", userId);
        await Promise.all([
            page.waitForResponse((response) => {
                const url = new URL(response.url());
                return url.pathname.endsWith("/subscribe-audit/stats") && url.searchParams.get("dimension") === "user";
            }),
            (async () => {
                const rankingMode = page.getByText("用户排行", { exact: true });
                await rankingMode.waitFor({ state: "visible" });
                await rankingMode.click();
            })(),
        ]);
        await page.locator(".ant-spin-spinning").waitFor({ state: "detached" });
        await page.waitForFunction(() => document.querySelector(".audit-ranking")?.textContent.includes("nav-user-"));
        const rankingRow = page.locator(".audit-ranking tr.ant-table-row").filter({ hasText: ordinaryEmail });
        const userLink = rankingRow.locator("a");
        const href = await userLink.getAttribute("href");
        assert.equal(href, hash("/user", { id: userId }));
        await Promise.all([
            page.waitForResponse((response) => new URL(response.url()).pathname.endsWith("/user/fetch")),
            page.waitForURL((url) => url.hash === href),
            userLink.click(),
        ]);
        assertFilterCall("/user/fetch", "id", userId);
    });

    await check("leak-probe members link to user and subscription audit", async () => {
        await gotoHash(page, "#/leak-probe");
        const taskRow = page.locator(".ant-table-tbody tr.ant-table-row").filter({ hasText: `Navigation task ${stamp}` });
        await taskRow.waitFor({ state: "visible" });
        await Promise.all([
            page.waitForResponse((response) => new URL(response.url()).pathname.endsWith("/leak-probe/detail")),
            taskRow.getByText("查看任务", { exact: true }).click(),
        ]);
        const taskDrawer = page.locator(".leak-task-drawer");
        await taskDrawer.waitFor({ state: "visible" });
        const memberRow = taskDrawer.locator(".leak-members tr.ant-table-row").filter({ hasText: ordinaryEmail });
        await memberRow.waitFor({ state: "visible" });
        const userLink = memberRow.locator(`a[href="${hash("/user", { id: userId })}"]`);
        assert.equal(await userLink.count(), 1);
        assert.equal(await memberRow.locator(`a[href="${hash("/subscribe-audit", { user_id: userId })}"]`).count(), 1);
        const auditHref = await memberRow.locator("a").filter({ hasText: "订阅审计" }).getAttribute("href");
        await Promise.all([
            page.waitForResponse((response) => new URL(response.url()).pathname.endsWith("/subscribe-audit/fetch")),
            page.waitForURL((url) => url.hash === auditHref),
            memberRow.locator(`a[href="${auditHref}"]`).click(),
        ]);
        assertFilterCall("/subscribe-audit/fetch", "user_id", userId);

        await gotoHash(page, "#/leak-probe");
        const reopenedTask = page.locator(".ant-table-tbody tr.ant-table-row").filter({ hasText: `Navigation task ${stamp}` });
        await Promise.all([
            page.waitForResponse((response) => new URL(response.url()).pathname.endsWith("/leak-probe/detail")),
            reopenedTask.getByText("查看任务", { exact: true }).click(),
        ]);
        const nodeHref = hash("/server/manage", { type: "vmess", id: 911 });
        const vmessLink = page.locator(`.leak-task-drawer a[href="${nodeHref}"]`).first();
        await vmessLink.waitFor({ state: "visible" });
        await Promise.all([
            page.waitForResponse((response) => new URL(response.url()).pathname.endsWith("/server/manage/getNodes")),
            page.waitForURL((url) => url.hash === nodeHref),
            vmessLink.click(),
        ]);
        const targetRows = page.locator(".nodes-table tr.ant-table-row");
        await targetRows.first().waitFor({ state: "visible" });
        assert.equal(await targetRows.count(), 1, "node link must filter by both protocol and ID when IDs collide");
        await targetRows.getByText(`Nav VMess ${stamp}`, { exact: true }).waitFor({ state: "visible" });
        assert.equal(await page.getByText(`Nav Trojan ${stamp}`, { exact: true }).count(), 0);
    });

    await check("user detail order and user plan/group links target exact records", async () => {
        const userHash = hash("/user", { id: userId });
        await gotoHash(page, userHash);
        assertFilterCall("/user/fetch", "id", userId);
        const userRow = page.locator(".ant-table-tbody tr.ant-table-row").filter({ hasText: ordinaryEmail });
        await userRow.waitFor({ state: "visible" });
        const orderLinkFromDetail = async () => {
            await userRow.getByText(ordinaryEmail, { exact: true }).click();
            const detail = page.locator(".ant-drawer-open").last();
            await detail.waitFor({ state: "visible" });
            await detail.getByRole("tab", { name: "订单记录", exact: true }).click();
            const link = detail.locator(`a[href="${hash("/order", { trade_no: tradeNo })}"]`);
            await link.waitFor({ state: "visible" });
            return link;
        };
        const orderLink = await orderLinkFromDetail();
        const orderHref = await orderLink.getAttribute("href");
        assert.equal(queryFromHref(orderHref).get("trade_no"), tradeNo);
        await Promise.all([
            page.waitForResponse((response) => new URL(response.url()).pathname.endsWith("/order/fetch")),
            page.waitForURL((url) => url.hash === orderHref),
            orderLink.click(),
        ]);
        assertFilterCall("/order/fetch", "trade_no", tradeNo);
        await page.reload({ waitUntil: "networkidle" });
        assertFilterCall("/order/fetch", "trade_no", tradeNo);
        await page.goBack();
        await page.waitForURL((url) => url.hash === userHash);

        await gotoHash(page, userHash);
        const row = page.locator(".ant-table-tbody tr.ant-table-row").filter({ hasText: ordinaryEmail });
        const planHref = hash("/plan", { id: planId });
        const planLink = row.locator(`a[href="${planHref}"]`);
        await planLink.waitFor({ state: "visible" });
        await Promise.all([
            page.waitForResponse((response) => new URL(response.url()).pathname.endsWith("/plan/fetch")),
            page.waitForURL((url) => url.hash === planHref),
            planLink.click(),
        ]);
        const planRows = page.locator(".ant-table-tbody tr.ant-table-row");
        await planRows.first().waitFor({ state: "visible" });
        assert.equal(await planRows.count(), 1, "plan id link should client-filter the full list to one row");
        await page.getByText(`Nav plan ${stamp}`, { exact: true }).waitFor({ state: "visible" });

        await gotoHash(page, userHash);
        const groupHref = hash("/server/group", { id: groupId });
        const groupLink = page.locator(".ant-table-tbody tr.ant-table-row").filter({ hasText: ordinaryEmail }).locator(`a[href="${groupHref}"]`);
        await groupLink.waitFor({ state: "visible" });
        await Promise.all([
            page.waitForResponse((response) => new URL(response.url()).pathname.endsWith("/server/group/fetch")),
            page.waitForURL((url) => url.hash === groupHref),
            groupLink.click(),
        ]);
        const groupRows = page.locator(".ant-table-tbody tr.ant-table-row");
        await groupRows.first().waitFor({ state: "visible" });
        assert.equal(await groupRows.count(), 1, "group id link should client-filter the full list to one row");
        await page.getByText(`Nav group ${stamp}`, { exact: true }).waitFor({ state: "visible" });
    });

    await check("query links survive refresh, new tab, back/forward, and same-href reset", async () => {
        const userHash = hash("/user", { id: userId });
        await page.evaluate(() => sessionStorage.setItem("admin-user-initial-filters", JSON.stringify([{ key: "email", condition: "=", value: "stale@example.test" }])));
        await gotoHash(page, userHash);
        assertFilterCall("/user/fetch", "id", userId);
        assert.equal(await page.evaluate(() => sessionStorage.getItem("admin-user-initial-filters")), null);

        await page.reload({ waitUntil: "networkidle" });
        assertFilterCall("/user/fetch", "id", userId);
        const secondPage = await context.newPage();
        await gotoHash(secondPage, "#/dashboard");
        await gotoHash(secondPage, userHash);
        assertFilterCall("/user/fetch", "id", userId);
        await secondPage.goBack();
        await secondPage.waitForURL("**#/dashboard");
        await secondPage.goForward();
        await secondPage.waitForURL((url) => url.hash === userHash);
        await secondPage.locator(".ant-spin-spinning").waitFor({ state: "detached" });
        assertFilterCall("/user/fetch", "id", userId);
        await secondPage.close();

        const userRow = page.locator(".ant-table-tbody tr.ant-table-row").filter({ hasText: ordinaryEmail });
        await page.getByRole("button", { name: "过滤器", exact: true }).click();
        const filterDrawer = page.locator(".user-filter-drawer");
        await filterDrawer.waitFor({ state: "visible" });
        await filterDrawer.getByRole("button", { name: "重置筛选", exact: true }).click();
        await filterDrawer.waitFor({ state: "hidden" });
        await userRow.waitFor({ state: "visible" });
        await userRow.getByText(ordinaryEmail, { exact: true }).click();
        const detail = page.locator(".ant-drawer-open").last();
        await detail.waitFor({ state: "visible" });
        const emailLabel = detail.locator(".key-values strong").getByText("邮箱", { exact: true });
        const emailLink = emailLabel.locator("xpath=..").locator(`a[href="${userHash}"]`);
        await emailLink.waitFor({ state: "visible" });
        const countBefore = apiCalls().filter((call) => call.path === "/user/fetch").length;
        await Promise.all([
            page.waitForResponse((response) => new URL(response.url()).pathname.endsWith("/user/fetch")),
            emailLink.click(),
        ]);
        await page.locator(".ant-table-tbody tr.ant-table-row").first().waitFor({ state: "visible" });
        await page.locator(".ant-drawer-open").waitFor({ state: "detached" });
        assert.ok(apiCalls().filter((call) => call.path === "/user/fetch").length > countBefore, "same-href click should request the remounted user list");
        assertFilterCall("/user/fetch", "id", userId);
        assert.equal(new URL(page.url()).hash, userHash, "same-href click should retain URL while remounting filtered page");
    });

    assert.equal(report.failures.length, 0, "fixture navigation page should have no runtime errors");
    console.log("admin-navigation: all fixture browser checks passed");
} catch (error) {
    if (!report.failures.some((failure) => failure.stack === error?.stack)) {
        report.failures.push({ name: "uncaught", message: error?.message ?? String(error), stack: error?.stack ?? null });
    }
    console.error(error);
    process.exitCode = 1;
} finally {
    if (browser) await browser.close();
    if (fixture) await fixture.close();
    report.finishedAt = new Date().toISOString();
    await writeFile(resolve(output, "report.json"), JSON.stringify(report, null, 2) + "\n");
}
