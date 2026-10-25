import { createServer } from "node:http";
import { readFile, stat } from "node:fs/promises";
import { resolve, sep } from "node:path";
import { nodeTypes } from "../src/domain.mjs";
import { createLeakFixture } from "./leak-fixture.mjs";
export async function startFixture({ visual = false, settings = {} } = {}) {
    const fixtureSettings = {
        title: "V2Board",
        secure_path: "test-admin",
        theme: { sidebar: "light", header: "dark", color: "default" },
        ...settings,
    };
    const root = resolve(import.meta.dirname, "../dist"),
        config = JSON.parse(
            await readFile(
                resolve(import.meta.dirname, "../src/schema/config.json"),
                "utf8",
            ),
        );
    let counter = 1;
    const store = {
        groups: [],
        routes: [],
        nodes: [],
        users: [],
        plans: [],
        orders: [],
        notice: [],
        knowledge: [],
        coupon: [],
        giftcard: [],
        payment: [],
        ticket: [],
        "subscribe-audit": [],
    };
    let auditSettings = { retention_days: 30, max_records: 1000000 };
    if (visual)
        store["subscribe-audit"].push(
            ...Array.from({ length: 25 }, (_, i) => ({
                id: i + 1,
                user_id: (i % 2) + 1,
                email:
                    i % 2
                        ? "audit-beta@example.test"
                        : "audit-alpha@example.test",
                ip: i % 2 ? "2001:db8::1" : "203.0.113.10",
                ua: i % 2 ? "sing-box/1.12.0" : "Clash.Meta/1.18 audit-client",
                format: i % 2 ? "Singbox" : "ClashMeta",
                result: i === 24 ? "unavailable" : "success",
                status: 200,
                requested_at: Math.floor(Date.now() / 1000) - i * 60,
                request_details:
                    i === 0
                        ? {
                              method: "GET",
                              protocol: "HTTP/1.1",
                              url: "https://panel.example.test/api/v1/client/subscribe?token=audit-fixture-token&flag=clash.meta",
                              scheme: "https",
                              host: "panel.example.test",
                              path: "/api/v1/client/subscribe",
                              remote_ip: "203.0.113.10",
                              duration_ms: 12,
                              query: {
                                  token: "audit-fixture-token",
                                  flag: "clash.meta",
                              },
                              headers: {
                                  authorization: "[redacted]",
                                  cookie: "[redacted]",
                                  "user-agent": "Clash.Meta/1.18 audit-client",
                                  "x-test":
                                      '<img src=x onerror="window.auditXss=1">',
                              },
                          }
                        : null,
            })),
        );
    const calls = [];
    const leakFixture = createLeakFixture(store);
    if (visual) {
        store.users.push({ id: -1, email: "demo@example.test", balance: 9300 });
        store.plans.push({
            id: -1,
            name: "户晨风奶茶",
            month_price: 500,
            year_price: 3600,
        });
        store.orders.push(
            ...Array.from({ length: 10 }, (_, i) => ({
                id: -i - 1,
                user_id: -1,
                plan_id: -1,
                plan_name: "户晨风奶茶",
                type: i === 5 ? 3 : 1,
                trade_no: `20261015145200000${509 + i}`,
                period: i % 2 ? "onetime_price" : "year_price",
                total_amount: i % 2 ? 2500 : 3600,
                status: i === 7 ? 0 : 3,
                commission_balance: i === 2 || i === 4 ? 480 : 0,
                commission_status: i === 4 ? 2 : 0,
                created_at: 1792047120 - i * 3600,
                paid_at: 1792047120 - i * 3600,
            })),
        );
    }
    const json = (res, data, status = 200) => {
        res.writeHead(status, { "Content-Type": "application/json" });
        res.end(JSON.stringify(data));
    };
    const server = createServer(async (req, res) => {
        try {
            const url = new URL(req.url, "http://localhost");
            if (!url.pathname.startsWith("/api/")) {
                const p = resolve(root, "." + url.pathname);
                if (!p.startsWith(root + sep) && p !== root) {
                    res.writeHead(403).end();
                    return;
                }
                let file =
                    url.pathname === "/" ? resolve(root, "index.html") : p;
                let text = await readFile(file);
                if (file.endsWith(".html"))
                    text = Buffer.from(
                        text
                            .toString()
                            .replace(
                                "<head>",
                                "<head><script>window.settings=" +
                                    JSON.stringify(fixtureSettings).replaceAll(
                                        "<",
                                        "\\u003c",
                                    ) +
                                    ";</script>",
                            ),
                    );
                res.writeHead(200, {
                    "Content-Type": file.endsWith(".js")
                        ? "text/javascript"
                        : file.endsWith(".css")
                          ? "text/css"
                          : file.endsWith(".woff2")
                            ? "font/woff2"
                            : "text/html",
                });
                res.end(text);
                return;
            }
            let body = "";
            for await (const chunk of req) body += chunk;
            const data = body ? JSON.parse(body) : {};
            const path = url.pathname.replace("/api/v1/test-admin", "");
            calls.push({
                path,
                method: req.method,
                data,
                query: Object.fromEntries(url.searchParams),
            });
            if (path === "/api/v1/passport/passkey/config")
                return json(res, { data: { enabled: false } });
            if (path === "/api/v1/passport/auth/login")
                return json(res, {
                    data: { auth_data: "Bearer fixture-admin", is_admin: true },
                });
            if (path === "/api/v1/user/info")
                return json(res, {
                    data: { email: "admin@example.test", is_admin: 1 },
                });
            if (req.headers.authorization !== "Bearer fixture-admin")
                return json(res, { message: "Unauthorized" }, 403);
            const leakResponse = leakFixture({
                path,
                data,
                query: url.searchParams,
                method: req.method,
            });
            if (leakResponse) return json(res, leakResponse);
            if (path === "/subscribe-audit/settings") {
                if (req.method === "POST")
                    auditSettings = { ...auditSettings, ...data };
                return json(res, { data: auditSettings });
            }
            if (
                [
                    "/subscribe-audit/fetch",
                    "/subscribe-audit/stats",
                    "/subscribe-audit/detail",
                ].includes(path)
            ) {
                const rows = store["subscribe-audit"].filter((row) => {
                    for (
                        let i = 0;
                        url.searchParams.has(`filter[${i}][key]`);
                        i++
                    ) {
                        const key = url.searchParams.get(`filter[${i}][key]`),
                            value = url.searchParams.get(`filter[${i}][value]`),
                            condition = url.searchParams.get(
                                `filter[${i}][condition]`,
                            );
                        const actual = String(row[key]);
                        if (
                            condition === "模糊"
                                ? !actual.includes(value)
                                : condition === "="
                                  ? actual !== value
                                  : condition === "!="
                                    ? actual === value
                                    : condition === ">="
                                      ? Number(actual) < Number(value)
                                      : condition === ">"
                                        ? Number(actual) <= Number(value)
                                        : condition === "<="
                                          ? Number(actual) > Number(value)
                                          : Number(actual) >= Number(value)
                        )
                            return false;
                    }
                    return true;
                });
                if (path.endsWith("/detail")) {
                    const record = store["subscribe-audit"].find(
                        (row) => row.id === Number(url.searchParams.get("id")),
                    );
                    return record
                        ? json(res, { data: record })
                        : json(res, { message: "记录不存在或已清理" }, 404);
                }
                if (path.endsWith("/stats")) {
                    const dimension = url.searchParams.get("dimension") ?? "ip",
                        column = dimension === "user" ? "user_id" : dimension;
                    const groups = new Map();
                    for (const row of rows) {
                        const key = row[column];
                        const group = groups.get(key) ?? {
                            value: key,
                            label: dimension === "user" ? row.email : key,
                            count: 0,
                            last_at: row.requested_at,
                        };
                        group.count++;
                        group.last_at = Math.max(
                            group.last_at,
                            row.requested_at,
                        );
                        groups.set(key, group);
                    }
                    return json(res, {
                        data: {
                            dimension,
                            total: rows.length,
                            users: new Set(rows.map((row) => row.user_id)).size,
                            ips: new Set(
                                rows.map((row) => row.ip).filter(Boolean),
                            ).size,
                            uas: new Set(
                                rows.map((row) => row.ua).filter(Boolean),
                            ).size,
                            ranking: [...groups.values()]
                                .sort(
                                    (a, b) =>
                                        b.count - a.count ||
                                        String(a.value).localeCompare(
                                            String(b.value),
                                        ),
                                )
                                .slice(0, 10),
                            generated_at: Math.floor(Date.now() / 1000),
                        },
                    });
                }
                const size = Number(url.searchParams.get("pageSize") ?? 20),
                    page = Number(url.searchParams.get("current") ?? 1);
                return json(res, {
                    data: rows
                        .slice((page - 1) * size, page * size)
                        .map(({ request_details, ...row }) => ({
                            ...row,
                            has_request_details: request_details ? 1 : 0,
                        })),
                    total: rows.length,
                    settings: auditSettings,
                });
            }
            if (path === "/config/fetch") return json(res, { data: config });
            if (path === "/config/save") {
                for (const values of Object.values(config))
                    for (const key of Object.keys(values))
                        if (key in data) values[key] = data[key];
                return json(res, { data: true });
            }
            if (path === "/config/getEmailTemplate")
                return json(res, { data: ["default.blade.php"] });
            if (path === "/config/getThemeTemplate")
                return json(res, { data: ["default"] });
            if (path === "/theme/getThemes")
                return json(res, {
                    data: {
                        active: "default",
                        themes: {
                            default: {
                                name: "默认主题",
                                configs: [
                                    {
                                        field_name: "theme_color",
                                        label: "主题色",
                                        field_type: "select",
                                        select_options: {
                                            default: "蓝色",
                                            green: "绿色",
                                        },
                                    },
                                ],
                            },
                        },
                    },
                });
            if (path === "/theme/getThemeConfig")
                return json(res, { data: { theme_color: "default" } });
            if (path === "/theme/saveThemeConfig")
                return json(res, {
                    data: JSON.parse(
                        Buffer.from(data.config, "base64").toString(),
                    ),
                });
            if (path === "/payment/getPaymentMethods")
                return json(res, { data: ["EPay", "Paytaro", "PaytaroQR"] });
            if (
                path === "/payment/getPaymentForm" &&
                data.payment?.startsWith("Paytaro")
            )
                return json(res, {
                    data: {
                        pid: { label: "App ID", type: "input" },
                        key: { label: "App Secret", type: "input" },
                        ...(data.payment === "PaytaroQR"
                            ? {
                                  method_uuid: {
                                      label: "支付方式 UUID",
                                      type: "input",
                                  },
                              }
                            : {}),
                    },
                });
            if (path === "/payment/getPaymentForm")
                return json(res, {
                    data: {
                        url: { label: "接口地址", type: "input" },
                        pid: { label: "商户 ID", type: "input" },
                        key: { label: "商户密钥", type: "input" },
                    },
                });
            if (path.startsWith("/stat/"))
                return json(res, {
                    data:
                        path === "/stat/getOverride"
                            ? visual
                                ? {
                                      online_user: 211,
                                      day_income: 9300,
                                      day_register_total: 6,
                                      month_income: 211282,
                                      last_month_income: 646121,
                                      commission_last_month_payout: 30185,
                                      month_register_total: 139,
                                      commission_pending_total: 1,
                                  }
                                : {
                                      online_user: 0,
                                      day_income: 0,
                                      month_income: 0,
                                  }
                            : path === "/stat/getOrder"
                              ? (visual
                                    ? Array.from({ length: 31 }, (_, i) =>
                                          new Date(Date.UTC(2026, 8, 14 + i))
                                              .toISOString()
                                              .slice(5, 10),
                                      )
                                    : ["10-13", "10-14", "10-15"]
                                ).flatMap((date, i) =>
                                    [
                                        "佣金笔数(已发放)",
                                        "佣金金额(已发放)",
                                        "收款笔数",
                                        "收款金额",
                                        "注册人数",
                                    ].map((type, j) => ({
                                        date,
                                        type,
                                        value: visual
                                            ? j === 3
                                                ? [
                                                      180, 230, 60, 570, 220,
                                                      300, 290, 295, 195, 270,
                                                      130, 265, 30, 85, 220,
                                                      400, 185, 350, 300, 90,
                                                      110, 32, 215, 90, 145, 40,
                                                      150, 45, 165, 160, 140,
                                                  ][i]
                                                : (i * (j + 3)) % 27
                                            : (i + 1) * (j + 1),
                                    })),
                                )
                              : visual
                                ? Array.from({ length: 20 }, (_, i) => ({
                                      server_name: `Hongkong ${i + 1} anytls | aws-hytron`,
                                      email: `demo${i}@example.test`,
                                      total: +(830 / (i + 1)).toFixed(2),
                                  }))
                                : [],
                });
            if (visual && path === "/system/getQueueMasters")
                return json(res, {
                    "fixture-host": {
                        name: "fixture-host",
                        pid: 700,
                        status: "running",
                    },
                });
            if (visual && path === "/system/getQueueWorkload")
                return json(res, {
                    data: [
                        {
                            name: "order_handle",
                            length: 0,
                            wait: 0,
                            processes: 1,
                        },
                    ],
                });
            if (path.startsWith("/system/"))
                return json(res, {
                    data:
                        path.includes("getQueueWorkload") ||
                        path.includes("getQueueMasters") ||
                        path.includes("getSystemLog")
                            ? []
                            : {
                                  horizon: true,
                                  schedule: true,
                                  status: true,
                                  failedJobs: 0,
                                  processes: 7,
                              },
                    total: 0,
                });
            if (path === "/order/detail")
                return json(res, {
                    data: store.orders.find((x) => x.id === data.id),
                });
            if (path === "/order/paid" || path === "/order/cancel") {
                const row = store.orders.find(
                    (x) => x.trade_no === data.trade_no,
                );
                if (!row) return json(res, { message: "Order not found" }, 404);
                row.status = path.endsWith("paid") ? 1 : 2;
                return json(res, { data: true });
            }
            if (path === "/server/manage/getNodes")
                return json(res, { data: store.nodes });
            if (path === "/server/manage/batch") {
                for (const n of data.nodes) {
                    const row = store.nodes.find(
                        (x) => x.type === n.type && x.id === n.id,
                    );
                    if (!row) return json(res, { message: "节点不存在" }, 404);
                }
                for (const n of data.nodes) {
                    const row = store.nodes.find(
                        (x) => x.type === n.type && x.id === n.id,
                    );
                    if (data.action === "update") {
                        Object.assign(row, data.changes);
                        if (typeof row.padding_scheme === "string")
                            row.padding_scheme = JSON.parse(row.padding_scheme);
                    }
                    if (data.action === "copy")
                        store.nodes.push({
                            ...structuredClone(row),
                            show: 0,
                            id: counter++,
                            name: row.name + " 副本",
                        });
                    if (data.action === "delete")
                        store.nodes = store.nodes.filter(
                            (x) => !(x.type === n.type && x.id === n.id),
                        );
                }
                return json(res, { data: true, affected: data.nodes.length });
            }
            if (path === "/server/manage/sort")
                return json(res, { data: true });
            const node = path.match(
                /^\/server\/(shadowsocks|vmess|trojan|tuic|hysteria|vless|anytls|v2node)\/(save|drop|update|copy)$/,
            );
            if (node) {
                const [, type, action] = node;
                let row = store.nodes.find(
                    (x) => x.type === type && x.id === data.id,
                );
                if (action === "save" || action === "update") {
                    if (!row) {
                        row = { id: counter++, type };
                        store.nodes.push(row);
                    }
                    Object.assign(row, data);
                    if (typeof row.padding_scheme === "string")
                        row.padding_scheme = JSON.parse(row.padding_scheme);
                } else if (action === "drop")
                    store.nodes = store.nodes.filter(
                        (x) => !(x.type === type && x.id === data.id),
                    );
                else
                    store.nodes.push({
                        ...structuredClone(row),
                        show: 0,
                        id: counter++,
                    });
                return json(res, { data: true });
            }
            const name = path.startsWith("/server/group")
                ? "groups"
                : path.startsWith("/server/route")
                  ? "routes"
                  : path.split("/")[1] === "user"
                    ? "users"
                    : path.split("/")[1] === "plan"
                      ? "plans"
                      : path.split("/")[1] === "order"
                        ? "orders"
                        : path.split("/")[1];
            const list = store[name];
            if (!list) return json(res, { data: true });
            const operation = path.split("/").at(-1);
            const match = (row) => {
                if (data.ids && !data.ids.includes(row.id)) return false;
                for (
                    let i = 0;
                    url.searchParams.has(`filter[${i}][key]`) ||
                    (data.filter ?? [])[i];
                    i++
                ) {
                    const f = data.filter?.[i] ?? {
                        key: url.searchParams.get(`filter[${i}][key]`),
                        condition: url.searchParams.get(
                            `filter[${i}][condition]`,
                        ),
                        value: url.searchParams.get(`filter[${i}][value]`),
                    };
                    const value = row[f.key],
                        v = f.value;
                    const ok =
                        f.condition === "模糊"
                            ? String(value).includes(v)
                            : f.condition === "="
                              ? String(value) === String(v)
                              : f.condition === "!="
                                ? String(value) !== String(v)
                                : f.condition === ">"
                                  ? Number(value) > Number(v)
                                  : f.condition === ">="
                                    ? Number(value) >= Number(v)
                                    : f.condition === "<"
                                      ? Number(value) < Number(v)
                                      : Number(value) <= Number(v);
                    if (!ok) return false;
                }
                return true;
            };
            if (operation === "fetch" || operation === "getUserInfoById") {
                const filtered = list.filter(match);
                return json(res, {
                    data:
                        url.searchParams.get("id") || data.id
                            ? list.find(
                                  (x) =>
                                      x.id ===
                                      Number(
                                          url.searchParams.get("id") ?? data.id,
                                      ),
                              )
                            : filtered,
                    total: filtered.length,
                });
            }
            if (operation === "dumpCSV") {
                res.writeHead(200, { "Content-Type": "text/csv" });
                return res.end(
                    "邮箱,余额\n" +
                        list
                            .filter(match)
                            .map((x) => x.email + "," + x.balance)
                            .join("\n"),
                );
            }
            if (operation === "ban") {
                list.filter(match).forEach((x) => (x.banned = 1));
                return json(res, { data: true });
            }
            if (operation === "allDel") {
                store[name] = list.filter((x) => !match(x));
                return json(res, { data: true });
            }
            if (operation === "drop" || operation === "delUser") {
                store[name] = list.filter((x) => x.id !== data.id);
                return json(res, { data: true });
            }
            if (["save", "generate", "update", "assign"].includes(operation)) {
                let row = list.find((x) =>
                    data.trade_no
                        ? x.trade_no === data.trade_no
                        : x.id === data.id,
                );
                if (!row) {
                    row = {
                        id: counter++,
                        show: 1,
                        renew: 1,
                        created_at: Math.floor(Date.now() / 1000),
                        updated_at: Math.floor(Date.now() / 1000),
                    };
                    list.push(row);
                }
                Object.assign(row, data);
                if (name === "users" && operation === "generate")
                    Object.assign(row, {
                        email: data.email_prefix + "@" + data.email_suffix,
                        balance: 0,
                        commission_balance: 0,
                        u: 0,
                        d: 0,
                        transfer_enable: 0,
                        banned: 0,
                        is_admin: 0,
                        is_staff: 0,
                        subscribe_url: "https://example.invalid/subscribe",
                    });
                return json(res, { data: true });
            }
            if (operation === "show") {
                const row = list.find((x) => x.id === data.id);
                row.show = row.show ? 0 : 1;
                return json(res, { data: true });
            }
            return json(res, { data: true });
        } catch (e) {
            json(res, { message: e.message }, 500);
        }
    });
    await new Promise((resolve) => server.listen(0, "127.0.0.1", resolve));
    return {
        origin: "http://127.0.0.1:" + server.address().port,
        calls,
        store,
        close: () => new Promise((resolve) => server.close(resolve)),
    };
}
