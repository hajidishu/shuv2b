import { Button, DatePicker, Divider, Input, Select } from "antd";
import { DeleteOutlined, PlusOutlined } from "@ant-design/icons";
import dayjs from "dayjs";
import { cents } from "./domain.mjs";
import { filterKeys, labels } from "./schema/resources";
import type { Filter, Row } from "./types";

type Option = { label: string; value: any };
const originalKeys = [
    "email",
    "id",
    "plan_id",
    "transfer_enable",
    "d",
    "expired_at",
    "uuid",
    "token",
    "banned",
    "invite_by_email",
    "invite_user_id",
    "remarks",
    "is_admin",
];
const defaultKeys = [
    ...originalKeys,
    ...filterKeys.filter((key) => !originalKeys.includes(key)),
];
const originalLabels: Record<string, string> = {
    id: "用户ID",
    plan_id: "订阅",
    transfer_enable: "流量",
    d: "下行",
    token: "TOKEN",
    banned: "账号状态",
    invite_user_id: "邀请人ID",
};
const booleanOptions = [
    { label: "是", value: 1 },
    { label: "否", value: 0 },
];
const dates = new Set([
    "expired_at",
    "created_at",
    "last_login_at",
    "requested_at",
]);
const text = new Set([
    "email",
    "invite_by_email",
    "remarks",
    "trade_no",
    "callback_no",
    "ip",
    "ua",
    "format",
]);

export function Filters({
    value,
    onChange,
    keys = defaultKeys,
    valueOptions = {},
    fieldLabels = {},
    references = [],
}: {
    value: Filter[];
    onChange: (filters: Filter[]) => void;
    keys?: string[];
    valueOptions?: Record<string, Option[]>;
    fieldLabels?: Record<string, string>;
    references?: Row | Row[];
}) {
    const plans: Row[] = Array.isArray(references)
        ? references
        : (references.plans ?? []);
    const enums: Record<string, Option[]> = {
        plan_id: [
            { label: "无订阅", value: "null" },
            ...plans.map((plan) => ({ label: plan.name, value: plan.id })),
        ],
        banned: [
            { label: "正常", value: 0 },
            { label: "封禁", value: 1 },
        ],
        is_admin: booleanOptions,
        is_staff: booleanOptions,
        auto_renewal: booleanOptions,
        commission_type: [
            { label: "跟随系统设置", value: 0 },
            { label: "循环返利", value: 1 },
            { label: "首次返利", value: 2 },
        ],
        ...valueOptions,
    };
    const conditions = (key: string) =>
        enums[key] || ["uuid", "token"].includes(key)
            ? ["=", "!="]
            : text.has(key)
              ? ["模糊", "=", "!="]
              : ["transfer_enable", "d", "expired_at"].includes(key)
                ? [">=", ">", "<", "<=", "=", "!="]
                : ["=", ">=", ">", "<", "<=", "!="];
    const update = (index: number, patch: Partial<Filter>) =>
        onChange(
            value.map((filter, i) =>
                i === index ? { ...filter, ...patch } : filter,
            ),
        );
    return (
        <div className="original-filter-controls">
            {value.map((filter, index) => {
                const options = enums[filter.key],
                    selected =
                        options?.find(
                            (option) =>
                                String(option.value) === String(filter.value),
                        )?.value ?? filter.value;
                return (
                    <div className="filter-condition" key={index}>
                        <Divider>
                            条件{index + 1}{" "}
                            <Button
                                type="text"
                                size="small"
                                danger
                                aria-label={"移除条件 " + (index + 1)}
                                icon={<DeleteOutlined />}
                                onClick={() =>
                                    onChange(
                                        value.filter((_, i) => i !== index),
                                    )
                                }
                            />
                        </Divider>
                        <div className="filter-control">
                            <label>字段名</label>
                            <Select
                                aria-label={"筛选字段 " + (index + 1)}
                                showSearch
                                optionFilterProp="label"
                                virtual={false}
                                value={filter.key}
                                options={keys.map((key) => ({
                                    value: key,
                                    label:
                                        fieldLabels[key] ??
                                        originalLabels[key] ??
                                        labels[key] ??
                                        key,
                                }))}
                                onChange={(key) =>
                                    update(index, {
                                        key,
                                        condition: conditions(key)[0],
                                        value: "",
                                    })
                                }
                            />
                        </div>
                        <div className="filter-control">
                            <label>条件</label>
                            <Select
                                aria-label={"比较条件 " + (index + 1)}
                                value={filter.condition}
                                options={conditions(filter.key).map(
                                    (condition) => ({
                                        label: condition,
                                        value: condition,
                                    }),
                                )}
                                onChange={(condition) =>
                                    update(index, { condition })
                                }
                            />
                        </div>
                        <div className="filter-control">
                            <label>欲检索内容</label>
                            {options ? (
                                <Select
                                    aria-label={"筛选值 " + (index + 1)}
                                    showSearch
                                    optionFilterProp="label"
                                    placeholder="请选择"
                                    value={
                                        filter.value === ""
                                            ? undefined
                                            : selected
                                    }
                                    options={options}
                                    onChange={(value) =>
                                        update(index, { value })
                                    }
                                />
                            ) : dates.has(filter.key) ? (
                                <DatePicker
                                    aria-label={"筛选值 " + (index + 1)}
                                    showTime
                                    format="YYYY-MM-DD HH:mm:ss"
                                    value={
                                        filter.value === ""
                                            ? null
                                            : dayjs.unix(Number(filter.value))
                                    }
                                    onChange={(date) =>
                                        update(index, {
                                            value: date?.unix() ?? "",
                                        })
                                    }
                                    placeholder="请选择日期时间"
                                />
                            ) : (
                                <Input
                                    aria-label={"筛选值 " + (index + 1)}
                                    inputMode={
                                        filter.key === "subscribe_count"
                                            ? "numeric"
                                            : undefined
                                    }
                                    placeholder={
                                        filter.key === "subscribe_count"
                                            ? "次数（非负整数）"
                                            : [
                                                    "balance",
                                                    "commission_balance",
                                                ].includes(filter.key)
                                              ? "金额（元）"
                                              : "请输入欲检索内容"
                                    }
                                    value={filter.value}
                                    onChange={(event) =>
                                        update(index, {
                                            value: event.target.value,
                                        })
                                    }
                                />
                            )}
                        </div>
                    </div>
                );
            })}
            <Button
                type="primary"
                block
                icon={<PlusOutlined />}
                aria-label="添加筛选条件"
                onClick={() =>
                    onChange([
                        ...value,
                        {
                            key: keys[0],
                            condition: conditions(keys[0])[0],
                            value: "",
                        },
                    ])
                }
            >
                添加条件
            </Button>
        </div>
    );
}

export function convertFilters(filters: Filter[]): Filter[] {
    return filters.map((filter) => {
        if (filter.value === "" || filter.value == null)
            throw new Error("请填写所有筛选值");
        return {
            ...filter,
            value: ["balance", "commission_balance"].includes(filter.key)
                ? cents(filter.value)
                : filter.value,
        };
    });
}
