import test from "node:test";
import assert from "node:assert/strict";
import {
    cents,
    yuan,
    encodeQuery,
    nodeKey,
    batchPayload,
    escapeCsv,
    routes,
    referenceValue,
    findReference,
    isReferenceField,
} from "../src/domain.mjs";
test("关联ID兼容数字和旧字符串，不修改原数组或非数字标识", () => {
    const values = ["13", "7"];
    assert.deepEqual(referenceValue(values), [13, 7]);
    assert.deepEqual(values, ["13", "7"]);
    for (const value of [null, undefined, "", "none", "9007199254740993"]) assert.equal(referenceValue(value), value);
    assert.equal(referenceValue("0"), 0);
    assert.equal(referenceValue(" 13 "), 13);
    const numeric = [{ id: 13, name: "权限组" }], strings = [{ id: "13", name: "权限组" }];
    assert.equal(findReference(numeric, "13"), numeric[0]);
    assert.equal(findReference(strings, 13), strings[0]);
    assert.equal(findReference(numeric, "14"), undefined);
    assert.equal(isReferenceField({ key: "route_id", reference: "routes" }), true);
    assert.equal(isReferenceField({ key: "public_plan_ids", kind: "select" }), true);
    assert.equal(isReferenceField({ key: "telegram_bot_admin_id", kind: "text" }), false);
});
test("金额转换保留 0、分和大金额，不接受隐式舍入", () => {
    for (const [value, expected] of [
        ["0", 0],
        ["0.01", 1],
        ["19.99", 1999],
        ["0.29", 29],
        ["1234567.89", 123456789],
        ["-2.01", -201],
    ])
        assert.equal(cents(value), expected);
    assert.equal(cents(""), null);
    assert.equal(yuan(1999), "19.99");
    for (const value of ["1.001", "1e3", "NaN", "Infinity", "9007199254740992"])
        assert.throws(() => cents(value));
});
test("筛选查询保留零值、比较条件和跨分页参数", () => {
    const q = encodeQuery({
        filter: [
            { key: "balance", condition: ">=", value: 0 },
            { key: "commission_balance", condition: "<", value: 500 },
        ],
        ids: [1, 3],
        current: 2,
    });
    assert.equal(q.get("filter[0][value]"), "0");
    assert.equal(q.get("filter[1][value]"), "500");
    assert.equal(q.get("ids[1]"), "3");
    assert.equal(q.get("current"), "2");
    assert.equal(q.get("filter[0][condition]"), ">=");
});
test("同 ID 不同协议分别选择，重复项和空选择不能批量执行", () => {
    const rows = [
        { type: "vmess", id: 1 },
        { type: "vless", id: 1 },
    ];
    assert.notEqual(nodeKey(rows[0]), nodeKey(rows[1]));
    assert.deepEqual(batchPayload(rows, "update", { show: 0 }), {
        nodes: rows,
        action: "update",
        changes: { show: 0 },
    });
    assert.throws(() => batchPayload([], "delete"));
    assert.throws(() => batchPayload([rows[0], rows[0]], "delete"));
    assert.throws(() =>
        batchPayload(
            Array.from({ length: 201 }, (_, id) => ({ type: "vmess", id })),
            "copy",
        ),
    );
});
test("CSV 转义避免公式执行和字段错位", () => {
    assert.equal(escapeCsv('a,"b"'), '"a,""b"""');
    assert.equal(escapeCsv("=1+1"), '"\'=1+1"');
});
test("原有页面入口保留并增加订阅审计", () => {
    assert.equal(new Set(routes).size, 21);
    assert.ok(routes.includes("/subscribe-audit"));
});
