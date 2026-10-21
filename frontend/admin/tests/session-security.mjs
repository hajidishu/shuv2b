import assert from "node:assert/strict";
import { mkdir, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { chromium } from "playwright";
import { encodeQuery } from "../src/domain.mjs";

const origin = process.env.ADMIN_LIVE_URL;
if (!origin || !process.env.ADMIN_LIVE_PASSWORD)
    throw new Error("Set authorized ADMIN_LIVE_URL and ADMIN_LIVE_PASSWORD");
const prefix = "session-fix-" + Date.now(),
    password = "SessionFixture!2026";
const out = resolve(
    import.meta.dirname,
    "../test-results/session-security-live",
);
await mkdir(out, { recursive: true });
const report = { prefix, passed: [], failures: [], cleanup: [] },
    users = [],
    notices = [];
const browser = await chromium.launch({
    executablePath:
        process.env.ADMIN_BROWSER_PATH ??
        "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe",
});
const context = await browser.newContext();
let admin, staff, target, temporaryAdmin;
const response = (path, data, token = admin, method = "POST") =>
    context.request.fetch(
        origin +
            "/api/v1/" +
            path +
            (method === "GET" && data ? "?" + encodeQuery(data) : ""),
        {
            method,
            headers: { Accept: "application/json", Authorization: token ?? "" },
            ...(method === "POST" ? { data } : {}),
        },
    );
const call = async (...args) => {
    const result = await response(...args);
    assert.ok(result.ok(), args[0] + " HTTP " + result.status());
    return result.json();
};
const login = async (email, value = password) =>
    (await call("passport/auth/login", { email, password: value }, "")).data
        .auth_data;
const denied = async (path, token) =>
    assert.equal(
        (await response(path, null, token, "GET")).status(),
        403,
        path,
    );
const check = async (name, fn) => {
    try {
        await fn();
        report.passed.push(name);
        console.log("PASS " + name);
    } catch (error) {
        report.failures.push({ name, message: error.message });
        throw error;
    }
};
const create = async (role) => {
    const local = prefix + "-" + role,
        email = local + "@example.test";
    await call("test-admin/user/generate", {
        email_prefix: local,
        email_suffix: "example.test",
        password,
    });
    const user = (
        await call(
            "test-admin/user/fetch",
            { filter: [{ key: "email", condition: "=", value: email }] },
            admin,
            "GET",
        )
    ).data.find((item) => item.email === email);
    assert.ok(user);
    users.push(user);
    if (role !== "user")
        await call("test-admin/user/update", {
            id: user.id,
            email,
            banned: 0,
            is_admin: role === "admin" ? 1 : 0,
            is_staff: role === "staff" ? 1 : 0,
        });
    return { ...user, auth: await login(email) };
};
const update = (changes) =>
    call(
        "staff/user/update",
        { id: target.id, email: target.email, banned: 0, ...changes },
        staff.auth,
    );

try {
    admin = await login(
        process.env.ADMIN_LIVE_EMAIL ?? "admin@v2b.test",
        process.env.ADMIN_LIVE_PASSWORD,
    );
    staff = await create("staff");
    target = await create("user");
    temporaryAdmin = await create("admin");
    await check("active-roles-and-normal-user-boundaries", async () => {
        await call("staff/plan/fetch", null, staff.auth, "GET");
        await call("test-admin/plan/fetch", null, temporaryAdmin.auth, "GET");
        await call("user/info", null, target.auth, "GET");
        await denied("test-admin/plan/fetch", target.auth);
        await denied("staff/plan/fetch", target.auth);
    });
    await check(
        "single-session-removal-rejects-old-token-keeps-other-login",
        async () => {
            const second = await login(target.email);
            await call("user/info", null, target.auth, "GET");
            const guid = JSON.parse(
                Buffer.from(target.auth.split(".")[1], "base64url").toString(),
            ).session;
            await call(
                "user/removeActiveSession",
                { session_id: guid },
                second,
            );
            await denied("user/info", target.auth);
            await call("user/info", null, second, "GET");
            target.auth = second;
        },
    );
    await check(
        "staff-cannot-reset-administrator-or-own-staff-account",
        async () => {
            for (const user of [temporaryAdmin, staff]) {
                const result = await response(
                    "staff/user/update",
                    {
                        id: user.id,
                        email: user.email,
                        banned: 1,
                        password: "Forbidden!2026",
                    },
                    staff.auth,
                );
                assert.ok(!result.ok());
            }
            await call(
                "test-admin/plan/fetch",
                null,
                temporaryAdmin.auth,
                "GET",
            );
            await call("staff/plan/fetch", null, staff.auth, "GET");
        },
    );
    await check(
        "removed-admin-token-stays-invalid-after-ban-and-demotion",
        async () => {
            const guid = JSON.parse(
                Buffer.from(
                    temporaryAdmin.auth.split(".")[1],
                    "base64url",
                ).toString(),
            ).session;
            await call(
                "user/removeActiveSession",
                { session_id: guid },
                temporaryAdmin.auth,
            );
            await call("test-admin/user/update", {
                id: temporaryAdmin.id,
                email: temporaryAdmin.email,
                is_admin: 0,
                is_staff: 0,
                banned: 1,
            });
            await denied("test-admin/plan/fetch", temporaryAdmin.auth);
            await denied("user/info", temporaryAdmin.auth);
        },
    );
    await check("staff-password-reset-revokes-all-old-logins", async () => {
        const second = await login(target.email);
        await call("user/info", null, target.auth, "GET");
        await call("user/info", null, second, "GET");
        await update({ password: "NewSessionFixture!2026" });
        await denied("user/info", target.auth);
        await denied("user/info", second);
        assert.ok(
            !(
                await response(
                    "passport/auth/login",
                    { email: target.email, password },
                    "",
                )
            ).ok(),
        );
        target.auth = await login(target.email, "NewSessionFixture!2026");
    });
    await check("ordinary-staff-edit-retains-existing-session", async () => {
        await update({ balance: 0 });
        await call("user/info", null, target.auth, "GET");
    });
    await check("staff-ban-and-unban-do-not-resurrect-old-token", async () => {
        const old = target.auth;
        await update({ banned: 1 });
        await denied("user/info", old);
        assert.ok(
            !(
                await response(
                    "passport/auth/login",
                    { email: target.email, password: "NewSessionFixture!2026" },
                    "",
                )
            ).ok(),
        );
        await update({ banned: 0 });
        await denied("user/info", old);
        target.auth = await login(target.email, "NewSessionFixture!2026");
    });
    await check(
        "staff-notice-html-round-trip-preserves-original-markup",
        async () => {
            const title = prefix + "-notice";
            const content =
                '<div data-notice-fixture="legacy" style="display:flex;gap:12px"><span class="original-style">正常公告</span><a href="/help" target="_self">帮助</a></div>';
            await call(
                "staff/notice/save",
                {
                    title,
                    content,
                },
                staff.auth,
            );
            const notice = (
                await call("test-admin/notice/fetch", null, admin, "GET")
            ).data.find((item) => item.title === title);
            assert.ok(notice);
            notices.push(notice.id);
            assert.equal(notice.content, content);
            if (!notice.show)
                await call("test-admin/notice/show", { id: notice.id });
            const returned = (
                await call(
                    "user/notice/fetch",
                    { id: notice.id },
                    target.auth,
                    "GET",
                )
            ).data.content;
            assert.equal(returned, content);
            const updated = content.replace("正常公告", "编辑后的公告");
            await call(
                "staff/notice/save",
                { id: notice.id, title, content: updated },
                staff.auth,
            );
            const edited = (
                await call(
                    "user/notice/fetch",
                    { id: notice.id },
                    target.auth,
                    "GET",
                )
            ).data.content;
            assert.equal(edited, updated);
        },
    );
    await check("malformed-token-is-rejected", async () => {
        await denied("user/info", "not-a-jwt");
    });
} finally {
    for (const id of notices) {
        try {
            await call("test-admin/notice/drop", { id });
            report.cleanup.push({ kind: "notice", id, pass: true });
        } catch (error) {
            report.cleanup.push({
                kind: "notice",
                id,
                pass: false,
                message: error.message,
            });
        }
    }
    for (const user of users) {
        try {
            await call("test-admin/user/delUser", { id: user.id });
            report.cleanup.push({ kind: "user", id: user.id, pass: true });
        } catch (error) {
            report.cleanup.push({
                kind: "user",
                id: user.id,
                pass: false,
                message: error.message,
            });
        }
    }
    await browser.close();
    await writeFile(
        resolve(out, "report.json"),
        JSON.stringify(report, null, 2),
    );
}
assert.equal(report.failures.length, 0);
assert.ok(report.cleanup.every((item) => item.pass));
console.log(
    JSON.stringify({
        passed: report.passed.length,
        cleanup: report.cleanup.length,
    }),
);
