import assert from "node:assert/strict";
import { chromium } from "playwright";
import { mkdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { startFixture } from "./fixture.mjs";
import { encodeQuery } from "../src/domain.mjs";

const live = !!process.env.ADMIN_LIVE_URL;
if (live && !process.env.ADMIN_LIVE_PASSWORD)
    throw new Error("ADMIN_LIVE_PASSWORD is required");

const output = resolve(
    import.meta.dirname,
    "../test-results/user-controls-" + (live ? "live" : "fixture"),
);
await mkdir(output, { recursive: true });

const report = {
    kind: live ? "live" : "fixture",
    passed: [],
    failures: [],
    cleanup: [],
    metrics: [],
};
const prefix =
    "user-controls-" +
    Date.now() +
    "-" +
    Math.random().toString(36).slice(2, 7);
const email = prefix + "@example.com";
const password = "UserControls!" + Math.random().toString(36).slice(2, 8);
let fixture;
let browser;
let context;
let page;
let token;
let groupId;
let planId;
let userId;

const shot = (name) =>
    page?.screenshot({ path: resolve(output, name + ".png"), fullPage: true });

const api = async (path, data) => {
    const method =
        path === "/user/fetch" || data === undefined ? "GET" : "POST";
    const response = await context.request.fetch(
        origin +
            "/api/v1/test-admin" +
            path +
            (method === "GET" && data
                ? "?" + encodeQuery(data).toString()
                : ""),
        {
            method,
            headers: { Authorization: token, Accept: "application/json" },
            ...(method === "POST" && data !== undefined ? { data } : {}),
        },
    );
    assert.ok(
        response.ok(),
        path +
            " " +
            response.status() +
            " " +
            (await response.text()).slice(0, 250),
    );
    if ((response.headers()["content-type"] ?? "").includes("text/csv"))
        return response.text();
    return response.json();
};

let origin;
const entry = live ? "/test-admin" : "/";
const nav = async (route) => {
    await page.goto(origin + entry + "#" + route);
    await page.reload({ waitUntil: "networkidle" });
    await page.waitForFunction(
        () => !document.querySelector(".ant-spin-spinning"),
    );
};
const check = async (name, action) => {
    try {
        await action();
        report.passed.push(name);
        console.log("PASS " + name);
    } catch (error) {
        report.failures.push({ name, error: error.stack ?? error.message });
        console.log("FAIL " + name + ": " + error.message);
        await shot("failure-" + name);
        throw error;
    }
};
const choose = async (combo, label) => {
    const dropdown = await openSelect(combo);
    await dropdown
        .locator(".ant-select-item-option-content")
        .getByText(label, { exact: true })
        .click();
};
const openSelect = async (combo) => {
    await combo
        .locator(
            "xpath=ancestor::div[contains(concat(' ',normalize-space(@class),' '),' ant-select ')][1]",
        )
        .locator(".ant-select-selector")
        .click();
    const id = await combo.getAttribute("aria-controls");
    const dropdown = page.locator(`.ant-select-dropdown:has(#${id})`);
    await dropdown.waitFor();
    await dropdown.locator(".ant-select-item-option").first().waitFor();
    return dropdown;
};
const menuFor = (row) =>
    page.locator(`tr[data-row-key="${row}"]`).getByText("操作", {
        exact: true,
    });
const openUserMenu = async () => {
    await menuFor(userId).click();
    const menu = page.locator(".ant-dropdown:visible").last();
    await menu.locator(".ant-dropdown-menu-item").first().waitFor();
    return menu;
};
const editor = () => page.locator(".ant-drawer:visible").last();
const waitForEditorSettled = async () => {
    const panel = editor().locator(".ant-drawer-content-wrapper");
    await panel.waitFor();
    let previous;
    let stable = 0;
    let current;
    for (let attempt = 0; attempt < 30; attempt++) {
        current = await panel.evaluate((node) => {
            const rect = node.getBoundingClientRect();
            return {
                x: rect.x,
                right: rect.right,
                width: rect.width,
                opacity: Number(getComputedStyle(node).opacity),
                viewport: innerWidth,
            };
        });
        if (
            previous &&
            Math.abs(current.x - previous.x) < 0.1 &&
            Math.abs(current.width - previous.width) < 0.1 &&
            Math.abs(current.opacity - previous.opacity) < 0.001
        )
            stable++;
        else stable = 0;
        if (stable >= 3 && current.opacity > 0.99) {
            report.metrics.push({ editorLayout: current });
            return current;
        }
        previous = current;
        await page.waitForTimeout(50);
    }
    throw new Error(
        "user editor drawer did not settle: " + JSON.stringify(current),
    );
};
const closeEditor = async () => {
    await editor().getByRole("button", { name: "取消", exact: true }).click();
    await editor().waitFor({ state: "hidden" });
};
const filterDrawer = () =>
    page
        .locator(".ant-drawer-open")
        .filter({ has: page.locator(".user-filter-drawer") })
        .last();
const openFilters = async () => {
    await page.getByRole("button", { name: "过滤器", exact: true }).click();
    await filterDrawer().waitFor();
};
const addFilter = async () =>
    filterDrawer()
        .getByRole("button", { name: "添加筛选条件", exact: true })
        .click();
const filterField = (index) =>
    filterDrawer().getByRole("combobox", {
        name: "筛选字段 " + index,
        exact: true,
    });
const filterCondition = (index) =>
    filterDrawer().getByRole("combobox", {
        name: "比较条件 " + index,
        exact: true,
    });

try {
    fixture = live ? null : await startFixture({ visual: true });
    origin = process.env.ADMIN_LIVE_URL ?? fixture.origin;
    browser = await chromium.launch({
        executablePath:
            process.env.ADMIN_BROWSER_PATH ??
            "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe",
    });
    context = await browser.newContext({
        viewport: { width: 1600, height: 1100 },
        locale: "zh-CN",
    });
    page = await context.newPage();
    page.on("pageerror", (error) =>
        report.failures.push({ name: "runtime", error: error.message }),
    );

    const login = await context.request.post(
        origin + "/api/v1/passport/auth/login",
        {
            data: {
                email: live ? "admin@v2b.test" : "admin@example.test",
                password: process.env.ADMIN_LIVE_PASSWORD ?? "fixture",
            },
        },
    );
    assert.ok(login.ok(), "admin login failed: " + (await login.text()));
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

    await api("/server/group/save", { name: prefix });
    groupId = (await api("/server/group/fetch")).data.find(
        (group) => group.name === prefix,
    )?.id;
    assert.ok(groupId, "test permission group was not created");
    await api("/plan/save", {
        name: prefix,
        group_id: groupId,
        transfer_enable: 64,
        device_limit: null,
        speed_limit: null,
        reset_traffic_method: null,
        month_price: 1234,
    });
    planId = (await api("/plan/fetch")).data.find(
        (plan) => plan.name === prefix,
    )?.id;
    assert.ok(planId, "test plan was not created");
    await api("/user/generate", {
        email_prefix: prefix,
        email_suffix: "example.com",
        password,
        plan_id: planId,
    });
    const userResponse = await api("/user/fetch", {
        current: 1,
        pageSize: 20,
        filter: [{ key: "email", condition: "=", value: email }],
    });
    userId = userResponse.data.find((user) => user.email === email)?.id;
    assert.ok(userId, "isolated test user was not created");
    await api("/user/update", {
        id: userId,
        email,
        transfer_enable: 64 * 1024 ** 3,
        u: 1024 ** 3,
        d: 0,
        total_used: 1024 ** 3,
        device_limit: null,
        expired_at: null,
        plan_id: planId,
        balance: 0,
        commission_balance: 0,
        banned: 0,
        is_admin: 0,
        is_staff: 0,
        commission_type: 0,
        remarks: null,
        speed_limit: null,
    });

    await check("user-table-core-columns-and-toolbar-order", async () => {
        await nav("/user");
        const headers = await page
            .locator(".ant-table-thead tr")
            .first()
            .locator("th")
            .allInnerTexts();
        const visible = headers.map((value) => value.trim()).filter(Boolean);
        const expected = [
            "ID",
            "邮箱",
            "状态",
            "订阅",
            "权限组",
            "已用(G)",
            "流量(G)",
            "设备数",
            "到期时间",
            "余额",
            "佣金",
            "加入时间",
            "操作",
        ];
        let after = -1;
        for (const label of expected) {
            const index = visible.indexOf(label, after + 1);
            assert.ok(
                index > after,
                `${label} missing/out of order: ${visible}`,
            );
            after = index;
        }
        const row = page.locator(`tr[data-row-key="${userId}"]`).first();
        const cells = await row
            .locator("td")
            .evaluateAll((items) => items.map((item) => item.innerText.trim()));
        assert.equal(cells[visible.indexOf("已用(G)") + 1], "1.00");
        assert.equal(cells[visible.indexOf("流量(G)") + 1], "64.00");
        const toolbar = page.locator(".table-toolbar");
        const toolbarButtons = ["过滤器", "操作", "添加用户"];
        const toolbarIcons = ["filter", "select", "user-add"];
        const toolbarOrder = await toolbar
            .locator("button")
            .evaluateAll((buttons) =>
                buttons.map((button) => button.getAttribute("aria-label")),
            );
        assert.deepEqual(toolbarOrder, toolbarButtons);
        for (let index = 0; index < toolbarButtons.length; index++) {
            const button = toolbar.getByRole("button", {
                name: toolbarButtons[index],
                exact: true,
            });
            assert.equal(await button.count(), 1);
            assert.equal(
                await button.locator("svg").getAttribute("data-icon"),
                toolbarIcons[index],
            );
        }
        report.metrics.push({ headers, coreColumns: expected });
        await shot("user-table");
    });

    await check("original-table-sort-controls-use-real-columns", async () => {
        for (const [label, key] of [
            ["状态", "banned"],
            ["订阅", "plan_id"],
            ["权限组", "group_id"],
        ]) {
            const response = page.waitForResponse((response) => {
                const url = new URL(response.url());
                return (
                    url.pathname.endsWith("/user/fetch") &&
                    url.searchParams.get("sort") === key
                );
            });
            await page
                .getByRole("columnheader", { name: label, exact: true })
                .click();
            const result = await response;
            assert.ok(result.ok(), await result.text());
            assert.equal(new URL(result.url()).searchParams.get("sort"), key);
        }
        // Finish the final sorted fetch before opening a row's transient menu.
        await page.waitForLoadState("networkidle");
        await page.waitForFunction(
            () =>
                !document.querySelector(
                    ".ant-table-wrapper .ant-spin-spinning",
                ),
        );
    });
    await check("row-menu-original-order-labels-and-icons", async () => {
        const menu = await openUserMenu();
        const items = menu.locator(".ant-dropdown-menu-item");
        const labels = await items.evaluateAll((nodes) =>
            nodes.map((node) => node.innerText.trim().replace(/\s+/g, " ")),
        );
        const expected = [
            "编辑",
            "分配订单",
            "复制订阅URL",
            "重置UUID及订阅URL",
            "TA的订单",
            "TA的邀请",
            "TA的流量记录",
            "删除用户",
        ];
        assert.deepEqual(labels, expected);
        const icons = await items.evaluateAll((nodes) =>
            nodes.map((node) => node.querySelector("svg")?.dataset.icon),
        );
        assert.deepEqual(icons, [
            "edit",
            "plus",
            "copy",
            "reload",
            "account-book",
            "usergroup-add",
            "solution",
            "delete",
        ]);
        report.metrics.push({ rowMenu: labels, rowMenuIcons: icons });
        await shot("user-row-menu");
        await page.keyboard.press("Escape");
        await menuFor(userId).click({ button: "right" });
        const contextMenu = page.locator(".ant-dropdown:visible").last();
        const contextItems = contextMenu.locator(".ant-dropdown-menu-item");
        await contextItems.first().waitFor();
        assert.deepEqual(
            await contextItems.evaluateAll((nodes) =>
                nodes.map((node) => node.innerText.trim().replace(/\s+/g, " ")),
            ),
            expected,
        );
        await shot("user-row-context-menu");
        await page.keyboard.press("Escape");
        await contextMenu.waitFor({ state: "hidden" });
        const clickMenu = await openUserMenu();
        await clickMenu.getByText("编辑", { exact: true }).click();
        await editor().waitFor();
        await waitForEditorSettled();
    });

    await check("user-editor-fields-units-datetime-and-state", async () => {
        assert.ok(await page.locator(".user-editor:visible").count());
        assert.equal(
            await editor().locator(".ant-drawer-title").innerText(),
            "用户管理",
        );
        const editorLayout = await waitForEditorSettled();
        assert.ok(
            Math.abs(editorLayout.width - 500) < 2,
            `${editorLayout.width}px`,
        );
        assert.ok(
            Math.abs(editorLayout.right - editorLayout.viewport) < 2,
            JSON.stringify(editorLayout),
        );
        const labels = await editor()
            .locator(".ant-form-item")
            .evaluateAll((items) =>
                items.map((item) =>
                    item
                        .querySelector(".ant-form-item-label label")
                        ?.innerText.trim()
                        .replace(/\s+/g, " "),
                ),
            );
        assert.deepEqual(labels, [
            "邮箱",
            "邀请人邮箱",
            "密码",
            "余额",
            "推广佣金",
            "已用上行",
            "已用下行",
            "流量",
            "设备数限制",
            "到期时间",
            "订阅计划",
            "账户状态",
            "推荐返利类型",
            "推荐返利比例",
            "专享折扣比例",
            "限速",
            "是否管理员",
            "是否员工",
            "备注",
        ]);
        const balance = editor().getByLabel("余额", { exact: true });
        const commission = editor().getByLabel("佣金余额", { exact: true });
        const upload = editor().getByLabel("已用上行", { exact: true });
        const download = editor().getByLabel("已用下行", { exact: true });
        const itemBox = async (control) =>
            control
                .locator(
                    "xpath=ancestor::div[contains(concat(' ',normalize-space(@class),' '),' ant-form-item ')]",
                )
                .boundingBox();
        const [balanceBox, commissionBox, uploadBox, downloadBox] =
            await Promise.all([
                itemBox(balance),
                itemBox(commission),
                itemBox(upload),
                itemBox(download),
            ]);
        assert.ok(Math.abs(balanceBox.y - commissionBox.y) < 2);
        assert.ok(Math.abs(uploadBox.y - downloadBox.y) < 2);
        assert.notEqual(balanceBox.x, commissionBox.x);
        assert.notEqual(uploadBox.x, downloadBox.x);
        assert.equal(
            await editor()
                .locator(".ant-input-group-addon")
                .filter({ hasText: "¥" })
                .count(),
            2,
        );
        assert.equal(
            await editor()
                .locator(".ant-input-number-group-addon")
                .filter({ hasText: "GB" })
                .count(),
            3,
        );
        const passwordInput = editor().getByLabel("密码", { exact: true });
        assert.equal(await passwordInput.inputValue(), "");
        const status = editor().getByRole("combobox", {
            name: "账户状态",
            exact: true,
        });
        const statusOptions = await openSelect(status);
        await statusOptions
            .locator(".ant-select-item-option")
            .first()
            .waitFor();
        assert.equal(
            await statusOptions
                .locator(".ant-select-item-option-content")
                .getByText("正常", { exact: true })
                .count(),
            1,
        );
        assert.equal(
            await statusOptions
                .locator(".ant-select-item-option-content")
                .getByText("封禁", { exact: true })
                .count(),
            1,
        );
        await page.keyboard.press("Escape");
        await statusOptions.waitFor({ state: "hidden" });
        const plan = editor().getByRole("combobox", {
            name: "订阅计划",
            exact: true,
        });
        const planOptions = await openSelect(plan);
        await planOptions.locator(".ant-select-item-option").first().waitFor();
        assert.equal(
            await planOptions
                .locator(".ant-select-item-option-content")
                .getByText("无", { exact: true })
                .count(),
            1,
        );
        assert.equal(
            await planOptions
                .locator(".ant-select-item-option-content")
                .getByText(prefix, { exact: true })
                .count(),
            1,
        );
        await page.keyboard.press("Escape");
        const expired = editor().getByRole("textbox", {
            name: "到期时间",
            exact: true,
        });
        await expired.click();
        await page.locator(".ant-picker-dropdown:visible").waitFor();
        assert.ok(
            (await page.locator(".ant-picker-dropdown:visible").count()) > 0,
        );
        assert.ok(
            (await page.locator(".ant-picker-time-panel:visible").count()) > 0,
            "expiry date picker must include time controls",
        );
        await editor().locator(".ant-drawer-title").click();
        await page.locator(".ant-picker-dropdown:visible").waitFor({
            state: "hidden",
        });
        assert.equal(
            await editor()
                .getByRole("button", { name: "取消", exact: true })
                .count(),
            1,
        );
        assert.equal(
            await editor()
                .getByRole("button", { name: "保存", exact: true })
                .count(),
            1,
        );
        report.metrics.push({ editFields: labels });
        await shot("user-editor");
    });

    await check(
        "commission-values-and-blank-password-preservation",
        async () => {
            assert.equal(
                await editor().getByLabel("密码", { exact: true }).inputValue(),
                "",
            );
            await editor().getByLabel("余额", { exact: true }).fill("1.23");
            await choose(
                editor().getByRole("combobox", {
                    name: "推荐返利类型",
                    exact: true,
                }),
                "循环返利",
            );
            let pending = page.waitForResponse(
                (response) =>
                    response.url().endsWith("/user/update") &&
                    response.request().method() === "POST",
            );
            await editor()
                .getByRole("button", { name: "保存", exact: true })
                .click();
            let response = await pending;
            assert.ok(response.ok(), await response.text());
            let payload = response.request().postDataJSON();
            assert.equal(payload.balance, 123);
            assert.equal(payload.commission_type, 1);
            assert.ok(
                !("password" in payload),
                "blank password must be omitted",
            );

            await openUserMenu();
            await page
                .locator(".ant-dropdown:visible")
                .last()
                .getByText("编辑", { exact: true })
                .click();
            await editor().waitFor();
            assert.equal(
                await editor().getByLabel("密码", { exact: true }).inputValue(),
                "",
                "API password hash must never be placed in the password field",
            );
            await choose(
                editor().getByRole("combobox", {
                    name: "推荐返利类型",
                    exact: true,
                }),
                "首次返利",
            );
            pending = page.waitForResponse(
                (candidate) =>
                    candidate.url().endsWith("/user/update") &&
                    candidate.request().method() === "POST",
            );
            await editor()
                .getByRole("button", { name: "保存", exact: true })
                .click();
            response = await pending;
            assert.ok(response.ok(), await response.text());
            payload = response.request().postDataJSON();
            assert.equal(payload.commission_type, 2);
            assert.ok(!("password" in payload));
            await shot("user-editor-saved");

            if (live) {
                const userLogin = await context.request.post(
                    origin + "/api/v1/passport/auth/login",
                    { data: { email, password } },
                );
                assert.ok(
                    userLogin.ok() && (await userLogin.json()).data?.auth_data,
                    "existing test-user password must still authenticate after blank-password edit",
                );
            }
        },
    );

    await check(
        "create-user-modal-fields-and-exclusive-email-mode",
        async () => {
            await page
                .getByRole("button", { name: "添加用户", exact: true })
                .click();
            const modal = page.getByRole("dialog", {
                name: "创建用户",
                exact: true,
            });
            await modal.waitFor();
            assert.ok(
                await page.locator(".user-generate-modal:visible").count(),
            );
            assert.equal(
                await modal.locator(".ant-modal-title").innerText(),
                "创建用户",
            );
            const labels = await modal
                .locator(".ant-form-item-label label")
                .evaluateAll((items) =>
                    items.map((item) => item.innerText.trim()),
                );
            assert.deepEqual(labels, [
                "邮箱",
                "密码",
                "到期时间",
                "订阅计划",
                "生成数量",
            ]);
            const prefixInput = modal.getByLabel("邮箱前缀", { exact: true });
            const suffixInput = modal.getByLabel("邮箱后缀", { exact: true });
            const separator = modal.getByLabel("邮箱分隔符", { exact: true });
            assert.equal(await separator.inputValue(), "@");
            assert.equal(await separator.isDisabled(), true);
            assert.equal(
                await prefixInput.evaluate((node) => node.style.width),
                "45%",
            );
            assert.equal(
                await separator.evaluate((node) => node.style.width),
                "10%",
            );
            assert.equal(
                await suffixInput.evaluate((node) => node.style.width),
                "45%",
            );
            await prefixInput.fill(prefix + "-single");
            assert.equal(
                await modal.getByLabel("生成数量", { exact: true }).count(),
                0,
                "entering a prefix hides batch-count input",
            );
            await prefixInput.fill("");
            const count = modal.getByLabel("生成数量", { exact: true });
            await count.fill("2");
            assert.equal(
                await modal.getByLabel("邮箱前缀", { exact: true }).count(),
                0,
                "entering a batch count hides single-prefix input",
            );
            report.metrics.push({ createFields: labels });
            await shot("user-create-modal");
            await modal
                .getByRole("button", { name: "取消", exact: true })
                .click();
        },
    );

    await check(
        "mail-modal-recipient-subject-and-12-row-textarea",
        async () => {
            await page
                .getByRole("button", { name: "操作", exact: true })
                .click();
            const actionMenu = page.locator(".ant-dropdown:visible").last();
            await actionMenu
                .locator(".ant-dropdown-menu-item")
                .first()
                .waitFor();
            const actionLabels = await actionMenu
                .locator(".ant-dropdown-menu-item")
                .evaluateAll((items) =>
                    items.map((item) => item.innerText.trim()),
                );
            assert.deepEqual(actionLabels, [
                "导出CSV",
                "发送邮件",
                "批量封禁",
                "批量删除",
            ]);
            const actionIcons = await actionMenu
                .locator(".ant-dropdown-menu-item")
                .evaluateAll((items) =>
                    items.map(
                        (item) => item.querySelector("svg")?.dataset.icon,
                    ),
                );
            assert.deepEqual(actionIcons, [
                "file-excel",
                "mail",
                "stop",
                "delete",
            ]);
            await actionMenu.getByText("发送邮件", { exact: true }).click();
            const modal = page.getByRole("dialog", {
                name: "发送邮件",
                exact: true,
            });
            await modal.waitFor();
            assert.ok(await page.locator(".user-mail-modal:visible").count());
            const recipient = modal.getByLabel("收件人", { exact: true });
            assert.equal(await recipient.isDisabled(), true);
            assert.equal(await recipient.inputValue(), "全部用户");
            assert.equal(
                await modal
                    .getByLabel("主题", { exact: true })
                    .getAttribute("placeholder"),
                "请输入邮件主题",
            );
            const content = modal.getByLabel("内容", { exact: true });
            assert.equal(await content.getAttribute("rows"), "12");
            assert.equal(
                await content.getAttribute("placeholder"),
                "请输入邮件内容",
            );
            report.metrics.push({ actionMenu: actionLabels, mailRows: 12 });
            await shot("user-mail-modal");
            await modal
                .getByRole("button", { name: "取消", exact: true })
                .click();
        },
    );

    await check("filter-drawer-plan-state-date-money-and-zero", async () => {
        await openFilters();
        await addFilter();
        const emailOptions = await openSelect(filterCondition(1));
        assert.equal(
            (
                await emailOptions
                    .locator(".ant-select-item-option-content")
                    .allInnerTexts()
            )[0],
            "模糊",
        );
        await filterDrawer().locator(".ant-drawer-title").click();
        await choose(filterField(1), "订阅");
        const planValue = filterDrawer().getByRole("combobox", {
            name: "筛选值 1",
            exact: true,
        });
        let options = await openSelect(planValue);
        await options
            .locator(".ant-select-item-option-content")
            .getByText("无订阅", { exact: true })
            .waitFor();
        await options
            .locator(".ant-select-item-option-content")
            .getByText(prefix, { exact: true })
            .click();
        await addFilter();
        await choose(filterField(2), "账号状态");
        await choose(
            filterDrawer().getByRole("combobox", {
                name: "筛选值 2",
                exact: true,
            }),
            "正常",
        );
        assert.ok(
            await filterDrawer()
                .locator(".ant-select-selection-item")
                .filter({ hasText: "正常" })
                .count(),
        );
        const zeroRequest = page.waitForRequest(
            (request) =>
                new URL(request.url()).pathname.endsWith("/user/fetch") &&
                new URL(request.url()).searchParams.get("filter[1][value]") ===
                    "0",
        );
        await filterDrawer()
            .getByRole("button", { name: "筛选用户", exact: true })
            .click();
        assert.equal(
            new URL((await zeroRequest).url()).searchParams.get(
                "filter[1][value]",
            ),
            "0",
        );
        await filterDrawer().waitFor({ state: "hidden" });
        await openFilters();
        await addFilter();
        await choose(filterField(3), "到期时间");
        const date = filterDrawer().getByRole("textbox", {
            name: "筛选值 3",
            exact: true,
        });
        await date.click();
        await page.locator(".ant-picker-dropdown:visible").waitFor();
        await page.locator(".ant-picker-time-panel:visible").waitFor();
        await filterDrawer().locator(".ant-drawer-title").click();
        await page
            .locator(".ant-picker-dropdown:visible")
            .waitFor({ state: "hidden" });
        options = await openSelect(filterCondition(3));
        const operators = await options
            .locator(".ant-select-item-option-content")
            .allInnerTexts();
        for (const op of [">=", ">", "<", "<="])
            assert.ok(operators.includes(op));
        await filterDrawer().locator(".ant-drawer-title").click();
        await filterDrawer()
            .getByRole("button", { name: "重置筛选", exact: true })
            .click();
        await filterDrawer().waitFor({ state: "hidden" });
        await openFilters();
        await addFilter();
        await choose(filterField(1), "余额");
        await filterDrawer()
            .getByRole("textbox", { name: "筛选值 1", exact: true })
            .fill("1.23");
        await shot("user-filter-drawer");
        const moneyRequest = page.waitForRequest(
            (request) =>
                new URL(request.url()).pathname.endsWith("/user/fetch") &&
                new URL(request.url()).searchParams.get("filter[0][value]") ===
                    "123",
        );
        await filterDrawer()
            .getByRole("button", { name: "筛选用户", exact: true })
            .click();
        assert.equal(
            new URL((await moneyRequest).url()).searchParams.get(
                "filter[0][value]",
            ),
            "123",
        );
        report.metrics.push({
            filterHasPlanSelect: true,
            filterHasStateSelect: true,
            zeroPreserved: true,
            datePicker: true,
            moneyCents: 123,
        });
    });
} catch (error) {
    if (!report.failures.length)
        report.failures.push({
            name: "uncaught",
            error: error.stack ?? error.message,
        });
    process.exitCode = 1;
} finally {
    if (context && token) {
        if (!userId) {
            try {
                const found = await api("/user/fetch", {
                    current: 1,
                    pageSize: 20,
                    filter: [{ key: "email", condition: "=", value: email }],
                });
                userId = found.data.find((user) => user.email === email)?.id;
            } catch (error) {
                report.cleanup.push({
                    step: "locate-test-user",
                    pass: false,
                    error: error.message,
                });
            }
        }
        for (const [kind, id, path] of [
            ["user", userId, "/user/delUser"],
            ["plan", planId, "/plan/drop"],
            ["group", groupId, "/server/group/drop"],
        ]) {
            if (!id) continue;
            try {
                await api(path, { id });
                report.cleanup.push({ kind, id, pass: true });
            } catch (error) {
                report.cleanup.push({
                    kind,
                    id,
                    pass: false,
                    error: error.message,
                });
                process.exitCode = 1;
            }
        }
    }
    await context?.close();
    await browser?.close();
    await fixture?.close();
    await writeFile(
        resolve(output, "report.json"),
        JSON.stringify(report, null, 2),
    );
}

if (report.failures.length) process.exitCode = 1;
