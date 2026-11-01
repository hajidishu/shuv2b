import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";
import ts from "typescript";

const root = resolve(import.meta.dirname, "..");
let hash = "#/dashboard";
const values = new Map();
globalThis.location = {
    get hash() { return hash; },
    set hash(value) { hash = value; },
};
globalThis.sessionStorage = {
    getItem(key) { return values.has(key) ? values.get(key) : null; },
    setItem(key, value) { values.set(key, String(value)); },
    removeItem(key) { values.delete(key); },
};
globalThis.window = { dispatchEvent() {} };

const source = await readFile(resolve(root, "src/navigation.tsx"), "utf8");
let javascript = ts.transpileModule(source, {
    compilerOptions: {
        target: ts.ScriptTarget.ES2022,
        module: ts.ModuleKind.ESNext,
        jsx: ts.JsxEmit.ReactJSX,
    },
}).outputText;
javascript = javascript.replaceAll(
    '"react/jsx-runtime"',
    JSON.stringify(pathToFileURL(resolve(root, "node_modules/react/jsx-runtime.js")).href),
);
const navigation = await import("data:text/javascript;base64," + Buffer.from(javascript).toString("base64"));

test("management links encode route/query data and keep numeric identifiers as URL strings", () => {
    assert.equal(navigation.managementHref("/user", { id: 73 }), "#/user?id=73");
    const href = navigation.managementHref("/order", { trade_no: "A/B +?#订单" });
    const parsed = new URLSearchParams(href.split("?")[1]);
    assert.equal(href.split("?")[0], "#/order");
    assert.equal(parsed.get("trade_no"), "A/B +?#订单");

    assert.equal(navigation.UserLink({ userId: 73, email: "u@example.test", children: "user" }).props.href, "#/user?id=73");
    assert.equal(navigation.UserLink({ email: "u@example.test", children: "user" }).props.href, "#/user?email=u%40example.test");
    assert.equal(navigation.OrderLink({ tradeNo: "A/7", children: "order" }).props.href, "#/order?trade_no=A%2F7");
    assert.equal(navigation.NodeLink({ nodeType: "vless", nodeId: 73, children: "node" }).props.href, "#/server/manage?type=vless&id=73");
    assert.equal(navigation.ResourceLink({ route: "/plan", id: 12, children: "plan" }).props.href, "#/plan?id=12");
});

test("URL filters use only the route whitelist, exact equality and take precedence over legacy storage", () => {
    const key = "admin-user-initial-filters";
    sessionStorage.setItem(key, JSON.stringify([{ key: "email", condition: "=", value: "legacy@example.test" }]));
    location.hash = "#/user?id=42&email=linked%2Btag%40example.test&sort=password&unknown=secret";
    assert.deepEqual(navigation.initialManagementFilters("/user", key), [
        { key: "id", condition: "=", value: "42" },
        { key: "email", condition: "=", value: "linked+tag@example.test" },
    ]);
    assert.equal(sessionStorage.getItem(key), null, "Legacy one-shot filters are consumed even when URL filters win");

    sessionStorage.setItem(key, JSON.stringify([{ key: "email", condition: "=", value: "legacy@example.test" }]));
    location.hash = "#/user/42?id=99";
    assert.deepEqual(navigation.initialManagementFilters("/user", key), [
        { key: "email", condition: "=", value: "legacy@example.test" },
    ], "A subroute must not be mistaken for the exact /user route");
    assert.equal(sessionStorage.getItem(key), null);
});

test("legacy filters survive malformed storage and a route with no whitelisted query fields", () => {
    const key = "admin-order-initial-filters";
    sessionStorage.setItem(key, JSON.stringify([{ key: "status", condition: "=", value: 1 }]));
    location.hash = "#/order?current=3&pageSize=50";
    assert.deepEqual(navigation.initialManagementFilters("/order", key), [
        { key: "status", condition: "=", value: 1 },
    ]);
    assert.equal(sessionStorage.getItem(key), null);

    sessionStorage.setItem(key, "not-json");
    assert.deepEqual(navigation.initialManagementFilters("/order", key), []);
    assert.equal(sessionStorage.getItem(key), null);
});

test("clicking the same management href refreshes the page; modified clicks preserve browser navigation", () => {
    const href = "#/user?id=42";
    location.hash = href;
    const link = navigation.ManagementLink({ href, children: "profile" });
    let prevented = 0, stopped = 0, refreshes = 0;
    window.dispatchEvent = (event) => {
        assert.equal(event.type, "admin-link-refresh");
        refreshes++;
    };
    const event = (overrides = {}) => ({
        button: 0, ctrlKey: false, metaKey: false, shiftKey: false, altKey: false,
        preventDefault() { prevented++; }, stopPropagation() { stopped++; }, ...overrides,
    });

    link.props.onClick(event());
    assert.equal(prevented, 1);
    assert.equal(stopped, 1);
    assert.equal(refreshes, 1);

    link.props.onClick(event({ ctrlKey: true }));
    assert.equal(prevented, 1, "Ctrl-click remains native so the browser can open a new tab");
    assert.equal(stopped, 2);
    assert.equal(refreshes, 1);
});
