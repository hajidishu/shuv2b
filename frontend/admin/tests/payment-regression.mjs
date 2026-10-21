import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { mkdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { chromium } from "playwright";
import { encodeQuery } from "../src/domain.mjs";

const origin = process.env.ADMIN_LIVE_URL;
if (!origin || !process.env.ADMIN_LIVE_PASSWORD)
    throw new Error("Set authorized ADMIN_LIVE_URL and ADMIN_LIVE_PASSWORD");
const prefix = "payment-fix-" + Date.now(),
    password = "PaymentTest!2026";
const out = resolve(
    import.meta.dirname,
    "../test-results/payment-regression-live",
);
await mkdir(out, { recursive: true });
const report = {
    prefix,
    passed: [],
    failures: [],
    users: [],
    gateways: [],
    cleanup: [],
};
const browser = await chromium.launch({
    executablePath:
        process.env.ADMIN_BROWSER_PATH ??
        "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe",
});
const context = await browser.newContext({
    viewport: { width: 1440, height: 1000 },
    locale: "zh-CN",
});
const page = await context.newPage();
let admin,
    a,
    b,
    serial = 0,
    lateOrder,
    lateUser;
const call = async (path, data, token = admin, method = "POST") => {
    const response = await context.request.fetch(
        origin +
            "/api/v1/" +
            path +
            (method === "GET" && data ? "?" + encodeQuery(data) : ""),
        {
            method,
            headers: {
                Accept: "application/json",
                Authorization: token ?? "",
                Origin: origin,
            },
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
        throw error;
    }
};
const user = async () => {
    const local = prefix + "-" + serial++,
        email = local + "@example.com";
    await call("test-admin/user/generate", {
        email_prefix: local,
        email_suffix: "example.com",
        password,
    });
    const record = (
        await call(
            "test-admin/user/fetch",
            { filter: [{ key: "email", condition: "=", value: email }] },
            admin,
            "GET",
        )
    ).data.find((item) => item.email === email);
    report.users.push({ id: record.id, email });
    const auth = (await call("passport/auth/login", { email, password }, ""))
        .data.auth_data;
    return { ...record, auth };
};
const order = async (account) => {
    const trade = (
        await call(
            "user/order/save",
            { plan_id: 0, period: "deposit", deposit_amount: 10000 },
            account.auth,
        )
    ).data;
    return { trade_no: trade, user: account };
};
const checkout = (invoice, gateway) =>
    call(
        "user/order/checkout",
        { trade_no: invoice.trade_no, method: gateway.id },
        invoice.user.auth,
    );
const detail = (invoice) =>
    call(
        "user/order/detail",
        { trade_no: invoice.trade_no },
        invoice.user.auth,
        "GET",
    ).then((value) => value.data);
const balance = async (account) =>
    Number(
        (
            await call(
                "test-admin/user/getUserInfoById",
                { id: account.id },
                admin,
                "GET",
            )
        ).data.balance,
    );
const receipt = async (link, gateway, callback, money) => {
    const query = new URL(link.data).searchParams;
    const params = {
        pid: gateway.config.pid,
        trade_status: "TRADE_SUCCESS",
        out_trade_no: query.get("out_trade_no"),
        trade_no: callback,
        money: money ?? query.get("money"),
    };
    const body = new URLSearchParams(
        Object.entries(params).sort(([a], [b]) => a.localeCompare(b)),
    );
    params.sign = createHash("md5")
        .update(
            decodeURIComponent(body.toString().replace(/\+/g, " ")).replace(
                /\\/g,
                "",
            ) + gateway.config.key,
        )
        .digest("hex");
    params.sign_type = "MD5";
    return context.request.post(query.get("notify_url"), {
        data: params,
        headers: { Accept: "application/json" },
    });
};
const received = async (link, gateway, callback) => {
    const response = await receipt(link, gateway, callback);
    assert.ok(response.ok(), "Receipt HTTP " + response.status());
    assert.equal(await response.text(), "success");
};
const waitCompleted = async (invoice) => {
    for (let i = 0; i < 40; i++) {
        if (Number((await detail(invoice)).status) === 3) return;
        await page.waitForTimeout(300);
    }
    assert.fail("Order queue did not complete");
};
const gatewayBody = (name, config, fixed) => ({
    name,
    payment: "EPay",
    config,
    handling_fee_fixed: fixed,
    handling_fee_percent: null,
});
try {
    admin = (
        await call(
            "passport/auth/login",
            {
                email: "admin@v2b.test",
                password: process.env.ADMIN_LIVE_PASSWORD,
            },
            "",
        )
    ).data.auth_data;
    for (const [suffix, fixed] of [
        ["a", 250],
        ["b", 0],
    ]) {
        const config = {
            pid: "fixture-" + suffix,
            key: "fixture-key-" + prefix + suffix,
            url: "https://gateway.example.test",
            type: "alipay",
        };
        await call(
            "test-admin/payment/save",
            gatewayBody(prefix + "-" + suffix, config, fixed),
        );
        const found = (
            await call("test-admin/payment/fetch", null, admin, "GET")
        ).data.find((item) => item.name === prefix + "-" + suffix);
        report.gateways.push({ id: found.id, name: found.name });
        await call("test-admin/payment/show", { id: found.id });
        const gateway = { ...found, config, fixed };
        if (suffix === "a") a = gateway;
        else b = gateway;
    }
    await check("normal-payment-reuses-checkout-and-opens-once", async () => {
        const account = await user(),
            invoice = await order(account);
        const first = await checkout(invoice, a),
            again = await checkout(invoice, a);
        assert.deepEqual(first, again);
        assert.notEqual(
            new URL(first.data).searchParams.get("out_trade_no"),
            invoice.trade_no,
        );
        await received(first, a, prefix + "-normal");
        await received(first, a, prefix + "-normal");
        await waitCompleted(invoice);
        assert.equal(await balance(account), 10000);
    });
    await check(
        "switch-channel-earlier-payment-settles-and-extra-payment-credits-wallet-once",
        async () => {
            const account = await user(),
                invoice = await order(account);
            const first = await checkout(invoice, a),
                second = await checkout(invoice, b);
            await received(first, a, prefix + "-earlier");
            await waitCompleted(invoice);
            await received(second, b, prefix + "-extra");
            await received(second, b, prefix + "-extra");
            assert.equal(await balance(account), 20000);
            assert.equal((await detail(invoice)).payment_adjustments.length, 1);
        },
    );
    await check("cancel-and-late-receipt-refund-wallet-once", async () => {
        lateUser = await user();
        lateOrder = await order(lateUser);
        const link = await checkout(lateOrder, a);
        await call(
            "user/order/cancel",
            { trade_no: lateOrder.trade_no },
            lateUser.auth,
        );
        await received(link, a, prefix + "-canceled");
        await received(link, a, prefix + "-canceled");
        assert.equal(await balance(lateUser), 10250);
        const data = await detail(lateOrder);
        assert.equal(Number(data.status), 2);
        assert.equal(data.payment_adjustments[0].reason, "canceled_order");
    });
    await check(
        "underpayment-and-empty-secret-configuration-are-rejected",
        async () => {
            const account = await user(),
                invoice = await order(account),
                link = await checkout(invoice, a);
            assert.equal(
                (
                    await receipt(link, a, prefix + "-underpaid", "0.01")
                ).status(),
                500,
            );
            assert.equal(Number((await detail(invoice)).status), 0);
            assert.equal(await balance(account), 0);
            await call(
                "user/order/cancel",
                { trade_no: invoice.trade_no },
                account.auth,
            );
            const response = await context.request.post(
                origin + "/api/v1/test-admin/payment/save",
                {
                    headers: {
                        Authorization: admin,
                        Accept: "application/json",
                    },
                    data: {
                        name: prefix + "-empty",
                        payment: "BEasyPaymentUSDT",
                        config: { bepusdt_apitoken: "" },
                    },
                },
            );
            assert.equal(response.status(), 422);
        },
    );
    await check(
        "rotated-and-disabled-channel-still-accepts-its-inflight-receipt",
        async () => {
            const account = await user(),
                invoice = await order(account),
                link = await checkout(invoice, a);
            await call("test-admin/payment/save", {
                id: a.id,
                ...gatewayBody(
                    a.name,
                    { ...a.config, key: "rotated-fixture-key" },
                    a.fixed,
                ),
            });
            await call("test-admin/payment/show", { id: a.id });
            const blocked = await context.request.post(
                origin + "/api/v1/user/order/checkout",
                {
                    headers: {
                        Authorization: account.auth,
                        Accept: "application/json",
                    },
                    data: { trade_no: invoice.trade_no, method: a.id },
                },
            );
            assert.equal(blocked.status(), 422);
            await received(link, a, prefix + "-disabled");
            await waitCompleted(invoice);
            assert.equal(await balance(account), 10000);
        },
    );
    await check("admin-order-detail-shows-wallet-adjustment", async () => {
        await page.goto(origin + "/test-admin#/login");
        await page.getByLabel("邮箱", { exact: true }).fill("admin@v2b.test");
        await page
            .getByLabel("密码", { exact: true })
            .fill(process.env.ADMIN_LIVE_PASSWORD);
        await page.getByRole("button", { name: "登录", exact: true }).click();
        await page.waitForURL("**#/dashboard");
        await page.evaluate(
            (email) =>
                sessionStorage.setItem(
                    "admin-order-initial-filters",
                    JSON.stringify([
                        { key: "email", condition: "=", value: email },
                    ]),
                ),
            lateUser.email,
        );
        // A full navigation preserves the one-shot initial filter until the new app mounts.
        await page.goto(
            origin + "/test-admin?payment-check=" + Date.now() + "#/order",
            { waitUntil: "networkidle" },
        );
        await page
            .locator("tbody tr.ant-table-row")
            .first()
            .locator("a")
            .first()
            .click();
        const drawer = page.getByRole("dialog", { name: "订单信息" });
        await drawer.getByText("补充收款已转入余额", { exact: true }).click();
        await drawer.getByText("取消后到账", { exact: true }).waitFor();
        await page.screenshot({
            path: resolve(out, "wallet-adjustment.png"),
            fullPage: true,
        });
    });
} catch (error) {
    if (!report.failures.length)
        report.failures.push({
            name: "setup",
            error: error.stack ?? error.message,
        });
    process.exitCode = 1;
} finally {
    for (const account of report.users) {
        try {
            await call("test-admin/user/delUser", { id: account.id });
            report.cleanup.push({ kind: "user", id: account.id, pass: true });
        } catch (error) {
            report.cleanup.push({
                kind: "user",
                id: account.id,
                pass: false,
                error: error.message,
            });
            process.exitCode = 1;
        }
    }
    await browser.close();
    await writeFile(
        resolve(out, "report.json"),
        JSON.stringify(report, null, 2),
    );
}
if (report.failures.length) process.exitCode = 1;
