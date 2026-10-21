import type { Field } from "../types";
import { userFields } from "./resources";

const byKey = new Map(userFields.map((field) => [field.key, field]));
const editOrder = [
    "email",
    "invite_user_email",
    "password",
    "balance",
    "commission_balance",
    "u",
    "d",
    "transfer_enable",
    "device_limit",
    "expired_at",
    "plan_id",
    "banned",
    "commission_type",
    "commission_rate",
    "discount",
    "speed_limit",
    "is_admin",
    "is_staff",
    "remarks",
];

export const commissionTypeOptions = [
    { label: "跟随系统设置", value: 0 },
    { label: "循环返利", value: 1 },
    { label: "首次返利", value: 2 },
];

const overrides: Record<string, Partial<Field>> = {
    email: { label: "邮箱", placeholder: "请输入邮箱" },
    invite_user_email: {
        label: "邀请人邮箱",
        placeholder: "请输入邀请人邮箱",
    },
    password: {
        label: "密码",
        placeholder: "如需修改密码请输入",
        help: undefined,
    },
    balance: {
        label: "余额",
        placeholder: "余额",
        ariaLabel: "余额",
    },
    commission_balance: {
        label: "推广佣金",
        placeholder: "推广佣金",
        ariaLabel: "佣金余额",
    },
    u: { label: "已用上行", placeholder: "已用上行" },
    d: { label: "已用下行", placeholder: "已用下行" },
    transfer_enable: { label: "流量", placeholder: "请输入流量" },
    device_limit: {
        label: "设备数限制",
        placeholder: "留空则不限制",
        ariaLabel: "设备限制",
    },
    expired_at: { label: "到期时间", placeholder: "长期有效" },
    plan_id: { label: "订阅计划", placeholder: "请选择用户订阅计划" },
    banned: { label: "账户状态" },
    commission_type: {
        label: "推荐返利类型",
        options: commissionTypeOptions,
    },
    commission_rate: {
        label: "推荐返利比例",
        placeholder: "请输入推荐返利比例(为空则跟随站点设置返利比例)",
    },
    discount: { label: "专享折扣比例", placeholder: "请输入专享折扣比例" },
    speed_limit: { label: "限速", placeholder: "留空则不限制" },
    is_admin: { label: "是否管理员" },
    is_staff: { label: "是否员工" },
    remarks: {
        label: "备注",
        placeholder: "请在这里记录..",
        rows: 4,
    },
};

export const userEditFields: Field[] = editOrder.map((key) => {
    const field = byKey.get(key);
    if (!field) throw new Error(`缺少用户字段定义: ${key}`);
    return { ...field, ...overrides[key] };
});
