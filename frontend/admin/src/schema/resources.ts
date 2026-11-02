import type { Field } from "../types";
import { options } from "./nodes";
import { periods } from "../domain.mjs";
const f = (
    key: string,
    label: string,
    kind: Field["kind"] = "text",
    extra: Partial<Field> = {},
): Field => ({ key, label, kind, ...extra });
export const planFields: Field[] = [
    f("name", "订阅名称", "text", { required: true }),
    f("group_id", "权限组", "select", { required: true, reference: "groups" }),
    f("transfer_enable", "每期流量 GB", "number", {
        required: true,
        min: 0,
        default: 100,
    }),
    f("device_limit", "设备限制", "number"),
    f("speed_limit", "速度限制 Mbps", "number"),
    f("capacity_limit", "人数限制", "number"),
    f("reset_traffic_method", "流量重置方式", "select", {
        options: [
            { label: "跟随系统", value: null },
            ...[
                "每月一日",
                "按月重置",
                "不重置",
                "每年一月一日",
                "按年重置",
            ].map((label, value) => ({ label, value })),
        ],
    }),
    ...Object.entries(periods).map(([key, label]) =>
        f(key, label + "价格", "money", {
            min: 0,
            help: "留空表示不提供该周期，0 表示免费",
        }),
    ),
    f("content", "订阅介绍", "html"),
    f("force_update", "强制更新到用户", "switch", {
        default: 0,
        help: "将变更的流量、限速和权限组同步到该套餐下的用户",
        when: (values) => !!values.id,
    }),
];
export const userFields: Field[] = [
    f("email", "邮箱", "text", { required: true }),
    f("password", "密码", "password", { help: "留空保留原密码" }),
    f("plan_id", "订阅计划", "select", { reference: "plans" }),
    f("transfer_enable", "流量额度", "gb"),
    f("u", "已用上传", "gb"),
    f("d", "已用下载", "gb"),
    f("device_limit", "设备限制", "number"),
    f("speed_limit", "速度限制 Mbps", "number"),
    f("expired_at", "到期时间", "date"),
    f("balance", "余额", "money"),
    f("commission_balance", "佣金余额", "money"),
    f("commission_type", "佣金类型", "select", {
        options: [
            { label: "跟随系统", value: 0 },
            { label: "循环返利", value: 1 },
            { label: "首次返利", value: 2 },
        ],
    }),
    f("commission_rate", "佣金比例 %", "number"),
    f("discount", "折扣 %", "number"),
    f("invite_user_email", "邀请人邮箱"),
    f("banned", "封禁", "switch", { default: 0 }),
    f("is_admin", "管理员", "switch", { default: 0 }),
    f("is_staff", "员工", "switch", { default: 0 }),
    f("remarks", "备注", "textarea"),
];
export const generateUserFields = [
    f("email_prefix", "邮箱前缀", "text", {
        help: "单个生成时填写；批量生成时留空",
    }),
    f("email_suffix", "邮箱后缀", "text", { required: true }),
    f("generate_count", "批量生成数量", "number", { min: 1 }),
    f("password", "密码", "password"),
    f("plan_id", "订阅计划", "select", { reference: "plans" }),
    f("expired_at", "到期时间", "date"),
];
export const orderFields = [
    f("email", "用户邮箱", "text", {
        required: true,
        placeholder: "请输入用户邮箱",
    }),
    f("plan_id", "请选择订阅", "select", {
        ariaLabel: "订阅计划",
        placeholder: "请选择订阅",
        reference: "plans",
        required: true,
    }),
    f("period", "请选择周期", "select", {
        ariaLabel: "支付周期",
        placeholder: "请选择周期",
        required: true,
        options: Object.entries(periods).map(([value, label]) => ({
            value,
            label,
        })),
    }),
    f("total_amount", "支付金额", "money", {
        ariaLabel: "分配金额",
        placeholder: "请输入需要支付的金额",
        required: true,
        addonBefore: null,
        addonAfter: "¥",
    }),
];
export const cardFields = (gift = false): Field[] => [
    f("name", "名称", "text", {
        required: true,
        placeholder: gift ? "请输入礼品卡名称" : "请输入优惠券名称",
    }),
    f("code", gift ? "自定义礼品卡卡密" : "自定义优惠券码", "text", {
        placeholder: gift
            ? "自定义礼品卡卡密(留空随机生成)"
            : "自定义优惠券码(留空随机生成)",
        when: (values) => !values.generate_count,
    }),
    f("type", "类型", "select", {
        required: true,
        default: 1,
        options: (gift
            ? ["金额", "时长", "流量", "重置流量", "套餐"]
            : ["金额折扣", "百分比折扣"]
        ).map((label, i) => ({ label, value: i + 1 })),
    }),
    f("value", gift ? "数值" : "优惠信息", "number", {
        min: 0,
        ariaLabel: "数值",
        placeholder: "请输入值",
        help: gift
            ? "金额按元填写；时长和套餐时长按天填写，流量按 GB 填写；重置类型无需填写"
            : "金额按元填写；百分比填写整数",
        when: (values) => !gift || Number(values.type) !== 4,
    }),
    ...(gift
        ? [
              f("plan_id", "指定订阅", "select", {
                  reference: "plans",
                  required: true,
                  placeholder: "指定订阅",
                  when: (values) => Number(values.type) === 5,
              }),
          ]
        : []),
    f("started_at", "开始时间", "date", {
        required: true,
    }),
    f("ended_at", "结束时间", "date", {
        required: true,
    }),
    f("limit_use", "最大使用次数", "text", {
        placeholder: "限制最大使用次数，用完则无法使用(为空则不限制)",
    }),
    ...(!gift
        ? [
              f("limit_use_with_user", "每个用户可使用次数", "text", {
                  placeholder: "限制每个用户可使用次数(为空则不限制)",
              }),
              f("limit_plan_ids", "限定套餐", "tags", {
                  placeholder: "限制指定订阅可以使用优惠(为空则不限制)",
              }),
              f("limit_period", "限定周期", "tags", {
                  options: Object.entries(periods).map(([value, label]) => ({
                      value,
                      label,
                  })),
                  placeholder: "限制指定周期可以使用优惠(为空则不限制)",
              }),
          ]
        : []),
    f("generate_count", "批量生成数量", "number", {
        min: 1,
        placeholder: "输入数量批量生成",
        when: (values) => !values.id && !values.code,
    }),
];
export const resourceSchemas: Record<
    string,
    {
        title: string;
        add: string;
        base: string;
        save: string;
        fields: Field[];
        columns: string[];
        toggle?: string;
        sort?: string;
        detail?: boolean;
    }
