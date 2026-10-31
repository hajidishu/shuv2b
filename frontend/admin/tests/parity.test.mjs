import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";
import ts from "typescript";
const root = resolve(import.meta.dirname, ".."),
    backend = resolve(root, "../..");
async function load(file, imports = {}) {
    let text = ts.transpileModule(await readFile(resolve(root, file), "utf8"), {
        compilerOptions: {
            target: ts.ScriptTarget.ES2022,
            module: ts.ModuleKind.ESNext,
        },
    }).outputText;
    for (const [from, to] of Object.entries(imports))
        text = text.replaceAll(JSON.stringify(from), JSON.stringify(to));
    return import(
        "data:text/javascript;base64," + Buffer.from(text).toString("base64")
    );
}
const nodesUrl =
    "data:text/javascript;base64," +
    Buffer.from(
        ts.transpileModule(
            await readFile(resolve(root, "src/schema/nodes.ts"), "utf8"),
            {
                compilerOptions: {
                    target: ts.ScriptTarget.ES2022,
                    module: ts.ModuleKind.ESNext,
                },
            },
        ).outputText,
    ).toString("base64");
const nodeSchema = await import(nodesUrl);
const schemas = await load("src/schema/resources.ts", {
    "./nodes": nodesUrl,
    "../domain.mjs": pathToFileURL(resolve(root, "src/domain.mjs")).href,
});
const editorSchema = await load("src/schema/node-editor.ts", {
    "./nodes": nodesUrl,
});

test("Naive 与 V2node Naive 仅提供原生网络和受信任 TLS 配置", () => {
    for (const type of ["naive", "v2node"]) {
        const fields = editorSchema.nodeEditorFields(type, { protocol: "naive", tls: 1, network: "udp" });
        const keys = editorSchema.nodeSubmissionFields(fields).map(f => f.key);
        for (const key of ["network", "tls", "tls_settings", "congestion_control"]) assert(keys.includes(key));
        for (const key of ["flow", "padding_scheme", "network_settings", "encryption_settings", "obfs", "zero_rtt_handshake"]) assert(!keys.includes(key));
        assert.deepEqual(fields.find(f => f.key === "network").options.map(o => o.value), ["tcp", "udp", ""]);
        const tls = fields.find(f => f.key === "tls");
        assert.deepEqual(tls.options.map(o => o.value), [1]);
        assert(!tls.config.nested.some(f => ["allow_insecure", "fingerprint", "private_key", "short_id"].includes(f.key)));
        assert.deepEqual(tls.config.nested.find(f => f.key === "cert_mode").options.map(o => o.value), ["http", "dns", "file"]);
    }
});
test("Mieru 与 V2node Mieru 使用原生传输、端口范围和专属配置，不显示 TLS", () => {
    for (const type of ["mieru", "v2node"]) {
        const fields = editorSchema.nodeEditorFields(type, { protocol: "mieru", network: "udp", tls: 2 });
        const all = editorSchema.nodeSubmissionFields(fields), keys = all.map(f => f.key);
        for (const key of ["network", "network_settings", "port", "server_port", "listen_ip"]) assert(keys.includes(key));
        for (const key of ["tls", "tls_settings", "flow", "padding_scheme", "congestion_control", "cipher", "zero_rtt_handshake"]) assert(!keys.includes(key));
        assert.equal(fields.find(f => f.key === "server_port").kind, "text");
        assert.deepEqual(fields.find(f => f.key === "network").options.map(o => o.value), ["tcp", "udp"]);
        assert(all.find(f => f.key === "network_settings").nested.some(f => f.key === "server_traffic_pattern"));
    }
});

