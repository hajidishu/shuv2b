import assert from "node:assert/strict";
import { chromium } from "playwright";
import { mkdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { routes, nodeTypes, nodeKey } from "../src/domain.mjs";
import { startFixture } from "./fixture.mjs";
const live = !!process.env.ADMIN_LIVE_URL;
if (live && !process.env.ADMIN_LIVE_PASSWORD)
    throw new Error(
        "Set ADMIN_LIVE_PASSWORD for the explicitly authorized test backend",
    );
const fixture = live ? null : await startFixture();
const origin = process.env.ADMIN_LIVE_URL ?? fixture.origin;
const entry = process.env.ADMIN_LIVE_ENTRY ?? (live ? "/test-admin" : "/");
const email =
    process.env.ADMIN_LIVE_EMAIL ??
    (live ? "admin@v2b.test" : "admin@example.test");
const password = process.env.ADMIN_LIVE_PASSWORD ?? "fixture-password";
const prefix = "rewrite-" + Date.now();
const out = resolve(import.meta.dirname, "../test-results");
await mkdir(out, { recursive: true });
const browser = await chromium.launch({
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
const page = await context.newPage();
const errors = [],
    bad = [];
page.on("pageerror", (e) => errors.push(e.message));
page.on("response", (r) => {
    if (r.status() >= 400 && r.url().includes("/api/"))
        bad.push({ url: r.url(), status: r.status() });
});
const report = {
    kind: live ? "live-backend" : "fixture",
    passed: [],
    failures: [],
    cleanup: [],
};
const owned = {
    nodes: [],
    groups: [],
    users: [],
    plans: [],
    notice: [],
    knowledge: [],
    coupon: [],
    giftcard: [],
    routes: [],
    payment: [],
};
let token;
const check = async (name, fn) => {
    if (
        process.env.ADMIN_CASE_FILTER &&
        !new RegExp(process.env.ADMIN_CASE_FILTER).test(name)
    )
        return;
    try {
        await fn();
        report.passed.push(name);
        console.log("PASS " + name);
    } catch (e) {
        report.failures.push({ name, message: e.message });
        await page.screenshot({
            path: resolve(
                out,
                "failure-" + name.replace(/[^a-z0-9-]/gi, "") + ".png",
            ),
            fullPage: true,
        });
        console.log("FAIL " + name + ": " + e.message);
        throw e;
    }
};
const api = async (path, data, method = data ? "POST" : "GET") => {
    const res = await context.request.fetch(
        origin + "/api/v1/test-admin" + path,
        {
            method,
            headers: { Authorization: token, Accept: "application/json" },
            ...(data ? { data } : {}),
        },
    );
    const text = await res.text();
    assert.ok(res.ok(), path + " " + res.status() + " " + text.slice(0, 300));
    return JSON.parse(text);
};
const listNodes = async () => (await api("/server/manage/getNodes")).data;
const drawer = () =>
    page.locator(".ant-drawer:visible,.ant-modal:visible").last();
const input = (name) =>
    drawer()
        .getByLabel(name, { exact: true })
        .and(page.locator("input,textarea,select"));
const editRow = async (row) => {
    const direct = row.getByRole("button", { name: "编辑", exact: true });
    if (await direct.count()) await direct.click();
    else if (await row.getByText("编辑", { exact: true }).count())
        await row.getByText("编辑", { exact: true }).click();
    else {
        await row.getByText("操作", { exact: true }).click();
        await page
            .locator(".ant-dropdown:visible")
            .getByText("编辑", { exact: true })
            .click();
    }
};
const choose = async (label, value) => {
    await input(label).focus();
    await input(label).press("ArrowDown");
    await page
        .locator(".ant-select-dropdown:visible")
        .getByText(value, { exact: true })
        .last()
        .click();
    await drawer().locator(".ant-drawer-title,.ant-modal-title").click();
};
const save = async (suffix) => {
    const promise = page.waitForResponse(
        (r) => r.url().endsWith(suffix) && r.request().method() === "POST",
    );
    await drawer().getByRole("button", { name: "保存", exact: true }).click();
    const response = await promise;
    assert.ok(response.ok(), await response.text());
    await page
        .locator(".ant-drawer:visible,.ant-modal:visible")
        .waitFor({ state: "hidden" });
};
const navigate = async (path) => {
    await page.goto(origin + entry + "#" + path, { waitUntil: "networkidle" });
    // Hash navigation can finish before React starts its requests. Reload so
    // screenshots and route assertions observe the completed page, not its predecessor.
    await page.reload({ waitUntil: "networkidle" });
    await page.waitForFunction(
        () => !document.querySelector(".ant-spin-spinning"),
    );
    await page.mouse.move(1430, 990);
};
const editJson = async (name, value) => {
    await drawer()
        .getByRole("button", { name: "编辑" + name, exact: true })
        .click();
    const dialog = page.getByRole("dialog", {
        name: "编辑" + name,
        exact: true,
    });
    await dialog
        .getByRole("textbox", { name: name + " JSON", exact: true })
        .fill(value);
    await dialog.getByRole("button", { name: "完成", exact: true }).click();
    await dialog.waitFor({ state: "hidden" });
};
try {
    const login = await context.request.post(
        origin + "/api/v1/passport/auth/login",
        { data: { email, password } },
    );
    token = (await login.json()).data.auth_data;
    await page.goto(origin + entry + "#/login", { waitUntil: "networkidle" });
    await page.getByLabel("邮箱", { exact: true }).fill(email);
    await page.getByLabel("密码", { exact: true }).fill(password);
    await page.getByRole("button", { name: "登录", exact: true }).click();
    await page.waitForURL("**#/dashboard");
    await page.waitForLoadState("networkidle");
    await check("all-page-routes", async () => {
        for (const path of routes.filter(
            (x) => !["/login", "/ticket/:ticket_id"].includes(x),
        )) {
            await navigate(path);
            assert.equal(
                await page.getByText("页面发生异常", { exact: true }).count(),
                0,
                path,
            );
            assert.equal(
                await page
                    .locator('.ant-alert-error, [role="alert"].error')
                    .count(),
                0,
                path,
            );
            await page.screenshot({
                path: resolve(
                    out,
                    (live ? "live-" : "fixture-") +
                        path.replace(/^\//, "").replaceAll("/", "-") +
                        ".png",
                ),
                fullPage: true,
            });
        }
        assert.equal(errors.length, 0, errors.join("\n"));
        assert.equal(bad.length, 0, JSON.stringify(bad));
    });
    await check(
        "original-toolbar-positions-and-settings-alignment",
        async () => {
            const measurements = [];
            for (const [path, label, width] of [
                ["/plan", "添加订阅", 110],
                ["/coupon", "添加优惠券", 124],
                ["/giftcard", "添加礼品卡", 124],
                ["/notice", "添加公告", 110],
                ["/knowledge", "新增", 82],
                ["/server/group", "添加权限组", 124],
                ["/server/route", "添加路由", 110],
                ["/config/payment", "添加支付方式", 138],
                ["/user", "过滤器", 96],
                ["/order", "过滤器", 96],
            ]) {
                await navigate(path);
                const measured = await page
                    .getByRole("button", { name: label, exact: true })
                    .evaluate((el) => {
                        const r = el.getBoundingClientRect(),
                            s = getComputedStyle(el);
                        return {
                            x: r.x,
                            y: r.y,
                            width: r.width,
                            height: r.height,
                            background: s.backgroundColor,
                            radius: s.borderRadius,
                        };
                    });
                measurements.push({ path, ...measured });
                assert.ok(
                    Math.abs(measured.x - 289) <= 1,
                    path + " toolbar x " + measured.x,
                );
                assert.ok(
                    Math.abs(measured.y - 91) <= 1,
                    path + " toolbar y " + measured.y,
                );
                assert.equal(measured.height, 32, path);
                assert.ok(
                    Math.abs(measured.width - width) <= 2,
                    path + " width " + measured.width,
                );
                assert.equal(measured.background, "rgb(255, 255, 255)", path);
                assert.equal(
                    measured.radius,
                    path === "/user" ? "4px 0px 0px 4px" : "4px",
                    path,
                );
                const toolbar = await page
                    .locator(".table-toolbar")
                    .first()
                    .boundingBox();
                const head = await page.locator("thead").first().boundingBox();
                assert.ok(
                    !head || toolbar.y + toolbar.height <= head.y + 1,
                    path + " overlapping table header",
                );
            }
            await navigate("/config/system");
            await page
                .locator("#app_description")
                .waitFor({ state: "visible" });
            await page.screenshot({
                path: resolve(
                    out,
                    live ? "live-settings-layout.png" : "settings-layout.png",
                ),
                fullPage: true,
            });
            const rows = await page
                .locator(".settings-fields .ant-form-item-row:visible")
                .evaluateAll((els) =>
                    els.map((el) => {
                        const label = el
                                .querySelector(".ant-form-item-label")
                                .getBoundingClientRect(),
                            input = el
                                .querySelector(".ant-form-item-control")
                                .getBoundingClientRect();
                        return {
                            labelX: label.x,
                            labelRight: label.right,
                            inputX: input.x,
                            inputRight: input.right,
                        };
                    }),
                );
            assert.ok(rows.length > 4);
            assert.ok(
                rows.every(
                    (r) => r.inputX > r.labelRight && r.inputRight < 1417,
                ),
                JSON.stringify(rows),
            );
            await writeFile(
                resolve(out, live ? "live-layout.json" : "layout.json"),
                JSON.stringify(
                    { buttons: measurements, settingsRows: rows },
                    null,
                    2,
                ),
            );
        },
    );
    await check("settings-autosave-retains-edits-across-tabs", async () => {
        const original = (await api("/config/fetch")).data.site.app_description;
        try {
            await navigate("/config/system");
            const description = page.locator("#app_description");
            await description.fill(prefix + "-description");
            await page.getByRole("tab", { name: "安全", exact: true }).click();
            await page.getByRole("tab", { name: "站点", exact: true }).click();
            assert.equal(
                await description.inputValue(),
                prefix + "-description",
            );
            await page.getByText("已保存", { exact: true }).waitFor();
            assert.equal(
                (await api("/config/fetch")).data.site.app_description,
                prefix + "-description",
            );
            await description.fill(prefix + "-debounced");
            await page.getByText("已保存", { exact: true }).waitFor();
            assert.equal(
                (await api("/config/fetch")).data.site.app_description,
                prefix + "-debounced",
            );
        } finally {
            await navigate("/dashboard");
            await api("/config/save", { app_description: original ?? null });
        }
    });
    await check("dashboard-wide-chart-and-compact-settings-tabs", async () => {
        await navigate("/dashboard");
        await page.evaluate(() =>
            document.fonts.load("17px original-simple-line-icons"),
        );
        assert.ok(
            await page.evaluate(() =>
                [...document.fonts].some(
                    (f) =>
                        f.family === "original-simple-line-icons" &&
                        f.status === "loaded",
                ),
            ),
            "Original sidebar icon font must load",
        );
        assert.equal(
            await page
                .getByRole("button", { name: "查看统计数据", exact: true })
                .count(),
            0,
        );
        for (const width of [1440, 2560, 3440]) {
            await page.setViewportSize({ width, height: 1100 });
            await navigate("/dashboard");
            await page.waitForFunction(() => {
                const host = document.querySelector(
                    '[aria-label="订单统计趋势"]',
                );
                const svg = host?.querySelector("svg");
                return (
                    svg &&
                    Math.abs(
                        svg.getBoundingClientRect().width -
                            host.getBoundingClientRect().width,
                    ) < 2
                );
            });
            const sizes = await page
                .getByRole("img", { name: "订单统计趋势", exact: true })
                .evaluate((host) => ({
                    svg: host.querySelector("svg").getBoundingClientRect()
                        .width,
                    grid: Math.max(
                        ...[...host.querySelectorAll("svg path")].map(
                            (path) => path.getBoundingClientRect().width,
                        ),
                    ),
                }));
            assert.ok(
                sizes.grid / sizes.svg > 0.9,
                JSON.stringify({ width, ...sizes }),
            );
            await page.screenshot({
                path: resolve(
                    out,
                    (live ? "live-" : "fixture-") +
                        "dashboard-" +
                        width +
                        ".png",
                ),
                fullPage: true,
            });
        }
        await navigate("/config/system");
        const tabBounds = () =>
            page.locator(".settings-card .ant-tabs-tab").evaluateAll((els) =>
                els.map((el) => {
                    const r = el.getBoundingClientRect();
                    return { x: r.x, right: r.right };
                }),
            );
        const tabs = await tabBounds();
        assert.ok(tabs.length > 1);
        for (let index = 1; index < tabs.length; index++) {
            assert.ok(
                Math.abs(tabs[index].x - tabs[index - 1].right - 32) < 1,
                JSON.stringify(tabs),
            );
        }
        await page.screenshot({
            path: resolve(
                out,
                (live ? "live-" : "fixture-") + "settings-wide.png",
            ),
            fullPage: true,
        });
        // Font metrics vary across runners. The tab strip must keep the same
        // intrinsic width at two wide viewports rather than fill either one.
        await page.setViewportSize({ width: 1920, height: 1000 });
        await page.evaluate(() => new Promise((resolve) => {
            requestAnimationFrame(() => requestAnimationFrame(resolve));
        }));
        const narrowerTabs = await tabBounds();
        assert.equal(narrowerTabs.length, tabs.length);
        assert.ok(
            Math.abs(
                narrowerTabs.at(-1).right - narrowerTabs[0].x -
                (tabs.at(-1).right - tabs[0].x),
            ) < 2,
            "Tabs must not stretch across wide screens: " +
                JSON.stringify({ tabs, narrowerTabs }),
        );
        await page.setViewportSize({ width: 1440, height: 1000 });
    });
    await api("/server/group/save", { name: prefix + "-group" });
    const group = (await api("/server/group/fetch")).data.find(
        (x) => x.name === prefix + "-group",
    );
    owned.groups.push(group.id);
    await check("v2node-protocol-visibility-and-original-drawer", async () => {
        for (const protocol of [
            "vmess",
            "vless",
            "shadowsocks",
            "trojan",
            "anytls",
            "hysteria2",
            "tuic",
        ]) {
            await navigate("/server/manage");
            await page
                .getByRole("button", { name: "添加节点", exact: true })
                .click();
            await page
                .locator(".ant-dropdown:visible")
                .getByText("V2node", { exact: true })
                .click();
            await choose(
                "节点协议",
                {
                    vmess: "VMess",
                    vless: "VLess",
                    shadowsocks: "Shadowsocks",
                    trojan: "Trojan",
                    anytls: "AnyTLS",
                    hysteria2: "Hysteria2",
                    tuic: "Tuic",
                }[protocol],
            );
            const panel = await drawer()
                .locator(".ant-drawer-content-wrapper")
                .boundingBox();
            assert.ok(Math.abs(panel.width - 500) < 2, "Original drawer width");
            assert.equal(
                await drawer()
                    .getByRole("button", { name: "编辑填充方案", exact: true })
                    .count(),
                protocol === "anytls" ? 1 : 0,
                protocol,
            );
            assert.equal(
                await drawer().locator("#up_mbps").count(),
                protocol === "hysteria2" ? 1 : 0,
                protocol,
            );
            assert.equal(
                await drawer().locator("#disable_sni").count(),
                protocol === "tuic" ? 1 : 0,
                protocol,
            );
            assert.equal(
                await drawer().locator("#cipher").count(),
                protocol === "shadowsocks" ? 1 : 0,
                protocol,
            );
            await input("节点名称").fill(prefix + "-v2-" + protocol);
            await input("连接端口").fill("443");
            await input("服务端口").fill("443");
            await choose("权限组", prefix + "-group");
            await input("连接地址").fill("node.example.invalid");
            if (protocol === "anytls") await editJson("填充方案", '["stop=8"]');
            await page.screenshot({
                path: resolve(
                    out,
                    (live ? "live-" : "fixture-") +
                        "node-v2-" +
                        protocol +
                        ".png",
                ),
                fullPage: true,
            });
            const request = page.waitForRequest(
                (r) =>
                    r.url().endsWith("/server/v2node/save") &&
                    r.method() === "POST",
            );
            await save("/server/v2node/save");
            const payload = (await request).postDataJSON();
            if (protocol !== "anytls")
                assert.ok(!("padding_scheme" in payload));
            if (protocol !== "hysteria2") assert.ok(!("up_mbps" in payload));
            const node = (await listNodes()).find(
                (n) => n.name === prefix + "-v2-" + protocol,
            );
            assert.ok(node, protocol);
            owned.nodes.push({ type: "v2node", id: node.id });
            assert.equal(node.protocol, protocol);
        }
    });
    await check("create-edit-all-node-types", async () => {
        for (const [type, label] of Object.entries(nodeTypes)) {
            await navigate("/server/manage");
            await page
                .getByRole("button", { name: "添加节点", exact: true })
                .click();
            await page
                .locator(".ant-dropdown:visible")
                .getByText(label, { exact: true })
                .click();
            await input("节点名称").fill(prefix + "-" + type);
            await input("连接端口").fill("443");
            await input("服务端口").fill("443");
            if (type === "v2node") await choose("节点协议", "VMess");
            await choose("权限组", prefix + "-group");
            await input("连接地址").fill("node.example.invalid");
            if (type === "anytls")
                await editJson("填充方案", '["stop=8","0=30-30"]');
            await save("/server/" + type + "/save");
            const node = (await listNodes()).find(
                (x) => x.type === type && x.name === prefix + "-" + type,
            );
            assert.ok(node, type);
            owned.nodes.push({ type, id: node.id });
            const row = page
                .locator(".ant-table-tbody tr")
                .filter({ hasText: prefix + "-" + type })
                .first();
            await editRow(row);
            await input("流量倍率").fill("1.75");
            await save("/server/manage/batch");
            assert.equal(
                Number(
                    (await listNodes()).find(
                        (x) => x.type === type && x.id === node.id,
                    ).rate,
                ),
                1.75,
                type,
            );
        }
    });
    await check("node-parent-clear-and-protocol-switch", async () => {
        const list = await listNodes(),
            child = list.find((n) => n.name === prefix + "-v2node"),
            parent = list.find((n) => n.name === prefix + "-v2-vmess");
        assert.ok(child && parent);
        await navigate("/server/manage");
        await editRow(
            page.locator('tr[data-row-key="' + nodeKey(child) + '"]'),
        );
        await choose("父节点", parent.name);
        await save("/server/manage/batch");
        assert.equal(
            (await listNodes()).find((n) => nodeKey(n) === nodeKey(child))
                .parent_id,
            parent.id,
        );
        await editRow(
            page.locator('tr[data-row-key="' + nodeKey(child) + '"]'),
        );
        const parentControl = drawer()
            .locator(".ant-form-item")
            .filter({ has: page.locator("#parent_id") })
            .locator(".ant-select");
        await parentControl.hover();
        await parentControl.locator(".ant-select-clear").click();
        await choose("节点协议", "AnyTLS");
        await editJson("填充方案", '["stop=8"]');
        await choose("节点协议", "VMess");
        assert.equal(
            await drawer()
                .getByRole("button", { name: "编辑填充方案", exact: true })
                .count(),
            0,
        );
        const request = page.waitForRequest(
            (r) =>
                r.url().endsWith("/server/manage/batch") &&
                r.method() === "POST",
        );
        await save("/server/manage/batch");
        const changes = (await request).postDataJSON().changes;
        assert.equal(changes.parent_id, null);
        assert.ok(!("padding_scheme" in changes));
        assert.equal(
            (await listNodes()).find((n) => nodeKey(n) === nodeKey(child))
                .parent_id,
            null,
        );
    });
    await check(
        "batch-update-preserves-config-and-unselected-nodes",
        async () => {
            await navigate("/server/manage");
            const before = await listNodes();
            const chosen = owned.nodes.filter((x) =>
                ["vmess", "vless"].includes(x.type),
            );
            for (const n of chosen) {
                await page
                    .locator(".ant-table-tbody tr")
                    .filter({ hasText: prefix + "-" + n.type })
                    .first()
                    .getByRole("checkbox")
                    .check();
            }
            await page
                .getByRole("button", { name: "批量编辑", exact: true })
                .click();
            await input("流量倍率").fill("2.25");
            await save("/server/manage/batch");
            const after = await listNodes();
            for (const own of owned.nodes) {
                const a = after.find((x) => nodeKey(x) === nodeKey(own)),
                    b = before.find((x) => nodeKey(x) === nodeKey(own));
                assert.equal(
                    Number(a.rate),
                    chosen.some((x) => nodeKey(x) === nodeKey(own))
                        ? 2.25
                        : Number(b.rate),
                );
                for (const k of [
                    "group_id",
                    "network",
                    "tls",
                    "padding_scheme",
                ])
                    assert.deepEqual(a[k], b[k], own.type + " " + k);
            }
        },
    );
    await check("json-validation-blocks-invalid-save", async () => {
        await navigate("/server/manage");
        await editRow(
            page
                .locator(".ant-table-tbody tr")
                .filter({ hasText: prefix + "-anytls" })
                .first(),
        );
        await drawer()
            .getByRole("button", { name: "编辑填充方案", exact: true })
            .click();
        const dialog = page.getByRole("dialog", {
            name: "编辑填充方案",
            exact: true,
        });
        await dialog
            .getByRole("textbox", { name: "填充方案 JSON" })
            .fill("{invalid");
        await dialog
            .getByText("请输入有效 JSON 数组", { exact: true })
            .waitFor();
        assert.ok(
            await dialog
                .getByRole("button", { name: "完成", exact: true })
                .isDisabled(),
        );
        await dialog
            .getByRole("textbox", { name: "填充方案 JSON" })
            .fill('["stop=9"]');
        await dialog.getByRole("button", { name: "完成", exact: true }).click();
        await dialog.waitFor({ state: "hidden" });
        await save("/server/manage/batch");
        const saved = (await listNodes()).find(
            (x) => x.name === prefix + "-anytls",
        ).padding_scheme;
        assert.deepEqual(
            typeof saved === "string" ? JSON.parse(saved) : saved,
            ["stop=9"],
        );
    });
    await api("/user/generate", {
        email_prefix: prefix,
        email_suffix: "example.test",
        password: "RewriteTest2026!",
    });
    const user = (await api("/user/fetch?pageSize=500")).data.find(
        (x) => x.email === prefix + "@example.test",
    );
    owned.users.push(user.id);
    await check("user-edit-money-and-device-limit", async () => {
        await navigate("/user");
        const row = page
            .locator(".ant-table-tbody tr")
            .filter({ hasText: user.email });
        await editRow(row);
        assert.equal(
            await input("密码").inputValue(),
            "",
            "Existing password hashes must not fill the password input",
        );
        await input("余额").fill("19.99");
        await input("佣金余额").fill("2.01");
        await input("设备限制").fill("7");
        await save("/user/update");
        const data = (await api("/user/getUserInfoById?id=" + user.id)).data;
        assert.equal(data.balance, 1999);
        assert.equal(data.commission_balance, 201);
        assert.equal(data.device_limit, 7);
        if (live) {
            const login = await context.request.post(
                origin + "/api/v1/passport/auth/login",
                { data: { email: user.email, password: "RewriteTest2026!" } },
            );
            assert.ok(
                login.ok(),
                "Editing balance must preserve the original password",
            );
        }
    });
    await check("balance-and-commission-filters-and-export-scope", async () => {
        await navigate("/user");
        await page.getByRole("button", { name: "过滤器", exact: true }).click();
        const add = page.getByRole("button", {
            name: "添加筛选条件",
            exact: true,
        });
        for (const [field, label, value] of [
            ["balance", "余额", "19.99"],
            ["commission_balance", "佣金余额", "2.01"],
        ]) {
            await add.click();
            const index = field === "balance" ? 1 : 2;
            await page
                .locator('.ant-select[aria-label="筛选字段 ' + index + '"]')
                .click();
            const listId = await page
                .getByRole("combobox", {
                    name: "筛选字段 " + index,
                    exact: true,
                })
                .getAttribute("aria-controls");
            await page
                .locator(
                    ".ant-select-dropdown:has(#" +
                        listId +
                        ') .ant-select-item-option[title="' +
                        label +
                        '"]',
                )
                .click();
            await page.getByLabel("筛选值 " + index).fill(value);
        }
        const response = page.waitForResponse(
            (r) =>
                r.url().includes("/user/fetch?") && r.url().includes("balance"),
        );
        await page
            .getByRole("button", { name: "筛选用户", exact: true })
            .click();
        const result = await (await response).json();
        assert.ok(result.data.some((x) => x.id === user.id));
        assert.ok(
            result.data.every(
                (x) => x.balance === 1999 && x.commission_balance === 201,
            ),
        );
        await page
            .locator(".ant-table-tbody tr")
            .filter({ hasText: user.email })
            .getByRole("checkbox")
            .check();
        const req = page.waitForRequest((r) =>
            r.url().endsWith("/user/dumpCSV"),
        );
        const downloaded = page.waitForEvent("download");
        await page.getByRole("button", { name: "操作", exact: true }).click();
        await page
            .locator(".ant-dropdown:visible")
            .getByText("导出CSV", { exact: true })
            .click();
        const payload = (await req).postDataJSON();
        assert.deepEqual(payload.ids, [user.id]);
        assert.equal(payload.filter[0].value, 1999);
        assert.equal(payload.filter[1].value, 201);
        await (await downloaded).saveAs(resolve(out, "filtered-users.csv"));
    });
    await check("batch-copy-and-delete-all-protocols", async () => {
        await navigate("/server/manage");
        const selectAcrossPages = async (nodes) => {
            await page.waitForFunction(
                () => !document.querySelector(".ant-spin-spinning"),
            );
            for (const node of nodes) {
                const row = page.locator(
                    'tr[data-row-key="' + nodeKey(node) + '"]',
                );
                if (!(await row.count())) {
                    const first = page.locator("li.ant-pagination-item-1");
                    if (await first.count()) await first.click();
                    for (
                        let attempt = 0;
                        !(await row.count()) && attempt < 10;
                        attempt++
                    ) {
                        const next = page.locator(
                            "li.ant-pagination-next:not(.ant-pagination-disabled)",
                        );
                        assert.ok(
                            await next.count(),
                            "Node row unavailable " + nodeKey(node),
                        );
                        await next.click();
                    }
                }
                await row.getByRole("checkbox").check();
            }
        };
        const before = await listNodes(),
            originals = owned.nodes.filter((n) =>
                before.some(
                    (row) =>
                        nodeKey(row) === nodeKey(n) &&
                        row.name === prefix + "-" + n.type,
                ),
            );
        await selectAcrossPages(originals);
        await page
            .getByRole("button", { name: "批量复制", exact: true })
            .click();
        let response = page.waitForResponse(
            (r) =>
                r.url().endsWith("/server/manage/batch") &&
                r.request().method() === "POST",
        );
        await page
            .locator(".ant-modal:visible")
            .getByRole("button", { name: "确定", exact: true })
            .click();
        assert.ok((await response).ok());
        await page.waitForLoadState("networkidle");
        const copies = (await listNodes()).filter(
            (n) => !before.some((b) => nodeKey(b) === nodeKey(n)),
        );
        owned.nodes.push(...copies.map(({ type, id }) => ({ type, id })));
        assert.equal(originals.length, Object.keys(nodeTypes).length);
        assert.equal(copies.length, originals.length);
        assert.ok(copies.every((n) => Number(n.show) === 0));
        await navigate("/server/manage");
        await selectAcrossPages(copies);
        await page
            .getByRole("button", { name: "批量删除", exact: true })
            .click();
        response = page.waitForResponse(
            (r) =>
                r.url().endsWith("/server/manage/batch") &&
                r.request().method() === "POST",
        );
        await page
            .locator(".ant-modal:visible")
            .getByRole("button", { name: "确定", exact: true })
            .click();
        assert.ok((await response).ok());
        const after = await listNodes();
        assert.ok(
            copies.every((n) => !after.some((a) => nodeKey(a) === nodeKey(n))),
        );
        assert.ok(
            originals.every((n) =>
                after.some((a) => nodeKey(a) === nodeKey(n)),
            ),
        );
        owned.nodes = owned.nodes.filter(
            (n) => !copies.some((copy) => nodeKey(copy) === nodeKey(n)),
        );
    });
    await check("management-create-and-edit-saves", async () => {
        for (const [path, label, base, nameField, fields, endpoint, kind] of [
            [
                "/plan",
                "添加订阅",
                "/plan",
                "订阅名称",
                { 权限组: prefix + "-group", 月付价格: "19.99" },
                "/plan/save",
                "plans",
            ],
            [
                "/coupon",
                "添加优惠券",
                "/coupon",
                "优惠券名称",
                { 数值: "19.99" },
                "/coupon/generate",
                "coupon",
            ],
            [
                "/giftcard",
                "添加礼品卡",
                "/giftcard",
                "礼品卡名称",
                { 数值: "25.00" },
                "/giftcard/generate",
                "giftcard",
            ],
            [
                "/notice",
                "添加公告",
                "/notice",
                "标题",
                { 公告内容: "<p>测试公告</p>" },
                "/notice/save",
                "notice",
            ],
            [
                "/knowledge",
                "新增",
                "/knowledge",
                "标题",
                { 分类: "测试分类", 内容: "<p>测试知识</p>" },
                "/knowledge/save",
                "knowledge",
            ],
            [
                "/server/route",
                "添加路由",
                "/server/route",
                "备注",
                { 匹配值: "example.invalid" },
                "/server/route/save",
                "routes",
            ],
        ]) {
            await navigate(path);
            await page
                .getByRole("button", { name: label, exact: true })
                .click();
            await input(nameField).fill(prefix + "-" + kind);
            for (const [key, value] of Object.entries(fields)) {
                if (key === "权限组") await choose(key, value);
                else if (key === "匹配规则") {
                    await input(key).fill(value);
                    await input(key).press("Enter");
                } else await input(key).fill(value);
            }
            if (["/coupon", "/giftcard"].includes(path)) {
                await drawer()
                    .getByPlaceholder("Start Time", { exact: true })
                    .fill("2026-10-01 00:00");
                await drawer()
                    .getByPlaceholder("Start Time", { exact: true })
                    .press("Enter");
                await drawer()
                    .getByPlaceholder("End Time", { exact: true })
                    .fill("2027-10-01 00:00");
                await drawer()
                    .getByPlaceholder("End Time", { exact: true })
                    .press("Enter");
            }
            await save(endpoint);
            const row = (await api(base + "/fetch?pageSize=500")).data.find(
                (x) => (x.name ?? x.title ?? x.remarks) === prefix + "-" + kind,
            );
            assert.ok(row, kind);
            owned[kind].push(row.id);
            await editRow(page.locator('tr[data-row-key="' + row.id + '"]'));
            await input(nameField).fill(prefix + "-" + kind + "-edited");
            await save(endpoint);
            const updated = (await api(base + "/fetch?pageSize=500")).data.find(
                (x) => x.id === row.id,
            );
            assert.equal(
                updated.name ?? updated.title ?? updated.remarks,
                prefix + "-" + kind + "-edited",
            );
            if (kind === "plans") {
                assert.equal(updated.group_id, group.id);
                assert.equal(updated.month_price, 1999);
            }
        }
    });
    if (live)
        await check("payment-configuration-saves", async () => {
            await navigate("/config/payment");
            await page
                .getByRole("button", { name: "添加支付方式", exact: true })
                .click();
            await drawer().waitFor();
            const gateway = drawer().getByRole("combobox", {
                name: "编辑支付网关",
                exact: true,
            });
            await input("显示名称").fill(prefix + "-payment");
            await gateway.press("ArrowDown");
            const listId = await gateway.getAttribute("aria-controls");
            await page
                .locator(
                    ".ant-select-dropdown:has(#" +
                        listId +
                        ') .ant-select-item-option[title="EPay"]',
                )
                .click();
            await input("URL").waitFor();
            assert.equal(
                await input("显示名称").inputValue(),
                prefix + "-payment",
                "Adapter switch must retain base fields",
            );
            await input("显示名称").fill(prefix + "-payment");
            await input("URL").fill("https://pay.example.invalid");
            await input("PID").fill("test-merchant");
            await input("KEY").fill("test-only-key");
            await input("TYPE").fill("alipay");
            await input("固定手续费").fill("0.29");
            await save("/payment/save");
            const row = (await api("/payment/fetch")).data.find(
                (x) => x.name === prefix + "-payment",
            );
            assert.ok(row);
            owned.payment.push(row.id);
            assert.equal(row.handling_fee_fixed, 29);
            assert.equal(row.config.pid, "test-merchant");
            assert.equal(row.enable, 0);
        });
    if (live)
        await check("order-assignment-manual-payment-and-queue", async () => {
            await navigate("/order");
            await page
                .getByRole("button", { name: "分配订单", exact: true })
                .click();
            await input("用户邮箱").fill(user.email);
            await choose("订阅计划", prefix + "-plans-edited");
            await choose("支付周期", "月付");
            await input("分配金额").fill("19.99");
            await save("/order/assign");
            const order = (
                await api(
                    "/order/fetch?filter[0][key]=user_id&filter[0][condition]=%3D&filter[0][value]=" +
                        user.id,
                )
            ).data[0];
            assert.ok(order);
            assert.equal(order.total_amount, 1999);
            assert.equal(order.status, 0);
            await page
                .locator('tr[data-row-key="' + order.id + '"]')
                .getByText("标记为", { exact: true })
                .first()
                .click();
            await page
                .locator(".ant-dropdown:visible")
                .getByText("已支付", { exact: true })
                .click();
            let response = page.waitForResponse(
                (r) =>
                    r.url().endsWith("/order/paid") &&
                    r.request().method() === "POST",
            );
            await page
                .locator(".ant-modal:visible")
                .getByRole("button", { name: "确定", exact: true })
                .click();
            assert.ok((await response).ok());
            let completed;
            for (let attempt = 0; attempt < 20; attempt++) {
                completed = (await api("/order/detail", { id: order.id })).data;
                if (completed.status === 3) break;
                await new Promise((r) => setTimeout(r, 500));
            }
            assert.equal(completed.status, 3);
            const updated = (await api("/user/getUserInfoById?id=" + user.id))
                .data;
            assert.equal(updated.plan_id, owned.plans[0]);
            assert.equal(updated.balance, 1999);
        });
    if (live) {
        await check(
            "order-email-type-filter-and-detail-user-navigation",
            async () => {
                const query = (filters) =>
                    "/order/fetch?" +
                    new URLSearchParams(
                        Object.fromEntries(
                            filters.flatMap((f, i) =>
                                Object.entries(f).map(([key, value]) => [
                                    `filter[${i}][${key}]`,
                                    value,
                                ]),
                            ),
                        ),
                    );
                const filters = [
                    { key: "email", condition: "=", value: user.email },
                ];
                const matches = (await api(query(filters))).data;
                assert.equal(matches.length, 1);
                const order = matches[0];
                const typed = (
                    await api(
                        query([
                            ...filters,
                            { key: "type", condition: "=", value: order.type },
                        ]),
                    )
                ).data;
                assert.equal(typed[0].id, order.id);
                assert.equal(
                    (
                        await api(
                            query([
                                {
                                    key: "email",
                                    condition: "模糊",
                                    value: prefix + "-nonexistent",
                                },
                            ]),
                        )
                    ).total,
                    0,
                );
                await navigate("/order");
                const detailResponse = page.waitForResponse(
                    (r) =>
                        r.url().endsWith("/order/detail") &&
                        r.request().method() === "POST",
                );
                await page
                    .locator(`tr[data-row-key="${order.id}"]`)
                    .locator("a[title]")
                    .click();
                const detailCall = await detailResponse;
                assert.equal(detailCall.request().postDataJSON().id, order.id);
                const detail = page.getByRole("dialog", {
                    name: "订单信息",
                    exact: true,
                });
                await detail
                    .getByRole("link", { name: user.email, exact: true })
                    .waitFor();
                const userResponse = page.waitForResponse(
                    (r) =>
                        r.url().includes("/user/fetch?") &&
                        r.request().method() === "GET",
                );
                await detail
                    .getByRole("link", { name: user.email, exact: true })
                    .click();
                assert.equal((await (await userResponse).json()).total, 1);
                await page.waitForURL("**#/user");
                await page.getByText(user.email, { exact: true }).waitFor();
            },
        );
        await check(
            "plan-force-update-syncs-test-user-and-keeps-cents",
            async () => {
                await navigate("/plan");
                await editRow(
                    page.locator(`tr[data-row-key="${owned.plans[0]}"]`),
                );
                const force = drawer().getByRole("checkbox", {
                    name: "强制更新到用户",
                    exact: true,
                });
                assert.equal(await force.isChecked(), false);
                await input("每期流量 GB").fill("123");
                await force.click();
                await save("/plan/save");
                const fresh = (await api("/user/getUserInfoById?id=" + user.id))
                    .data;
                assert.equal(fresh.transfer_enable, 123 * 1073741824);
                assert.equal(fresh.group_id, group.id);
                assert.equal(fresh.balance, 1999);
                assert.equal(
                    (await api("/plan/fetch")).data.find(
                        (p) => p.id === owned.plans[0],
                    ).month_price,
                    1999,
                );
            },
        );
    }
    if (live)
        await check("ticket-reply-close-and-admin-authorization", async () => {
            const login = await context.request.post(
                origin + "/api/v1/passport/auth/login",
                { data: { email: user.email, password: "RewriteTest2026!" } },
            );
            const bearer = (await login.json()).data.auth_data;
            const forbidden = await context.request.post(
                origin + "/api/v1/test-admin/server/manage/batch",
                {
                    headers: { Authorization: bearer },
                    data: {
                        action: "update",
                        nodes: [owned.nodes[0]],
                        changes: { rate: 99 },
                    },
                },
            );
            assert.equal(forbidden.status(), 403);
            const created = await context.request.post(
                origin + "/api/v1/user/ticket/save",
                {
                    headers: { Authorization: bearer },
                    data: {
                        subject: prefix + "-ticket",
                        level: 1,
                        message: "UI regression ticket",
                    },
                },
            );
            assert.ok(created.ok(), await created.text());
            const ticket = (await api("/ticket/fetch")).data.find(
                (t) => t.user_id === user.id,
            );
            assert.ok(ticket);
            await navigate("/ticket/" + ticket.id);
            await page
                .getByRole("textbox", { name: "工单回复" })
                .fill("Admin regression reply");
            let response = page.waitForResponse(
                (r) =>
                    r.url().endsWith("/ticket/reply") &&
                    r.request().method() === "POST",
            );
            await page
                .getByRole("button", { name: "回复工单", exact: true })
                .click();
            assert.ok((await response).ok());
            await page
                .getByText("Admin regression reply", { exact: true })
                .waitFor();
            await page
                .getByRole("button", { name: "关闭工单", exact: true })
                .click();
            response = page.waitForResponse(
                (r) =>
                    r.url().endsWith("/ticket/close") &&
                    r.request().method() === "POST",
            );
            await page
                .locator(".ant-modal:visible")
                .getByRole("button", { name: "确定", exact: true })
                .click();
            assert.ok((await response).ok());
            assert.equal(
                (await api("/ticket/fetch?id=" + ticket.id)).data.status,
                1,
            );
        });
    await check("all-management-editors-open", async () => {
        for (const [path, label] of [
            ["/plan", "添加订阅"],
            ["/coupon", "添加优惠券"],
            ["/giftcard", "添加礼品卡"],
            ["/notice", "添加公告"],
            ["/knowledge", "新增"],
            ["/server/group", "添加权限组"],
            ["/server/route", "添加路由"],
            ["/config/payment", "添加支付方式"],
            ["/order", "分配订单"],
            ["/config/theme", "编辑主题"],
        ]) {
            await navigate(path);
            await page
                .getByRole("button", { name: label, exact: true })
                .click();
            await drawer().waitFor();
            if (path === "/config/payment") {
                assert.equal(
                    await drawer()
                        .getByLabel("alert1", { exact: true })
                        .count(),
                    0,
                );
                assert.equal(
                    await page.locator(".ant-drawer:visible").count(),
                    0,
                );
                await page.waitForFunction(() => {
                    const modal = document.querySelector(".payment-modal");
                    return (
                        modal &&
                        Math.abs(modal.getBoundingClientRect().width - 520) < 2
                    );
                });
                const box = await drawer().boundingBox();
                assert.ok(
                    Math.abs(box.width - 520) < 2,
                    "Original payment modal width",
                );
                await page.screenshot({
                    path: resolve(
                        out,
                        (live ? "live-" : "fixture-") + "payment-editor.png",
                    ),
                    fullPage: true,
                });
            }
            assert.equal(
                await drawer()
                    .getByRole("button", { name: "保存", exact: true })
                    .count(),
                1,
                path,
            );
            assert.ok(
                (await drawer().locator("input,textarea").count()) > 0,
                path,
            );
            await drawer()
                .getByRole("button", { name: "取消", exact: true })
                .click();
        }
    });
    await check("responsive-layout-and-no-runtime-errors", async () => {
        await navigate("/dashboard");
        await page.screenshot({
            path: resolve(out, "dashboard-desktop.png"),
            fullPage: true,
        });
        await navigate("/server/manage");
        await page.screenshot({
            path: resolve(out, "nodes-desktop.png"),
            fullPage: true,
        });
        await navigate("/user");
        await page.screenshot({
            path: resolve(out, "users-desktop.png"),
            fullPage: true,
        });
        await page.setViewportSize({ width: 390, height: 844 });
        await page.reload({ waitUntil: "networkidle" });
        await page.screenshot({
            path: resolve(out, "users-mobile.png"),
            fullPage: true,
        });
        assert.ok(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
            "mobile user page overflows viewport",
        );
        for (const path of [
            "/server/manage",
            "/config/system",
            "/config/payment",
            "/order",
        ]) {
            await navigate(path);
            await page.screenshot({
                path: resolve(
                    out,
                    (live ? "live-" : "fixture-") +
                        path.slice(1).replaceAll("/", "-") +
                        "-mobile.png",
                ),
                fullPage: true,
            });
            assert.ok(
                await page.evaluate(
                    () => document.documentElement.scrollWidth <= innerWidth,
                ),
                path + " mobile overflow",
            );
        }
        assert.equal(errors.length, 0, errors.join("\n"));
        assert.equal(bad.length, 0, JSON.stringify(bad));
    });
} catch (e) {
    if (!report.failures.length)
        report.failures.push({ name: "setup", message: e.message });
} finally {
    try {
        for (const node of (await listNodes()).filter((n) =>
            String(n.name).startsWith(prefix + "-"),
        ))
            if (!owned.nodes.some((own) => nodeKey(own) === nodeKey(node)))
                owned.nodes.push({ type: node.type, id: node.id });
    } catch (e) {
        report.cleanup.push({
            kind: "node-discovery",
            pass: false,
            message: e.message,
        });
    }
    for (const n of owned.nodes)
        try {
            await api("/server/" + n.type + "/drop", { id: n.id });
            report.cleanup.push({ kind: n.type, id: n.id, pass: true });
        } catch (e) {
            report.cleanup.push({
                kind: n.type,
                id: n.id,
                pass: false,
                message: e.message,
            });
        }
    for (const id of owned.users)
        try {
            await api("/user/delUser", { id });
            report.cleanup.push({ kind: "user", id, pass: true });
        } catch (e) {
            report.cleanup.push({
                kind: "user",
                id,
                pass: false,
                message: e.message,
            });
        }
    for (const [kind, base] of [
        ["plans", "/plan"],
        ["routes", "/server/route"],
        ["notice", "/notice"],
        ["knowledge", "/knowledge"],
        ["coupon", "/coupon"],
        ["giftcard", "/giftcard"],
        ["payment", "/payment"],
    ])
        for (const id of owned[kind])
            try {
                await api(base + "/drop", { id });
                report.cleanup.push({ kind, id, pass: true });
            } catch (e) {
                report.cleanup.push({
                    kind,
                    id,
                    pass: false,
                    message: e.message,
                });
            }
    for (const id of owned.groups)
        try {
            await api("/server/group/drop", { id });
            report.cleanup.push({ kind: "group", id, pass: true });
        } catch (e) {
            report.cleanup.push({
                kind: "group",
                id,
                pass: false,
                message: e.message,
            });
        }
    await browser.close();
    if (fixture) await fixture.close();
    await writeFile(
        resolve(
            out,
            process.env.ADMIN_REPORT_NAME ??
                (live ? "live-browser.json" : "browser.json"),
        ),
        JSON.stringify(report, null, 2),
    );
}
assert.equal(report.failures.length, 0, JSON.stringify(report.failures));
assert.ok(report.cleanup.every((x) => x.pass));
console.log(
    JSON.stringify({
        kind: report.kind,
        passed: report.passed.length,
        cleanup: report.cleanup.length,
    }),
);
