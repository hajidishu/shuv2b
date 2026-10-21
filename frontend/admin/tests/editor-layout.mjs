import assert from "node:assert/strict";
import { chromium } from "playwright";
import { mkdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { startFixture } from "./fixture.mjs";

const live = !!process.env.ADMIN_LIVE_URL;
if (live && !process.env.ADMIN_LIVE_PASSWORD)
    throw new Error("ADMIN_LIVE_PASSWORD is required");
const fixture = live ? null : await startFixture({ visual: true });
const origin = process.env.ADMIN_LIVE_URL ?? fixture.origin,
    entry = live ? "/test-admin" : "/";
const output = resolve(
    import.meta.dirname,
    "../test-results/editor-layout-" + (live ? "live" : "fixture"),
);
await mkdir(output, { recursive: true });
const browser = await chromium.launch({
    executablePath:
        process.env.ADMIN_BROWSER_PATH ??
        "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe",
});
const context = await browser.newContext({
    viewport: { width: 2560, height: 1312 },
    locale: "zh-CN",
});
const page = await context.newPage(),
    prefix = "editor-regression-" + Date.now(),
    owned = [];
const report = {
    kind: live ? "live" : "fixture",
    passed: [],
    failures: [],
    cleanup: [],
    metrics: [],
};
page.on("pageerror", (error) =>
    report.failures.push({ name: "runtime", error: error.message }),
);
let token;
const api = async (path, data) => {
    const response = await context.request.fetch(
        origin + "/api/v1/test-admin" + path,
        {
            method: data ? "POST" : "GET",
            headers: { Authorization: token, Accept: "application/json" },
            ...(data ? { data } : {}),
        },
    );
    assert.ok(
        response.ok(),
        path +
            " " +
            response.status() +
            " " +
            (await response.text()).slice(0, 200),
    );
    return response.json();
};
const nav = async (path) => {
    await page.goto(origin + entry + "#" + path);
    await page.reload({ waitUntil: "networkidle" });
    await page.waitForFunction(
        () => !document.querySelector(".ant-spin-spinning"),
    );
};
const shot = (name) =>
    page.screenshot({ path: resolve(output, name + ".png"), fullPage: true });
const editor = () => page.locator(".ant-drawer:visible").last();
const close = async () => {
    await editor().getByRole("button", { name: "取消", exact: true }).click();
    await page.locator(".ant-drawer:visible").waitFor({ state: "hidden" });
};
const editRow = async (id) => {
    await page
        .locator(`tr[data-row-key="${id}"]`)
        .getByText("操作", { exact: true })
        .click();
    await page
        .locator(".ant-dropdown:visible")
        .getByText("编辑", { exact: true })
        .click();
    await editor().waitFor();
    await page.waitForTimeout(350);
};
const save = async (path) => {
    const pending = page.waitForResponse(
        (response) =>
            response.url().endsWith(path) &&
            response.request().method() === "POST",
    );
    await editor().getByRole("button", { name: /^(提交|保存)$/ }).last().click();
    const response = await pending;
    assert.ok(response.ok(), await response.text());
    await page.locator(".ant-drawer:visible").waitFor({ state: "hidden" });
    return response.request().postDataJSON();
};
const check = async (name, action) => {
    try {
        await action();
        report.passed.push(name);
        console.log("PASS " + name);
    } catch (error) {
        report.failures.push({ name, error: error.stack });
        console.log("FAIL " + name + ": " + error.message);
        await shot("failure-" + name);
        throw error;
    }
};
try {
    const login = await context.request.post(
        origin + "/api/v1/passport/auth/login",
        {
            data: {
                email: live ? "admin@v2b.test" : "admin@example.test",
                password: process.env.ADMIN_LIVE_PASSWORD ?? "fixture",
            },
        },
    );
    token = (await login.json()).data.auth_data;
    await page.goto(origin + entry + "#/login");
    await page
        .getByLabel("邮箱", { exact: true })
        .fill(live ? "admin@v2b.test" : "admin@example.test");
    await page
        .getByLabel("密码", { exact: true })
        .fill(process.env.ADMIN_LIVE_PASSWORD ?? "fixture");
    await page.getByRole("button", { name: "登录", exact: true }).click();
    await page.waitForURL("**#/dashboard");
    await check("queue-section-title-padding-and-horizon-map", async () => {
        for (const width of [2560, 1440, 390]) {
            await page.setViewportSize({ width, height: 1312 });
            await nav("/queue");
            assert.equal(
                await page.getByRole("heading", { name: "队列监控", exact: true }).count(),
                1,
            );
            const metrics = await page
                .locator(".data-section-card")
                .filter({ has: page.locator(".ant-table") })
                .evaluateAll((cards) =>
                    cards.map((card) => {
                        const head = card
                                .querySelector(".ant-card-head-title")
                                .getBoundingClientRect(),
                            table = card
                                .querySelector(".ant-table")
                                .getBoundingClientRect(),
                            box = card.getBoundingClientRect();
                        return {
                            title: card.querySelector(".ant-card-head-title")
                                .textContent,
                            inset: head.x - box.x,
                            headBottom: head.bottom,
                            tableTop: table.top,
                        };
                    }),
                );
            assert.equal(metrics.length, 3);
            assert.ok(
                metrics.some((m) => m.title.trim() === "当前作业详情"),
                "queue work table title",
            );
            assert.ok(
                metrics.every(
                    (m) =>
                        Math.abs(m.inset - 16) < 2 && m.headBottom < m.tableTop,
                ),
                JSON.stringify(metrics),
            );
            const master = page.locator(".data-section-card").filter({
                has: page.getByText("队列管理进程", { exact: true }),
            });
            assert.ok(
                (await master.locator(".ant-table-row").count()) > 0,
                "Horizon keyed JSON must show its master process",
            );
            report.metrics.push({ width, queue: metrics });
            await shot("queue-" + width);
        }
    });
    await api("/server/group/save", { name: prefix });
    const group = (await api("/server/group/fetch")).data.find(
        (g) => g.name === prefix,
    );
    owned.push({ base: "/server/group", id: group.id });
    await api("/plan/save", {
        name: prefix,
        group_id: group.id,
        transfer_enable: 100,
        device_limit: null,
        speed_limit: null,
        reset_traffic_method: 3,
        month_price: 1999,
    });
    const plan = (await api("/plan/fetch")).data.find((p) => p.name === prefix);
    owned.push({ base: "/plan", id: plan.id });
    const content =
        '# 教程标题\n\n**加粗文本** 和 {{subscribeUrl}}\n\n<div class="custom-html-style"><h2>HTML 教程</h2><p>保留原始 HTML</p></div>\n\n| 套餐 | 价格 |\n| --- | --- |\n| 示例 | 19.99 |';
    await api("/knowledge/save", {
        title: prefix,
        category: prefix,
        language: "zh-CN",
        body: content,
    });
    const knowledge = (await api("/knowledge/fetch")).data.find(
        (k) => k.title === prefix,
    );
    owned.push({ base: "/knowledge", id: knowledge.id });
    await check(
        "knowledge-original-split-editor-toolbar-and-save",
        async () => {
            await page.setViewportSize({ width: 2560, height: 1312 });
            await nav("/knowledge");
            await editRow(knowledge.id);
            assert.equal(
                await editor().locator(".ant-drawer-title").innerText(),
                "编辑知识",
            );
            const md = editor().locator(".rc-md-editor"),
                source = editor().getByRole("textbox", {
                    name: "内容",
                    exact: true,
                });
            const box = await md.boundingBox(),
                drawer = await page
                    .locator(
                        ".ant-drawer-content-wrapper:has(.knowledge-editor)",
                    )
                    .boundingBox();
            assert.ok(Math.abs(drawer.width - 2048) < 2);
            assert.ok(Math.abs(box.height - 500) < 2);
            assert.equal(await source.inputValue(), content);
            assert.equal(
                await md.locator(".sec-html h1").innerText(),
                "教程标题",
            );
            assert.equal(
                await md.locator(".sec-html h2").innerText(),
                "HTML 教程",
            );
            assert.ok(
                (await md.locator(".sec-html").innerText()).includes(
                    "{{subscribeUrl}}",
                ),
            );
            assert.ok(
                (await md.locator(".rc-md-navigation .button").count()) >= 18,
            );
            await shot("knowledge-editor-2560");
            await md.locator('[title="全屏"]').click();
            await page.locator(".rc-md-editor.full").waitFor();
            const full = await md.boundingBox();
            assert.ok(
                Math.abs(full.width - 2560) < 2 && Math.abs(full.x) < 2,
                JSON.stringify(full),
            );
            await md.locator('[title="退出全屏"]').click();
            await source.evaluate((input) => {
                const start = input.value.indexOf("加粗文本");
                input.focus();
                input.setSelectionRange(start, start + 4);
            });
            await md.locator('[title="斜体"]').click();
            assert.ok((await source.inputValue()).includes("*加粗文本*"));
            await source.fill(content);
            const payload = await save("/knowledge/save");
            assert.equal(payload.body, content);
            assert.equal(
                (await api("/knowledge/fetch?id=" + knowledge.id)).data.body,
                content,
            );
            await editRow(knowledge.id);
            assert.equal(await source.inputValue(), content);
            await editor()
                .getByRole("combobox", { name: "语言", exact: true })
                .focus();
            await editor()
                .getByRole("combobox", { name: "语言", exact: true })
                .press("ArrowDown");
            await page.locator(".ant-select-dropdown:visible").waitFor();
            for (const label of [
                "简体中文",
                "繁體中文",
                "English",
                "日本語",
                "Tiếng Việt",
                "한국어",
            ])
                assert.equal(
                    await page
                        .locator(".ant-select-dropdown:visible")
                        .getByText(label, { exact: true })
                        .count(),
                    1,
                );
            await editor().locator(".ant-drawer-title").click();
            if (!live) {
                await source.fill(
                    '<script>window.__previewUnsafe=1</script><style>body{display:none}</style><p onclick="window.__previewUnsafe=1">safe</p><a href="javascript:alert(1)">link</a>',
                );
                await md
                    .locator(".sec-html")
                    .getByText("safe", { exact: true })
                    .waitFor();
                assert.equal(
                    await md
                        .locator(
                            '.sec-html script,.sec-html style,.sec-html [onclick],.sec-html a[href^="javascript:"]',
                        )
                        .count(),
                    0,
                );
                assert.equal(
                    await page.evaluate(() => window.__previewUnsafe),
                    undefined,
                );
            }
            await close();
            await page.setViewportSize({ width: 390, height: 844 });
            await editRow(knowledge.id);
            assert.ok(
                await page.evaluate(
                    () => document.documentElement.scrollWidth <= innerWidth,
                ),
            );
            await shot("knowledge-editor-mobile");
            await close();
        },
    );
    if (!live)
        await check("knowledge-sort-uses-backend-field", async () => {
            await page.setViewportSize({ width: 1440, height: 1000 });
            await nav("/knowledge");
            await api("/knowledge/save", {
                title: prefix + "-second",
                category: prefix,
                language: "zh-CN",
                body: "sort",
            });
            const second = (await api("/knowledge/fetch")).data.find(
                (k) => k.title === prefix + "-second",
            );
            owned.push({ base: "/knowledge", id: second.id });
            await nav("/knowledge");
            const response = page.waitForResponse(
                (r) =>
                    r.url().endsWith("/knowledge/sort") &&
                    r.request().method() === "POST",
            );
            await page
                .locator(`tr[data-row-key="${second.id}"]`)
                .getByText("操作", { exact: true })
                .click();
            await page
                .locator(".ant-dropdown:visible")
                .getByText("上移", { exact: true })
                .click();
            const result = await response;
            assert.ok(result.ok(), await result.text());
            const payload = result.request().postDataJSON();
            assert.ok(Array.isArray(payload.knowledge_ids));
            assert.ok(!("ids" in payload));
        });
    await check("plan-original-price-grid-description-and-submit", async () => {
        await page.setViewportSize({ width: 2560, height: 1312 });
        await nav("/plan");
        await editRow(plan.id);
        const drawer = page.locator(
                ".ant-drawer-content-wrapper:has(.plan-editor)",
            ),
            box = await drawer.boundingBox();
        assert.equal(Math.round(box.width), 500);
        assert.equal(
            await editor().locator(".rc-md-editor,.html-preview").count(),
            0,
        );
        const prices = await editor()
            .locator(".plan-prices .form-grid")
            .first()
            .locator(".ant-form-item")
            .evaluateAll((items) =>
                items.map((item) => ({
                    x: item.getBoundingClientRect().x,
                    y: item.getBoundingClientRect().y,
                    width: item.getBoundingClientRect().width,
                })),
            );
        assert.equal(prices.length, 6);
        assert.ok(
            prices.every((p) => Math.abs(p.y - prices[0].y) < 1),
            JSON.stringify(prices),
        );
        await editor()
            .getByRole("button", { name: "添加权限组", exact: true })
            .click();
        const groupModal = page.getByRole("dialog", {
            name: "添加权限组",
            exact: true,
        });
        await groupModal
            .getByRole("textbox", { name: "权限组名称", exact: true })
            .fill(prefix + "-inline");
        const groupResponse = page.waitForResponse(
            (response) =>
                response.url().endsWith("/server/group/save") &&
                response.request().method() === "POST",
        );
        await groupModal
            .getByRole("button", { name: "添加", exact: true })
            .click();
        assert.ok((await groupResponse).ok());
        await groupModal.waitFor({ state: "hidden" });
        const addedGroup = (await api("/server/group/fetch")).data.find(
            (group) => group.name === prefix + "-inline",
        );
        assert.ok(addedGroup);
        owned.push({ base: "/server/group", id: addedGroup.id });
        const description = "<p>套餐 HTML 描述</p>";
        const resetSelector = editor()
            .locator(".ant-select")
            .filter({
                has: page.getByRole("combobox", {
                    name: "流量重置方式",
                    exact: true,
                }),
            });
        await resetSelector.hover();
        await resetSelector.locator(".ant-select-clear").click();
        await editor()
            .getByRole("textbox", { name: "订阅介绍", exact: true })
            .fill(description);
        await editor()
            .getByRole("textbox", { name: "月付价格", exact: true })
            .fill("0");
        await editor()
            .getByRole("textbox", { name: "季付价格", exact: true })
            .fill("19.99");
        await editor()
            .getByRole("textbox", { name: "年付价格", exact: true })
            .fill("");
        await shot("plan-editor-2560");
        const payload = await save("/plan/save");
        assert.equal(payload.month_price, 0);
        assert.equal(payload.quarter_price, 1999);
        assert.equal(payload.year_price, null);
        assert.equal(payload.reset_traffic_method, null);
        assert.equal(payload.content, description);
        assert.equal(payload.group_id, addedGroup.id);
        await editRow(plan.id);
        assert.equal(
            await editor()
                .getByRole("textbox", { name: "季付价格", exact: true })
                .inputValue(),
            "19.99",
        );
        await page.setViewportSize({ width: 390, height: 844 });
        assert.ok(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
        );
        await shot("plan-editor-mobile");
        await close();
    });
} finally {
    for (const item of [
        ...owned.filter((item) => item.base !== "/server/group").reverse(),
        ...owned.filter((item) => item.base === "/server/group").reverse(),
    ])
        try {
            await api(item.base + "/drop", { id: item.id });
            report.cleanup.push({ ...item, pass: true });
        } catch (error) {
            report.cleanup.push({ ...item, pass: false, error: error.message });
            process.exitCode = 1;
        }
    await browser.close();
    await fixture?.close();
    await writeFile(
        resolve(output, "report.json"),
        JSON.stringify(report, null, 2),
    );
}
if (report.failures.length) process.exitCode = 1;
