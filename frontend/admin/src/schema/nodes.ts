import type { Field } from "../types";
export const options = (...values: any[]) =>
    values.map((v) => ({ label: String(v), value: v }));
const f = (
    key: string,
    label: string,
    kind: Field["kind"] = "text",
    extra: Partial<Field> = {},
): Field => ({ key, label, kind, ...extra });
const tlsOptions = [
    { label: "关闭", value: 0 },
    { label: "TLS", value: 1 },
    { label: "Reality", value: 2 },
];
const tlsNested: Field["nested"] = [
    { key: "server_name", label: "SNI" },
    { key: "allow_insecure", label: "允许不安全", kind: "switch" },
    { key: "fingerprint", label: "指纹" },
    { key: "public_key", label: "Reality 公钥" },
    { key: "private_key", label: "Reality 私钥", kind: "password" },
    { key: "short_id", label: "Short ID" },
    { key: "server_port", label: "伪装端口" },
    {
        key: "cert_mode",
        label: "证书模式",
        kind: "select",
        options: options("none", "self", "remote", "http", "dns"),
    },
    { key: "cert_file", label: "证书文件" },
    { key: "key_file", label: "私钥文件" },
    {
        key: "ech",
        label: "ECH 模式",
        kind: "select",
        options: options("", "auto", "custom"),
    },
    { key: "ech_server_name", label: "ECH Server Name" },
    { key: "ech_config", label: "ECH 配置" },
    { key: "ech_key", label: "ECH 密钥", kind: "password" },
    { key: "pinned_peer_cert_sha256", label: "证书 SHA256" },
    { key: "dest", label: "Reality 伪装目标" },
    { key: "xver", label: "Proxy Protocol 版本", kind: "number" },
    { key: "rtt", label: "握手超时", kind: "number" },
    { key: "provider", label: "DNS 解析提供商" },
    { key: "dns_env", label: "DNS 环境变量", kind: "password" },
    { key: "reject_unknown_sni", label: "拒绝未知 SNI", kind: "switch" },
    { key: "ticket", label: "启用会话票据", kind: "switch" },
];
const networkNested: Field["nested"] = [
    { key: "path", label: "路径" },
    { key: "host", label: "Host" },
    { key: "serviceName", label: "gRPC Service Name" },
    { key: "mode", label: "模式" },
    { key: "password", label: "传输密码", kind: "password" },
    {
        key: "security",
        label: "VMess 加密",
        kind: "select",
        options: options("auto", "aes-128-gcm", "chacha20-poly1305", "none"),
    },
];
export const commonNodeFields: Field[] = [
    f("name", "节点名称", "text", { required: true }),
    f("group_id", "权限组", "select", {
        reference: "groups",
        multiple: true,
        required: true,
        default: [],
    }),
    f("route_id", "路由", "select", {
        reference: "routes",
        multiple: true,
        default: [],
    }),
    f("parent_id", "父节点", "select", { reference: "parents" }),
    f("host", "连接地址", "text", { required: true }),
    f("port", "连接端口", "text", {
        required: true,
        default: "443",
        help: "支持当前后端允许的端口范围格式",
    }),
    f("server_port", "后端服务端口", "number", {
        required: true,
        default: 443,
    }),
    f("rate", "流量倍率", "number", { required: true, default: 1, min: 0 }),
    f("tags", "标签", "tags"),
    f("show", "显示节点", "switch", { default: 1 }),
    f("sort", "排序", "number"),
];
const network = (key = "network_settings") => [
    f("network", "传输协议", "select", {
        required: true,
        default: "tcp",
        options: options(
            "tcp",
            "kcp",
            "ws",
            "http",
            "domainsocket",
            "quic",
            "grpc",
            "httpupgrade",
            "xhttp",
        ),
    }),
    f(key, "传输协议配置", "json", { nested: networkNested }),
];
const tls = (key = "tls_settings", vmess = false) => [
    f("tls", "TLS", "select", {
        required: true,
        default: 0,
        options: vmess ? tlsOptions.slice(0, 2) : tlsOptions,
    }),
    f(key, "TLS 配置", "json", { nested: tlsNested }),
];
const tuic = [
    f("disable_sni", "禁用 SNI", "switch", { default: 0 }),
    f("udp_relay_mode", "UDP 中继模式", "select", {
        options: options("native", "quic"),
        default: "native",
    }),
    f("zero_rtt_handshake", "0-RTT 握手", "switch", { default: 0 }),
    f("congestion_control", "拥塞控制", "select", {
        options: options("cubic", "new_reno", "bbr"),
        default: "bbr",
    }),
];
const hysteria = [
    f("up_mbps", "上传带宽 Mbps", "number"),
    f("down_mbps", "下载带宽 Mbps", "number"),
    f("obfs", "混淆类型"),
    f("obfs_password", "混淆密码", "password"),
];
const cipher = f("cipher", "加密方式", "select", {
    required: true,
    default: "aes-128-gcm",
    options: options(
        "aes-128-gcm",
        "aes-192-gcm",
        "aes-256-gcm",
        "chacha20-ietf-poly1305",
        "2022-blake3-aes-128-gcm",
        "2022-blake3-aes-256-gcm",
    ),
});
const sni = [
    f("server_name", "SNI"),
    f("insecure", "允许不安全", "switch", { default: 0, required: true }),
];
export const protocolFields: Record<string, Field[]> = {
    shadowsocks: [
        cipher,
        f("obfs", "混淆", "select", { options: options("", "http") }),
        f("obfs_settings", "混淆配置", "json", {
            nested: [
                { key: "host", label: "Host" },
                { key: "path", label: "路径" },
            ],
        }),
    ],
    vmess: [
        ...tls("tlsSettings", true),
        ...network("networkSettings"),
        f("ruleSettings", "规则配置", "json"),
        f("dnsSettings", "DNS 配置", "json"),
    ],
    trojan: [
        ...network(),
        f("server_name", "SNI"),
        f("allow_insecure", "允许不安全", "switch", { default: 0 }),
    ],
    tuic: [...sni, ...tuic],
    hysteria: [
        f("version", "Hysteria 版本", "select", {
            required: true,
            default: 2,
            options: options(1, 2),
        }),
        ...sni,
        ...hysteria,
    ],
    vless: [
        ...tls(),
        f("flow", "Flow", "select", {
            options: options("", "xtls-rprx-vision"),
        }),
        ...network(),
        f("encryption", "加密方式"),
        f("encryption_settings", "加密配置", "json"),
    ],
    anytls: [
        ...sni,
        f("padding_scheme", "填充方案", "json", {
            help: 'JSON 数组，例如 ["stop=8", "0=30-30"]',
        }),
    ],
    v2node: [
        f("protocol", "节点协议", "select", {
            required: true,
            default: "vmess",
            options: options(
                "shadowsocks",
                "vmess",
                "vless",
                "trojan",
                "tuic",
                "hysteria2",
                "anytls",
            ),
        }),
        f("listen_ip", "监听地址"),
        ...tls(),
        f("flow", "Flow", "select", {
            options: options("", "xtls-rprx-vision"),
        }),
        ...network(),
        f("trusted_x_forwarded_for", "可信转发 IP", "tags"),
        f("encryption", "加密方式"),
        f("encryption_settings", "加密配置", "json"),
        { ...cipher, required: false },
        ...tuic,
        ...hysteria,
        f("padding_scheme", "填充方案", "json"),
    ],
};
export const nodeFields = (type: string) =>
    [...commonNodeFields, ...(protocolFields[type] ?? [])].map((field) =>
        type === "v2node" && field.key === "network"
            ? {
                  ...field,
                  options: options(
                      "tcp",
                      "ws",
                      "grpc",
                      "http",
                      "httpupgrade",
                      "xhttp",
                  ),
              }
            : field,
    );
