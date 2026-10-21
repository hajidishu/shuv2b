import assert from "node:assert/strict";
import { chromium } from "playwright";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { startFixture } from "./fixture.mjs";
import { encodeQuery } from "../src/domain.mjs";

const live = !!process.env.ADMIN_LIVE_URL;
if (
    live &&
    (!process.env.ADMIN_LIVE_PASSWORD || !process.env.ADMIN_NODE_AUTH_FILE)
)
    throw new Error(
        "Live checks require ADMIN_LIVE_PASSWORD and ADMIN_NODE_AUTH_FILE",
    );
const fixture = live ? null : await startFixture({ visual: true });
const origin = process.env.ADMIN_LIVE_URL ?? fixture.origin,
    entry = live ? "/test-admin" : "/";
const out = resolve(
    import.meta.dirname,
    "../test-results/leak-probe-" + (live ? "live" : "fixture"),
);
await mkdir(out, { recursive: true });
const prefix = "leak-check-" + Date.now(),
    password = "LeakProbeTest!2026";
const report = {
    kind: live ? "live" : "fixture",
    passed: [],
    failures: [],
    cleanup: [],
};
const owned = { users: [], nodes: [], pool: [] };
let browser,
    context,
    page,
    token,
    groupId,
    nodeGroupId,
    planId,
    taskId,
    rootRound,
    nodeAuth;
const api = async (path, data, method = "GET") => {
    const response = await context.request.fetch(
        origin +
            "/api/v1/test-admin" +
            path +
            (method === "GET" && data ? "?" + encodeQuery(data) : ""),
        {
            method,
            headers: { Authorization: token, Accept: "application/json" },
            ...(method === "POST" ? { data } : {}),
        },
    );
    assert.ok(response.ok(), path + " HTTP " + response.status());
    return response.json();
};
const check = async (name, fn) => {
    try {
        await fn();
        report.passed.push(name);
        console.log("PASS " + name);
    } catch (error) {
        report.failures.push({ name, error: error.stack ?? error.message });
        await page?.screenshot({
            path: resolve(out, "failure-" + name + ".png"),
            fullPage: true,
        });
        throw error;
    }
};
const choose = async (combo, name) => {
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
        .getByText(name, { exact: true })
        .click();
};
const ownedNodeLabel = (node) =>
    `${node.name} · vmess #${node.id} · ${node.host}`;
const confirm = async () => {
    const dialog = page.locator(".ant-modal-confirm:visible").last();
    await dialog.getByRole("button", { name: /确.*定/ }).click();
    await dialog.waitFor({ state: "hidden" });
};
const details = () => api("/leak-probe/detail", { id: taskId });
const taskDrawer = () => page.locator(".leak-task-drawer");
const userInfo = async (user) =>
    (await api("/user/getUserInfoById", { id: user.id })).data;