test("统计图保留日期对齐、原版配色和平滑曲线，排名不改写响应", async () => {
    const { incomeOptions, rankingOptions, originalChartColors } = await load(
        "src/chart-options.ts",
    );
    const option = incomeOptions([
        { date: "10-01", type: "收款金额", value: 3 },
        { date: "10-02", type: "注册人数", value: 2 },
        { date: "10-02", type: "收款金额", value: 7 },
    ]);
    assert.deepEqual(option.xAxis.data, ["10-01", "10-02"]);
    assert.deepEqual(option.series[0].data, [3, 7]);
    assert.deepEqual(option.series[1].data, [null, 2]);
    assert.ok(option.series.every((s) => s.smooth && s.type === "line"));
    assert.deepEqual(option.color, originalChartColors);
    const rows = [
        { server_name: "A", total: 30 },
        { server_name: "B", total: 10 },
    ];
    const snapshot = structuredClone(rows),
        ranking = rankingOptions(rows);
    assert.deepEqual(rows, snapshot);
    assert.deepEqual(ranking.yAxis.data, ["B", "A"]);
    assert.deepEqual(ranking.series[0].data, [10, 30]);
});
test("支付接口提示不被当成配置字段或提交数据", async () => {
    const { paymentFields } = await load("src/schema/payments.ts");
    const fields = paymentFields({
        pid: { label: "App ID", type: "input" },
        secret: { label: "App Secret", type: "password" },
        alert1: { type: "alert", content: "gateway instructions" },
    });
    assert.deepEqual(
        fields.map((f) => f.key),
        ["config_pid", "config_secret"],
    );
    assert.equal(fields[1].kind, "password");
});
test("V2node 按所选协议显示字段，隐藏协议配置不进入提交集合", () => {
    const empty = editorSchema
        .nodeSubmissionFields(editorSchema.nodeEditorFields("v2node", {}))
        .map((f) => f.key);
    assert.ok(
        !empty.includes("tls") &&
            !empty.includes("network") &&
            !empty.includes("padding_scheme"),
    );
    for (const protocol of [
        "vmess",
        "vless",
        "shadowsocks",
        "trojan",
        "anytls",
        "hysteria2",
        "tuic",
    ]) {
        const fields = editorSchema.nodeEditorFields("v2node", {
            protocol,
            tls: 1,
            network: "tcp",
        });
        const keys = editorSchema
            .nodeSubmissionFields(fields)
            .map((f) => f.key);
        assert.equal(
            keys.includes("padding_scheme"),
            protocol === "anytls",
            protocol,
        );
        assert.equal(
            keys.includes("up_mbps"),
            protocol === "hysteria2",
            protocol,
        );
        assert.equal(
            keys.includes("disable_sni"),
            protocol === "tuic",
            protocol,
        );
        assert.equal(
            keys.includes("cipher"),
            protocol === "shadowsocks",
            protocol,
        );
        assert.equal(keys.includes("flow"), protocol === "vless", protocol);
        assert.equal(fields[0].span, 8);
        assert.equal(fields[1].span, 4);
    }
});
test("TLS 和 Reality 配置隔离；VMess 使用原版 camelCase 设置字段", () => {
    for (const mode of [1, 2]) {
        const fields = editorSchema.nodeEditorFields("v2node", {
            protocol: "vless",
            tls: mode,
            network: "tcp",
        });
        const nested = fields
            .find((f) => f.key === "tls")
            .config.nested.map((f) => f.key);
        assert.equal(nested.includes("private_key"), mode === 2);
        assert.equal(nested.includes("cert_mode"), mode === 1);
    }
    const vmess = editorSchema
        .nodeEditorFields("vmess", { tls: 1, network: "tcp" })
        .find((f) => f.key === "tls").config;
    assert.deepEqual(
        vmess.nested.map((f) => f.key),
        ["serverName", "allowInsecure"],
    );
});
test("复用的原版图标字体具有固定来源和原始校验值", async () => {
    const { createHash } = await import("node:crypto");
    const names = ["simple-line-icons.woff2", "fa-solid.woff2"];
    const provenance = await readFile(
        resolve(root, "src/assets/icons/PROVENANCE.txt"),
        "utf8",
    );
    for (const name of names) {
        const match = provenance.match(
            new RegExp(name.replaceAll(".", "\\.") + " SHA256 ([0-9a-f]{64})"),
        );
        assert.ok(match, name);
        assert.equal(
            createHash("sha256")
                .update(await readFile(resolve(root, "src/assets/icons", name)))
                .digest("hex"),
            match[1],
        );
    }
});
test("用户编辑绝不回填密码哈希，金额和零值仍保留", async () => {
    const source = await readFile(resolve(root, "src/components.tsx"), "utf8");
    const ast = ts.createSourceFile(
        "components.tsx",
        source,
        ts.ScriptTarget.Latest,
        true,
        ts.ScriptKind.TSX,
    );
    const functions = ast.statements
        .filter(
            (n) =>
                ts.isFunctionDeclaration(n) &&
                ["displayValues", "payloadValues"].includes(n.name?.text),
        )
        .map((n) => n.getText(ast))
        .join("\n");
    const code = ts.transpileModule(functions, {
        compilerOptions: {
            target: ts.ScriptTarget.ES2022,
            module: ts.ModuleKind.ESNext,
        },
    }).outputText;
    const module = await import(
        "data:text/javascript;base64," +
            Buffer.from(
                "import {cents,yuan} from " +
                    JSON.stringify(
                        pathToFileURL(resolve(root, "src/domain.mjs")).href,
                    ) +
                    ";\n" +
                    code,
            ).toString("base64")
    );
    const fields = [
        { key: "password", kind: "password" },
        { key: "balance", kind: "money" },
        { key: "banned", kind: "switch" },
        { key: "expired_at", kind: "date" },
    ];
    const displayed = module.displayValues(fields, {
        password: "$2y$12$existing-hash",
        balance: 1999,
        banned: 0,
        expired_at: 1791993637,
    });
    assert.equal(displayed.password, undefined);
    assert.equal(displayed.balance, "19.99");
    const payload = module.payloadValues(fields, displayed);
    assert.ok(!Object.hasOwn(payload, "password"));
    assert.equal(payload.balance, 1999);
    assert.equal(payload.banned, 0);
    assert.equal(payload.expired_at, 1791993637);
});
test("原有节点表单覆盖后端保存规则中的全部可编辑字段", async () => {
    for (const type of [
        "shadowsocks",
        "vmess",
        "trojan",
        "tuic",
        "hysteria",
        "vless",
        "anytls",
        "v2node",
    ]) {
        const suffix =
            { anytls: "AnyTLS", v2node: "V2node" }[type] ??
            type[0].toUpperCase() + type.slice(1);
        const relative = ["shadowsocks", "vmess", "trojan"].includes(type)
            ? `app/Http/Requests/Admin/Server${suffix}Save.php`
            : `app/Http/Controllers/V1/Admin/Server/${suffix}Controller.php`;
        const code = await readFile(resolve(backend, relative), "utf8");
        const start = ["shadowsocks", "vmess", "trojan"].includes(type)
            ? code.indexOf("return [")
            : code.indexOf("$request->validate([");
        const block = code.slice(
            start,
            code.indexOf("];", start) > 0
                ? code.indexOf("];", start) + 2
                : code.indexOf("]);", start) + 3,
        );
        const keys = [...block.matchAll(/'([\w.]+)'\s*=>/g)].map(
            (m) => m[1].split(".")[0],
        );
        const declared = nodeSchema.nodeFields(type).map((f) => f.key);
        assert.ok(keys.length > 5, relative);
        for (const key of keys)
            assert.ok(declared.includes(key), `${type}: missing ${key}`);
    }
});
test("套餐、用户和生成表单覆盖后端验证字段", async () => {
    for (const [file, fields] of [
        ["PlanSave", schemas.planFields],
        ["UserUpdate", schemas.userFields],
        ["UserGenerate", schemas.generateUserFields],
        ["OrderAssign", schemas.orderFields],
        ["CouponGenerate", schemas.cardFields()],
        ["GiftcardGenerate", schemas.cardFields(true)],
    ]) {
        const code = (
            await readFile(
                resolve(backend, `app/Http/Requests/Admin/${file}.php`),
                "utf8",
            )
        ).split("public function messages")[0];
        for (const m of code.matchAll(/'([a-z_]+)'\s*=>/g))
            assert.ok(
                fields.some((f) => f.key === m[1]),
                `${file}: ${m[1]}`,
            );
    }
});
test("所有后台配置字段都有设置表单入口", async () => {
    const config = JSON.parse(
        await readFile(resolve(root, "src/schema/config.json"), "utf8"),
    );
    const keys = Object.values(config).flatMap(Object.keys);
    const php = (
        await readFile(
            resolve(backend, "app/Http/Requests/Admin/ConfigSave.php"),
            "utf8",
        )
    ).split("public function rules")[0];
    for (const m of php.matchAll(/'([a-z_]+)'\s*=>/g))
        assert.ok(keys.includes(m[1]), "Missing configuration " + m[1]);
});
test("前端独立构建且不引用旧编译运行时", async () => {
    for (const name of ["src/main.tsx", "src/App.tsx", "index.html"]) {
        const content = await readFile(resolve(root, name), "utf8");
        assert.ok(
            !/compat\/|legacy\/|umi\.css|async\.js|__react/.test(content),
            name,
        );
    }
    const manifest = JSON.parse(
        await readFile(resolve(root, "dist/manifest.json"), "utf8"),
    );
    assert.ok(manifest["index.html"]?.file);
});
