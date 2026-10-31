export const nodeTypes = {
    shadowsocks: "Shadowsocks",
    vmess: "VMess",
    trojan: "Trojan",
    tuic: "Tuic",
    hysteria: "Hysteria",
    vless: "VLess",
    anytls: "AnyTLS",
    naive: "Naive",
    mieru: "Mieru",
    v2node: "V2node",
};
export const periods = {
    month_price: "月付",
    quarter_price: "季付",
    half_year_price: "半年付",
    year_price: "年付",
    two_year_price: "两年付",
    three_year_price: "三年付",
    onetime_price: "一次性",
    reset_price: "重置流量",
};
export const routes = [
    "/dashboard",
    "/config/system",
    "/config/payment",
    "/config/theme",
    "/server/manage",
    "/server/group",
    "/server/route",
    "/plan",
    "/order",
    "/user",
    "/notice",
    "/ticket",
    "/coupon",
    "/giftcard",
    "/knowledge",
    "/subscribe-audit",
    "/leak-probe",
    "/queue",
    "/",
    "/login",
    "/ticket/:ticket_id",
];
export function cents(value) {
    if (value === null || value === undefined || value === "") return null;
    const match = String(value).match(/^(-?)(\d+)(?:\.(\d{0,2}))?$/);
    if (!match) throw new Error("金额最多保留两位小数");
    const result =
        (Number(match[2]) * 100 + Number((match[3] ?? "").padEnd(2, "0"))) *
        (match[1] ? -1 : 1);
    if (!Number.isSafeInteger(result)) throw new Error("金额超出范围");
    return result;
}
export function yuan(value) {
    return value == null ? null : (Number(value) / 100).toFixed(2);
}
export function encodeQuery(
    value,
    prefix = "",
    params = new URLSearchParams(),
) {
    for (const [key, item] of Object.entries(value)) {
        const name = prefix ? `${prefix}[${key}]` : key;
        if (item === undefined || item === null) continue;
        if (typeof item === "object") encodeQuery(item, name, params);
        else params.append(name, String(item));
    }
    return params;
}
export function nodeKey(node) {
    return `${node.type}:${node.id}`;
}
export function batchPayload(nodes, action, changes) {
    const keys = nodes.map(nodeKey);
    if (
        !nodes.length ||
        nodes.length > 200 ||
        new Set(keys).size !== nodes.length
    )
        throw new Error("请选择 1–200 个不同节点");
    return {
        nodes: nodes.map(({ type, id }) => ({ type, id })),
        action,
        ...(action === "update" ? { changes } : {}),
    };
}
export function escapeCsv(value) {
    const text = String(value ?? "");
    return (
        '"' +
        (/^[=+@-]/.test(text) ? "'" + text : text).replaceAll('"', '""') +
        '"'
    );
}