const nodeUsers = async (node) => {
    const response = await context.request.post(
        origin + "/api/v1/server/UniProxy/user",
        {
            data: {
                token: nodeAuth.token,
                node_type: "vmess",
                node_id: node.id,
            },
        },
    );
    assert.ok(response.ok(), "Node user endpoint HTTP " + response.status());
    return (await response.json()).users
        .map((user) => user.id)
        .sort((a, b) => a - b);
};
const createTaskThroughUi = async (name) => {
    await page
        .getByRole("button", { name: "新建排查任务", exact: true })
        .click();
    const modal = page.getByRole("dialog");
    await modal.getByLabel("任务名称", { exact: true }).fill(name);
    await choose(
        modal.getByRole("combobox", { name: "参与范围", exact: true }),
        "邮箱清单",
    );
    await modal
        .getByLabel("用户邮箱清单", { exact: true })
        .fill(owned.users.map((user) => user.email).join("\n"));
    await choose(
        modal.getByRole("combobox", { name: "A组专用节点" }),
        ownedNodeLabel(owned.nodes[0]),
    );
    await choose(
        modal.getByRole("combobox", { name: "B组专用节点" }),
        ownedNodeLabel(owned.nodes[1]),
    );
    await modal.getByRole("button", { name: "预览参与名单" }).click();
    await modal.getByText("匹配 4 人（允许2至10000人）").waitFor();
    const created = page.waitForResponse((response) =>
        response.url().endsWith("/leak-probe/create"),
    );
    await modal
        .getByRole("button", { name: "创建并开始", exact: true })
        .click();
    const response = await created;
    assert.ok(response.ok(), "Create task HTTP " + response.status());
    taskId = (await response.json()).data.id;
    await taskDrawer().waitFor();
    await page.waitForLoadState("networkidle");
    return details();
};
try {
    browser = await chromium.launch({
        executablePath:
            process.env.ADMIN_BROWSER_PATH ??
            "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe",
    });
    context = await browser.newContext({
        viewport: { width: 1440, height: 1000 },
        locale: "zh-CN",
    });
    page = await context.newPage();
    page.on("pageerror", (error) =>
        report.failures.push({ name: "runtime", error: error.message }),
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
    if (live)
        nodeAuth = JSON.parse(
            await readFile(process.env.ADMIN_NODE_AUTH_FILE, "utf8"),
        );
    await api("/server/group/save", { name: prefix }, "POST");
    groupId = (await api("/server/group/fetch")).data.find(
        (group) => group.name === prefix,
    ).id;
    await api("/server/group/save", { name: prefix + "-dedicated" }, "POST");
    nodeGroupId = (await api("/server/group/fetch")).data.find(
        (group) => group.name === prefix + "-dedicated",
    ).id;
    assert.notEqual(groupId, nodeGroupId);
    await api(
        "/plan/save",
        {
            name: prefix,
            group_id: groupId,
            transfer_enable: 10,
            show: 0,
            renew: 1,
            month_price: 100,
            reset_traffic_method: 2,
        },
        "POST",
    );
    planId = (await api("/plan/fetch")).data.find(
        (plan) => plan.name === prefix,
    ).id;
    for (let i = 0; i < 4; i++) {
        const email = prefix + "-" + i + "@example.com";
        await api(
            "/user/generate",
            {
                email_prefix: prefix + "-" + i,
                email_suffix: "example.com",
                password,
                plan_id: planId,
                expired_at: Math.floor(Date.now() / 1000) + 86400,
            },
            "POST",
        );
        const user = (
            await api("/user/fetch", {
                filter: [{ key: "email", condition: "=", value: email }],
            })
        ).data.find((user) => user.email === email);
        owned.users.push(user);
        await api(
            "/user/update",
            {
                id: user.id,
                email,
                is_admin: 0,
                is_staff: 0,
                banned: 0,
                plan_id: planId,
                transfer_enable: 10 * 1024 ** 3,
                balance: 1999,
            },
            "POST",
        );
        if (i >= 2) continue;
        await api(
            "/server/vmess/save",
            {
                name: prefix + "-node-" + i,
                group_id: [nodeGroupId],
                host: "203.0.113." + (81 + i),
                port: "443",
                server_port: 443,
                tls: 0,
                network: "tcp",
                rate: 1,
            },
            "POST",
        );
        const node = (await api("/server/manage/getNodes")).data.find(
            (node) => node.name === prefix + "-node-" + i,
        );
        owned.nodes.push(node);
        await api("/server/vmess/update", { id: node.id, show: 1 }, "POST");
    }
    await page.goto(origin + entry + "#/leak-probe");
    await page.reload({ waitUntil: "networkidle" });
    await check("register-isolated-node-pool-through-ui", async () => {
        await page
            .getByRole("button", { name: "专用节点池", exact: true })
            .click();
        const drawer = page.locator(".leak-pool-drawer");
        for (const node of owned.nodes) {
            await choose(
                drawer.getByRole("combobox", { name: "选择登记节点" }),
                `${node.name} · vmess #${node.id} · ${node.host}`,
            );
            await drawer
                .getByRole("button", { name: "登记专用节点", exact: true })
                .click();
            await confirm();
            await page.waitForLoadState("networkidle");
        }
        owned.pool = (await api("/leak-probe/nodes")).data.filter((node) =>
            owned.nodes.some((server) => server.id === node.node_id),
        );
        assert.equal(owned.pool.length, 2);
        await drawer.locator(".ant-drawer-close").click();
        await drawer.waitFor({ state: "hidden" });
        if (live) assert.deepEqual(await nodeUsers(owned.nodes[0]), []);
    });
    await check("preview-and-create-fixed-roster-through-ui", async () => {
        const detail = await createTaskThroughUi(prefix);
        rootRound = detail.rounds[0];
        assert.equal(rootRound.a_count, 2);
        assert.equal(rootRound.b_count, 2);
        await page.screenshot({
            path: resolve(out, "probe-first-round.png"),
            fullPage: true,
        });
    });
    if (live)
        await check(
            "actual-subscriptions-user-api-and-node-authorization-stay-in-their-groups",
            async () => {
                const roster = (
                    await api("/leak-probe/members", {
                        round_id: rootRound.id,
                        pageSize: 100,
                    })
                ).data;
                for (const branch of ["a", "b"]) {
                    const expected = roster
                        .filter((member) => member.branch === branch)
                        .map((member) => member.user_id)
                        .sort((a, b) => a - b);
                    const snapshot = rootRound[branch + "_snapshot"];
                    const node = owned.nodes.find(
                        (node) => node.id === snapshot.node_id,
                    );
                    assert.deepEqual(await nodeUsers(node), expected);
                }
                for (const user of owned.users) {
                    const info = await userInfo(user),
                        member = roster.find(
                            (member) => member.user_id === user.id,
                        );
                    const expected = rootRound[member.branch + "_snapshot"],
                        other =
                            rootRound[
                                (member.branch === "a" ? "b" : "a") +
                                    "_snapshot"
                            ];
                    const response = await context.request.get(
                        origin +
                            "/api/v1/client/subscribe?" +
                            new URLSearchParams({
                                token: info.token,
                                flag: "clash.meta",
                            }),
                        { headers: { "User-Agent": "Clash.Meta " + prefix } },
                    );
                    assert.ok(response.ok());
                    const body = await response.text();
                    assert.ok(body.includes(expected.host));
                    assert.ok(!body.includes(other.host));
                    assert.ok(!body.includes("_leak_probe"));
                    const accountLogin = await context.request.post(
                        origin + "/api/v1/passport/auth/login",
                        { data: { email: user.email, password } },
                    );
                    const auth = (await accountLogin.json()).data.auth_data;
                    const nodeList = await context.request.get(
                        origin + "/api/v1/user/server/fetch",
                        { headers: { Authorization: auth } },
                    );
                    assert.ok(nodeList.ok());
                    const payload = await nodeList.json();
                    assert.deepEqual(
                        payload.data.map((node) => node.id),
                        [expected.node_id],
                    );
                    assert.ok(!JSON.stringify(payload).includes("_leak_probe"));
                }
                for (let i = 0; i < 30; i++) {
                    const round = (await details()).rounds[0];
                    if (round.a_seen + round.b_seen === 4) break;
                    await page.waitForTimeout(500);
                }
                const measured = (await details()).rounds[0];
                assert.equal(measured.a_seen + measured.b_seen, 4);
                await taskDrawer()
                    .getByRole("button", { name: "刷新任务详情" })
                    .click();
                await page.waitForLoadState("networkidle");
            },
        );
    if (live)
        await check(
            "role-or-plan-changes-revoke-dedicated-node-authorization",
            async () => {
                const user = owned.users[0];
                const member = (
                    await api("/leak-probe/members", {
                        round_id: rootRound.id,
                        pageSize: 100,
                    })
                ).data.find((member) => member.user_id === user.id);
                const node = owned.nodes.find(
                    (node) =>
                        node.id ===
                        rootRound[member.branch + "_snapshot"].node_id,
                );
                const update = (values) =>
                    api(
                        "/user/update",
                        {
                            id: user.id,
                            email: user.email,
                            banned: 0,
                            is_staff: 0,
                            is_admin: 0,
                            plan_id: planId,
                            ...values,
                        },
                        "POST",
                    );
                try {
                    await update({ is_admin: 1 });
                    assert.ok(!(await nodeUsers(node)).includes(user.id));
                    await update({ plan_id: null });
                    assert.ok(!(await nodeUsers(node)).includes(user.id));
                } finally {
                    await update({});
                }
                assert.ok((await nodeUsers(node)).includes(user.id));
            },
        );
    await check("pause-resume-and-human-results", async () => {
        await taskDrawer()
            .getByRole("button", { name: /暂.*停.*任.*务/ })
            .click();
        await confirm();
        assert.equal((await details()).data.status, "paused");
        if (live)
            assert.deepEqual(
                await nodeUsers(
                    owned.nodes.find(
                        (node) => node.id === rootRound.a_snapshot.node_id,
                    ),
                ),
                [],
            );
        await taskDrawer()
            .getByRole("button", { name: /恢.*复.*任.*务/ })
            .click();
        await confirm();
        await choose(
            taskDrawer().getByRole("combobox", { name: "A组人工检测结果" }),
            "人工确认异常",
        );
        await choose(
            taskDrawer().getByRole("combobox", { name: "B组人工检测结果" }),
            "正常",
        );
        await taskDrawer()
            .getByLabel("检测依据/备注", { exact: true })
            .fill("自动回归模拟人工结果，不代表真实封锁");
        await taskDrawer()
            .getByRole("button", { name: "保存人工结果" })
            .click();
        await page.waitForLoadState("networkidle");
        assert.equal((await details()).rounds[0].result_a, "abnormal");
    });
    await check(
        "advance-reuses-the-same-two-nodes-and-keeps-history",
        async () => {
            await taskDrawer()
                .getByRole("button", { name: "推进下一轮" })
                .click();
            const modal = page.getByRole("dialog", { name: "选择分支并推进" });
            await choose(
                modal.getByRole("combobox", { name: "下一轮A组节点" }),
                ownedNodeLabel(owned.nodes[0]),
            );
            await choose(
                modal.getByRole("combobox", { name: "下一轮B组节点" }),
                ownedNodeLabel(owned.nodes[1]),
            );
            await modal
                .getByRole("button", { name: "确认推进", exact: true })
                .click();
            await modal.waitFor({ state: "hidden" });
            await page.waitForLoadState("networkidle");
            const detail = await details();
            assert.equal(detail.rounds.length, 2);
            assert.equal(detail.rounds[1].a_count, 1);
            assert.equal(detail.rounds[1].b_count, 1);
            assert.deepEqual(
                [detail.rounds[1].a_node_id, detail.rounds[1].b_node_id].sort(),
                [rootRound.a_node_id, rootRound.b_node_id].sort(),
            );
            const selected = await taskDrawer()
                .getByRole("combobox", { name: "A组人工检测结果" })
                .locator(
                    "xpath=ancestor::div[contains(concat(' ',normalize-space(@class),' '),' ant-select ')][1]",
                )
                .locator(".ant-select-selection-item")
                .innerText();
            assert.ok(selected.includes("未确认"));
            if (live) {
                const members = (
                    await api("/leak-probe/members", {
                        round_id: detail.rounds[1].id,
                        pageSize: 100,
                    })
                ).data;
                for (const branch of ["a", "b"]) {
                    const node = owned.nodes.find(
                        (node) =>
                            node.id ===
                            detail.rounds[1][branch + "_snapshot"].node_id,
                    );
                    assert.deepEqual(
                        await nodeUsers(node),
                        members
                            .filter((member) => member.branch === branch)
                            .map((member) => member.user_id),
                    );
                }
            }
            await page.screenshot({
                path: resolve(out, "probe-second-round.png"),
                fullPage: true,
            });
        },
    );
    await check("single-candidate-finish-never-bans-user", async () => {
        await choose(
            taskDrawer().getByRole("combobox", { name: "A组人工检测结果" }),
            "人工确认异常",
        );
        await choose(
            taskDrawer().getByRole("combobox", { name: "B组人工检测结果" }),
            "正常",
        );
        await taskDrawer()
            .getByRole("button", { name: "保存人工结果" })
            .click();
        await page.waitForLoadState("networkidle");
        await taskDrawer().getByRole("button", { name: "推进下一轮" }).click();
        await page
            .getByRole("dialog", { name: "选择分支并推进" })
            .getByRole("button", { name: "确认推进", exact: true })
            .click();
        await page
            .getByRole("dialog", { name: "选择分支并推进" })
            .waitFor({ state: "hidden" });
        assert.equal((await details()).data.status, "identified");
        for (const user of owned.users) {
            const info = await userInfo(user);
            assert.equal(Number(info.banned), 0);
            assert.equal(Number(info.balance), 1999);
        }
        await page.screenshot({
            path: resolve(out, "probe-candidate.png"),
            fullPage: true,
        });
    });
    await check("finished-task-releases-nodes-for-a-new-task", async () => {
        const previousTaskId = taskId;
        assert.equal((await details()).data.status, "identified");
        const reusable = (await api("/leak-probe/nodes")).data.filter((node) =>
            owned.pool.some((item) => item.id === node.id),
        );
        assert.equal(reusable.length, 2);
        assert.ok(reusable.every((node) => node.state === "ready"));
        await taskDrawer().locator(".ant-drawer-close").click();
        await taskDrawer().waitFor({ state: "hidden" });
        const nextTask = await createTaskThroughUi(prefix + "-reuse");
        assert.notEqual(taskId, previousTaskId);
        assert.equal(
            nextTask.rounds[0].a_count + nextTask.rounds[0].b_count,
            4,
        );
        assert.deepEqual(
            [nextTask.rounds[0].a_node_id, nextTask.rounds[0].b_node_id].sort(),
            [rootRound.a_node_id, rootRound.b_node_id].sort(),
        );
        assert.equal(
            (await api("/leak-probe/detail", { id: previousTaskId })).rounds
                .length,
            2,
        );
        if (live) {
            const allowed = [
                ...(await nodeUsers(owned.nodes[0])),
                ...(await nodeUsers(owned.nodes[1])),
            ].sort((a, b) => a - b);
            assert.deepEqual(
                allowed,
                owned.users.map((user) => user.id).sort((a, b) => a - b),
            );
        }
        await taskDrawer()
            .getByRole("button", { name: /结.*束.*任.*务/ })
            .click();
        await confirm();
        assert.equal((await details()).data.status, "completed");
        const finished = (await api("/leak-probe/nodes")).data.filter((node) =>
            owned.pool.some((item) => item.id === node.id),
        );
        assert.ok(finished.every((node) => node.state === "ready"));
        if (live)
            for (const node of owned.nodes)
                assert.deepEqual(await nodeUsers(node), []);
    });
    await check("mobile-task-page-and-no-runtime-errors", async () => {
        await taskDrawer().locator(".ant-drawer-close").click();
        await taskDrawer().waitFor({ state: "hidden" });
        await page.setViewportSize({ width: 390, height: 844 });
        assert.ok(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth + 1,
            ),
        );
        await page.screenshot({
            path: resolve(out, "probe-mobile.png"),
            fullPage: true,
        });
    });
    if (live)
        await check("non-admin-cannot-create-or-inspect-probes", async () => {
            const login = await context.request.post(
                origin + "/api/v1/passport/auth/login",
                { data: { email: owned.users[0].email, password } },
            );
            const ordinary = (await login.json()).data.auth_data;
            for (const path of ["/fetch", "/nodes", "/detail?id=" + taskId]) {
                const response = await context.request.get(
                    origin + "/api/v1/test-admin/leak-probe" + path,
                    { headers: { Authorization: ordinary } },
                );
                assert.equal(response.status(), 403);
            }
            const denied = await context.request.post(
                origin + "/api/v1/test-admin/leak-probe/update",
                {
                    headers: { Authorization: ordinary },
                    data: { id: taskId, action: "finish" },
                },
            );
            assert.equal(denied.status(), 403);
        });
} catch (error) {
    if (!report.failures.length)
        report.failures.push({
            name: "setup",
            error: error.stack ?? error.message,
        });
    process.exitCode = 1;
} finally {
    const cleanup = async (kind, id, path, data) => {
        try {
            await api(path, data, "POST");
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
    };
    if (taskId) {
        try {
            const task = (await details()).data;
            if (["active", "paused"].includes(task.status))
                await cleanup("finish-task", taskId, "/leak-probe/update", {
                    id: taskId,
                    round_id: task.current_round_id,
                    action: "finish",
                    note: "回归清理",
                });
        } catch (error) {
            report.cleanup.push({
                kind: "task",
                pass: false,
                error: error.message,
            });
            process.exitCode = 1;
        }
    }
    for (const user of owned.users)
        await cleanup("user", user.id, "/user/delUser", { id: user.id });
    if (context && token) {
        try {
            owned.pool = (await api("/leak-probe/nodes")).data.filter((node) =>
                owned.nodes.some(
                    (server) =>
                        server.id === node.node_id &&
                        node.node_type === "vmess",
                ),
            );
        } catch {}
    }
    for (const node of owned.pool)
        await cleanup("pool", node.id, "/leak-probe/nodes", {
            action: "remove",
            id: node.id,
        });
    for (const node of owned.nodes)
        await cleanup("node", node.id, "/server/vmess/drop", { id: node.id });
    if (planId) await cleanup("plan", planId, "/plan/drop", { id: planId });
    if (groupId)
        await cleanup("group", groupId, "/server/group/drop", { id: groupId });
    if (nodeGroupId)
        await cleanup("group", nodeGroupId, "/server/group/drop", {
            id: nodeGroupId,
        });
    await browser?.close();
    await fixture?.close();
    await writeFile(
        resolve(out, "report.json"),
        JSON.stringify(report, null, 2),
    );
}
if (report.failures.length) process.exitCode = 1;