> = {
    "/plan": {
        title: "订阅管理",
        add: "添加订阅",
        base: "/plan",
        save: "/plan/save",
        fields: planFields,
        columns: [
            "show",
            "renew",
            "name",
            "count",
            "group_name",
            "transfer_enable",
            "device_limit",
            "month_price",
            "quarter_price",
            "half_year_price",
            "year_price",
            "two_year_price",
            "three_year_price",
            "onetime_price",
            "reset_price",
        ],
        sort: "plan_ids",
    },
    "/server/group": {
        title: "权限组管理",
        add: "添加权限组",
        base: "/server/group",
        save: "/server/group/save",
        fields: [f("name", "名称", "text", { required: true })],
        columns: ["id", "name", "user_count", "server_count"],
    },
    "/server/route": {
        title: "路由管理",
        add: "添加路由",
        base: "/server/route",
        save: "/server/route/save",
        fields: [
            f("remarks", "备注", "text", {
                required: true,
                placeholder: "请输入备注",
            }),
            f("match", "匹配值", "lines", {
                help: "填写域名、IP、端口或协议匹配值；默认出站不需要匹配规则。",
                rows: 5,
                placeholder: "请输入匹配值",
                helpLink: {
                    label: "路由规则参考",
                    href: "https://xtls.github.io/config/routing.html#ruleobject",
                },
                when: (values) => values.action !== "default_out",
            }),
            f("action", "动作", "select", {
                required: true,
                default: "block",
                placeholder: "请选择动作",
                options: [
                    ["block", "禁止访问(域名目标)"],
                    ["block_ip", "禁止访问(IP目标)"],
                    ["block_port", "禁止访问(端口目标)"],
                    ["protocol", "禁止访问(协议)"],
                    ["dns", "指定DNS服务器进行解析"],
                    ["route", "指定出站服务器(域名目标)"],
                    ["route_ip", "指定出站服务器(IP目标)"],
                    ["default_out", "自定义默认出站"],
                ].map(([value, label]) => ({ value, label })),
            }),
            f("action_value", "动作值", "textarea", {
                help: "DNS 动作填写 DNS 服务器地址；路由类动作填写 Xray 出站 JSON。",
                placeholder:
                    "填写 DNS 服务器地址或 Xray 出站 JSON（按动作类型）",
                rows: 8,
                helpLink: {
                    label: "Xray 出站配置参考",
                    href: "https://xtls.github.io/config/outbound.html",
                },
                when: (values) =>
                    ["dns", "route", "route_ip", "default_out"].includes(
                        String(values.action),
                    ),
            }),
        ],
        columns: ["id", "remarks", "match_count", "action"],
    },
    "/notice": {
        title: "公告管理",
        add: "添加公告",
        base: "/notice",
        save: "/notice/save",
        fields: [
            f("title", "标题", "text", {
                required: true,
                placeholder: "请输入公告标题",
            }),
            f("content", "公告内容", "textarea", {
                required: true,
                rows: 12,
                placeholder: "请输入公告内容",
            }),
            f("tags", "公告标签", "tags", {
                placeholder: "输入后回车添加标签",
            }),
            f("img_url", "图片URL", "text", {
                placeholder: "请输入图片URL",
            }),
        ],
        columns: ["id", "show", "title", "created_at"],
        toggle: "show",
    },
    "/knowledge": {
        title: "知识库管理",
        add: "新增",
        base: "/knowledge",
        save: "/knowledge/save",
        fields: [
            f("title", "标题", "text", {
                required: true,
                placeholder: "请输入知识标题",
            }),
            f("category", "分类", "text", {
                required: true,
                placeholder: "请输入分类，分类将会自动归集",
            }),
            f("language", "语言", "select", {
                required: true,
                default: "zh-CN",
                placeholder: "请选择知识语言",
                options: [
                    { value: "en-US", label: "English" },
                    { value: "ja-JP", label: "日本語" },
                    { value: "ko-KR", label: "한국어" },
                    { value: "vi-VN", label: "Tiếng Việt" },
                    { value: "zh-CN", label: "简体中文" },
                    { value: "zh-TW", label: "繁體中文" },
                ],
            }),
            f("body", "内容", "markdown", { required: true }),
        ],
        columns: ["id", "show", "title", "category", "language", "updated_at"],
        toggle: "show",
        sort: "knowledge_ids",
        detail: true,
    },
    "/coupon": {
        title: "优惠券管理",
        add: "添加优惠券",
        base: "/coupon",
        save: "/coupon/generate",
        fields: cardFields(),
        columns: [
            "id",
            "show",
            "name",
            "code",
            "type",
            "value",
            "limit_use",
            "started_at",
            "ended_at",
        ],
        toggle: "show",
    },
    "/giftcard": {
        title: "礼品卡管理",
        add: "添加礼品卡",
        base: "/giftcard",
        save: "/giftcard/generate",
        fields: cardFields(true),
        columns: [
            "id",
            "name",
            "type",
            "value",
            "plan_id",
            "code",
            "limit_use",
            "started_at",
            "ended_at",
        ],
    },
};
export const labels: Record<string, string> = {
    id: "ID",
    name: "名称",
    title: "标题",
    email: "邮箱",
    group_id: "权限组",
    group_name: "权限组",
    transfer_enable: "流量 GB",
    device_limit: "设备限制",
    month_price: "月付",
    quarter_price: "季付",
    half_year_price: "半年付",
    year_price: "年付",
    two_year_price: "两年付",
    three_year_price: "三年付",
    onetime_price: "一次性",
    reset_price: "重置流量",
    count: "统计",
    user_count: "用户数量",
    server_count: "节点数量",
    language: "语言",
    show: "显示",
    renew: "续费",
    remarks: "备注",
    match: "匹配值",
    action: "动作",
    action_value: "动作值",
    match_count: "匹配数量",
    tags: "标签",
    created_at: "创建时间",
    updated_at: "更新时间",
    category: "分类",
    code: "兑换码",
    type: "类型",
    value: "数值",
    plan_id: "套餐 ID",
    limit_use: "可用次数",
    started_at: "开始时间",
    ended_at: "结束时间",
    trade_no: "订单号",
    plan_name: "订阅计划",
    period: "支付周期",
    total_amount: "支付金额",
    status: "状态",
    commission_status: "佣金状态",
    commission_balance: "佣金余额",
    balance: "余额",
    commission_rate: "佣金比例",
    discount: "折扣",
    expired_at: "到期时间",
    subscribe_count: "拉取订阅次数",
    speed_limit: "速度限制",
    banned: "封禁",
    is_admin: "管理员",
    is_staff: "员工",
    uuid: "UUID",
    token: "订阅令牌",
    invite_by_email: "邀请人邮箱",
    invite_user_id: "邀请人 ID",
    u: "上传 GB",
    d: "下载 GB",
    total_used: "已用流量 GB",
    auto_renewal: "自动续费",
    last_login_at: "最后登录",
    commission_type: "佣金类型",
};
export const filterKeys = [
    "id",
    "email",
    "plan_id",
    "balance",
    "commission_balance",
    "commission_rate",
    "commission_type",
    "discount",
    "transfer_enable",
    "u",
    "d",
    "total_used",
    "device_limit",
    "speed_limit",
    "expired_at",
    "subscribe_count",
    "created_at",
    "last_login_at",
    "banned",
    "is_admin",
    "is_staff",
    "remarks",
    "uuid",
    "token",
    "invite_by_email",
    "invite_user_id",
    "auto_renewal",
];
