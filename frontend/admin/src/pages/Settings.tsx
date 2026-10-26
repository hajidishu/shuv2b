import { Alert } from "antd";
import { useRef } from "react";
import { Collapse } from "antd";
import { ErrorNotice } from "../components";
import uiMetadata from "../schema/config-ui.json";
import { useEffect, useState } from "react";
import { App, Button, Card, Form, Input, Space, Tabs } from "antd";
import { api } from "../api";
import {
    DataCard,
    displayValues,
    Editor,
    Fields,
    Page,
    payloadValues,
    useAction,
    useResource,
} from "../components";
import defaults from "../schema/config.json";
import type { Field, Row } from "../types";
import PasskeyDeployment from "./PasskeyDeployment";
const configLabels: Record<string, string> = {
    ticket_status: "工单开关",
    deposit_bounus: "充值奖励（充值金额:奖励金额）",
    invite_force: "强制邀请码",
    invite_commission: "默认佣金比例 %",
    invite_gen_limit: "邀请码生成数量",
    invite_never_expire: "邀请码永不过期",
    commission_first_time_enable: "仅首次购买返佣",
    commission_auto_check_enable: "佣金自动审核",
    commission_withdraw_limit: "最低提现金额",
    commission_withdraw_method: "提现方式",
    withdraw_close_enable: "关闭提现",
    commission_distribution_enable: "三级分销",
    commission_distribution_l1: "一级分佣比例",
    commission_distribution_l2: "二级分佣比例",
    commission_distribution_l3: "三级分佣比例",
    logo: "LOGO 地址",
    force_https: "强制 HTTPS",
    stop_register: "关闭注册",
    app_name: "站点名称",
    app_description: "站点描述",
    app_url: "站点地址",
    subscribe_url: "订阅地址",
    subscribe_path: "订阅路径",
    try_out_enable: "开启试用",
    try_out_plan_id: "试用套餐 ID",
    try_out_hour: "试用时长（小时）",
    tos_url: "服务条款地址",
    currency: "货币代码",
    currency_symbol: "货币符号",
    plan_change_enable: "允许更换套餐",
    reset_traffic_method: "默认流量重置方式",
    surplus_enable: "开启套餐折抵",
    allow_new_period: "允许购买其他周期",
    new_order_event_id: "新购事件",
    renew_order_event_id: "续费事件",
    change_order_event_id: "换套餐事件",
    show_info_to_server_enable: "向节点显示用户信息",
    show_subscribe_method: "订阅信息显示方式",
    show_subscribe_expire: "提前显示到期天数",
    frontend_theme: "用户前台主题",
    frontend_theme_sidebar: "后台侧栏风格",
    frontend_theme_header: "后台顶栏风格",
    frontend_theme_color: "后台主题色",
    frontend_background_url: "登录背景地址",
    server_api_url: "节点 API 地址",
    server_token: "节点通讯密钥",
    server_pull_interval: "节点拉取间隔（秒）",
    server_push_interval: "节点上报间隔（秒）",
    server_node_report_min_traffic: "节点最小上报流量",
    server_device_online_min_traffic: "设备在线最小流量",
    device_limit_mode: "设备限制模式",
    email_template: "邮件模板",
    email_host: "SMTP 主机",
    email_port: "SMTP 端口",
    email_username: "SMTP 用户名",
    email_password: "SMTP 密码",
    email_encryption: "SMTP 加密",
    email_from_address: "发件地址",
    telegram_bot_enable: "启用 Telegram 机器人",
    telegram_bot_token: "Telegram Bot Token",
    telegram_discuss_link: "Telegram 群组链接",
    telegram_discuss_id: "Telegram 群组 ID",
    telegram_channel_id: "Telegram 频道 ID",
    windows_version: "Windows 版本",
    windows_download_url: "Windows 下载地址",
    macos_version: "macOS 版本",
    macos_download_url: "macOS 下载地址",
    android_version: "Android 版本",
    android_download_url: "Android 下载地址",
    email_verify: "注册邮箱验证",
    safe_mode_enable: "安全模式",
    secure_path: "管理员入口路径",
    email_whitelist_enable: "邮箱白名单",
    email_whitelist_suffix: "邮箱白名单后缀",
    email_gmail_limit_enable: "限制 Gmail 别名",
    recaptcha_enable: "启用验证码",
    recaptcha_key: "验证码私钥",
    recaptcha_site_key: "验证码站点密钥",
    register_limit_by_ip_enable: "注册 IP 限制",
    register_limit_count: "注册次数限制",
    register_limit_expire: "注册限制时间（分钟）",
    password_limit_enable: "密码尝试限制",
    password_limit_count: "密码尝试次数",
    password_limit_expire: "密码限制时间（分钟）",
};
const groupLabels: Record<string, string> = {
    site: "站点",
    subscribe: "订阅",
    invite: "邀请与佣金",
    deposit: "充值",
    ticket: "工单",
    server: "节点",
    frontend: "界面",
    email: "邮件",
    telegram: "Telegram",
    app: "客户端",
    safe: "安全",
};
const toggleKeys = new Set([
    "passkey_enabled",
    "invite_force",
    "invite_never_expire",
    "commission_first_time_enable",
    "commission_auto_check_enable",
    "withdraw_close_enable",
    "commission_distribution_enable",
    "force_https",
    "stop_register",
    "try_out_enable",
    "plan_change_enable",
    "surplus_enable",
    "allow_new_period",
    "show_info_to_server_enable",
    "telegram_bot_enable",
    "email_verify",
    "safe_mode_enable",
    "email_whitelist_enable",
    "email_gmail_limit_enable",
    "recaptcha_enable",
    "register_limit_by_ip_enable",
    "password_limit_enable",
]);
const hiddenConfigFields = new Set([
    // Managed on the separate theme page or absent from the recovered legacy form.
    "frontend_theme",
    "recaptcha_enable",
    "try_out_enable",
    "telegram_discuss_id",
    "telegram_channel_id",
]);
const configUiOverrides: Row = {
    windows_download_url: {
        label: "Windows 下载地址",
        help: "Windows 端版本号及下载地址。",
        placeholder: "https://xxxx.com/xxx.exe",
        order: 74.5,
    },
    macos_download_url: {
        label: "macOS 下载地址",
        help: "macOS 端版本号及下载地址。",
        placeholder: "https://xxxx.com/xxx.dmg",
        order: 75.5,
    },
    android_download_url: {
        label: "Android 下载地址",
        help: "Android 端版本号及下载地址。",
        placeholder: "https://xxxx.com/xxx.apk",
        order: 76.5,
    },
};
export default function Settings() {
    const config = useResource("/config/fetch"),
        templates = useResource("/config/getEmailTemplate"),
        themes = useResource("/config/getThemeTemplate"),
        plans = useResource("/plan/fetch");
    const act = useAction();
    const [group, setGroup] = useState("site"),
        [webhook, setWebhook] = useState(false),
        [saveState, setSaveState] = useState(""),
        [error, setError] = useState("");
    const [form] = Form.useForm();
    const formValues = Form.useWatch([], form) ?? {};
    const pending = useRef<Row>({}),
        saved = useRef<Row>({}),
        timer = useRef<ReturnType<typeof setTimeout>>(),
        queue = useRef(Promise.resolve());
    const tabOrder = [
        "site",
        "safe",
        "subscribe",
        "deposit",
        "ticket",
        "invite",
        "frontend",
        "server",
        "email",
        "telegram",
        "app",
    ];
    const captions: Row = {
        site: "站点",
        safe: "安全",
        subscribe: "订阅",
        deposit: "充值",
        ticket: "工单",
        invite: "邀请&佣金",
        frontend: "个性化",
        server: "节点",
        email: "邮件",
        telegram: "Telegram",
        app: "APP",
    };
    const values = {
        ...(config.data?.[group] ?? (defaults as Row)[group] ?? {}),
        ...saved.current,
    };
    const meta = uiMetadata as Row;
    const fieldMeta = (key: string) => meta[key] ?? configUiOverrides[key];
    const allFields: Field[] = Object.keys((defaults as Row)[group] ?? {})
        .filter((key) => !hiddenConfigFields.has(key))
        .sort(
            (a, b) =>
                (fieldMeta(a)?.order ?? 999) - (fieldMeta(b)?.order ?? 999),
        )
        .map((key) => {
            const val = (defaults as Row)[group][key];
            const fieldMetaEntry = fieldMeta(key);
            const field: Field = {
                key,
                label: fieldMetaEntry?.label ?? configLabels[key] ?? key,
                help: fieldMetaEntry?.help,
                placeholder: fieldMetaEntry?.placeholder,
                kind: Array.isArray(val)
                    ? "tags"
                    : toggleKeys.has(key)
                      ? "switch"
                      : typeof val === "number"
                        ? "number"
                        : /password|token|recaptcha_key|turnstile_secret_key/.test(key)
                          ? "password"
                          : "text",
            };
            const enumOpts: Row = {
                ticket_status: ["完全开放", "仅限付费用户", "关闭工单"],
                device_limit_mode: ["按节点限制", "全局限制"],
                reset_traffic_method: [
                    "每月一日",
                    "按月重置",
                    "不重置",
                    "每年一月一日",
                    "按年重置",
                ],
                show_subscribe_method: ["永久有效", "一次性有效", "限时有效"],
                new_order_event_id: ["不执行任何动作", "重置用户流量"],
                renew_order_event_id: ["不执行任何动作", "重置用户流量"],
                change_order_event_id: ["不执行任何动作", "重置用户流量"],
                frontend_theme_sidebar: ["light", "dark"],
                frontend_theme_header: ["light", "dark"],
                frontend_theme_color: ["default", "darkblue", "black", "green"],
                email_encryption: ["", "ssl", "tls"],
                email_template: templates.data,
                frontend_theme: themes.data,
            };
            if (enumOpts[key]) {
                field.kind = "select";
                field.options = enumOpts[key].map(
                    (label: string, i: number) => ({
                        label,
                        value: [
                            "ticket_status",
                            "device_limit_mode",
                            "reset_traffic_method",
                            "show_subscribe_method",
                            "new_order_event_id",
                            "renew_order_event_id",
                            "change_order_event_id",
                        ].includes(key)
                            ? i
                            : label.replace(/\.blade\.php$/, ""),
                    }),
                );
            }
            if (key === "try_out_plan_id") {
                field.kind = "select";
                field.options = [
                    { label: "关闭", value: 0 },
                    ...(plans.data ?? []).map((p: Row) => ({
                        label: p.name,
                        value: p.id,
                    })),
                ];
            }
            if (key === "captcha_provider") {
                field.kind = "select";
                field.options = [
                    { label: "关闭", value: "none" },
                    { label: "Google reCAPTCHA", value: "recaptcha" },
                    { label: "Cloudflare Turnstile", value: "turnstile" },
                ];
            }
            if (key === "subscribe_url") field.kind = "textarea";
            return field;
        });
    const fields = allFields.filter((field) => {
        const current = { ...values, ...formValues };
        const dependent: Row = {
            email_whitelist_suffix: "email_whitelist_enable",
            register_limit_count: "register_limit_by_ip_enable",
            register_limit_expire: "register_limit_by_ip_enable",
            password_limit_count: "password_limit_enable",
            password_limit_expire: "password_limit_enable",
            commission_distribution_l1: "commission_distribution_enable",
            commission_distribution_l2: "commission_distribution_enable",
            commission_distribution_l3: "commission_distribution_enable",
        };
        if (["recaptcha_key", "recaptcha_site_key"].includes(field.key)) return current.captcha_provider === "recaptcha";
        if (["turnstile_site_key", "turnstile_secret_key"].includes(field.key)) return current.captcha_provider === "turnstile";
        if (field.key === "try_out_hour")
            return Number(current.try_out_plan_id) !== 0;
        if (field.key === "show_subscribe_expire")
            return Number(current.show_subscribe_method) === 2;
        return !dependent[field.key] || !!Number(current[dependent[field.key]]);
    });
    useEffect(() => {
        form.resetFields();
        form.setFieldsValue(displayValues(allFields, values));
    }, [config.data, group]);
    const flush = () => {
        if (timer.current) clearTimeout(timer.current);
        const payload = pending.current;
        if (!Object.keys(payload).length) return;
        pending.current = {};
        setSaveState("保存中");
        queue.current = queue.current.then(async () => {
            try {
                await api("/config/save", payload, "POST");
                setError("");
                setSaveState("已保存");
                if (
                    payload.secure_path &&
                    payload.secure_path !== window.settings?.secure_path
                )
                    location.assign(
                        "/" + payload.secure_path + "#/config/system",
                    );
            } catch (e) {
                pending.current = { ...payload, ...pending.current };
                setError((e as Error).message);
                setSaveState("保存失败");
            }
        });
    };
    useEffect(() => () => flush(), []);
    const changed = (v: Row) => {
        try {
            const payload = payloadValues(fields, v);
            Object.assign(saved.current, payload);
            pending.current = {
                ...pending.current,
                ...payload,
            };
            setSaveState("尚未保存");
            if (timer.current) clearTimeout(timer.current);
            timer.current = setTimeout(flush, 1500);
        } catch (e) {
            setError((e as Error).message);
        }
    };
    const regular = fields.filter((f) => fieldMeta(f.key)),
        advanced = fields.filter((f) => !fieldMeta(f.key));
    const emailTest = () =>
        act.modal.confirm({
            title: "向当前管理员发送测试邮件？",
            onOk: () =>
                api("/config/testSendMail", {}, "POST").then(() =>
                    act.message.success("测试邮件已发送"),
                ),
        });
    const renderSettingsFields = (items: Field[]) => {
        if (group === "app") {
            const platforms = [
                {
                    key: "windows_version",
                    title: "Windows",
                    description: "Windows端版本号及下载地址",
                    url: "windows_download_url",
                    placeholder: "https://xxxx.com/xxx.exe",
                },
                {
                    key: "macos_version",
                    title: "macOS",
                    description: "macOS端版本号及下载地址",
                    url: "macos_download_url",
                    placeholder: "https://xxxx.com/xxx.dmg",
                },
                {
                    key: "android_version",
                    title: "Android",
                    description: "Android端版本号及下载地址",
                    url: "android_download_url",
                    placeholder: "https://xxxx.com/xxx.apk",
                },
            ];
            return (
                <div className="settings-fields">
                    {platforms.map((platform) => (
                        <Form.Item
                            key={platform.key}
                            label={
                                <div className="settings-label">
                                    <strong>{platform.title}</strong>
                                    <span>{platform.description}</span>
                                </div>
                            }
                        >
                            <Space
                                direction="vertical"
                                size={6}
                                style={{ width: "100%" }}
                            >
                                <Form.Item name={platform.key} noStyle>
                                    <Input placeholder="1.0.0" />
                                </Form.Item>
                                <Form.Item name={platform.url} noStyle>
                                    <Input placeholder={platform.placeholder} />
                                </Form.Item>
                            </Space>
                        </Form.Item>
                    ))}
                </div>
            );
        }
        if (group === "telegram") {
            const webhookIndex = items.findIndex(
                (field) => field.key === "telegram_webhook_domain",
            );
            if (webhookIndex >= 0) {
                return (
                    <>
                        <Fields
                            settings
                            fields={items.slice(0, webhookIndex + 1)}
                        />
                        {!!(
                            formValues.telegram_bot_token ??
                            values.telegram_bot_token
                        ) && (
                            <div className="settings-fields">
                                <Form.Item
                                    label={
                                        <div className="settings-label">
                                            <strong>设置 Webhook</strong>
                                            <span>
                                                使用上方填写的域名接收机器人指令。
                                            </span>
                                        </div>
                                    }
                                >
                                    <Button
                                        onClick={() => {
                                            flush();
                                            setWebhook(true);
                                        }}
                                    >
                                        一键设置
                                    </Button>
                                </Form.Item>
                            </div>
                        )}
                        <Fields
                            settings
                            fields={items.slice(webhookIndex + 1)}
                        />
                    </>
                );
            }
        }
        if (group === "email") {
            const templateIndex = items.findIndex(
                (field) => field.key === "email_template",
            );
            if (templateIndex >= 0) {
                return (
                    <>
                        <Fields
                            settings
                            fields={items.slice(0, templateIndex + 1)}
                        />
                        <div className="settings-fields">
                            <Form.Item
                                label={
                                    <div className="settings-label">
                                        <strong>发送测试邮件</strong>
                                        <span>
                                            邮件将会发送到当前登录用户邮箱。
                                        </span>
                                    </div>
                                }
                            >
                                <Button onClick={emailTest}>
                                    发送测试邮件
                                </Button>
                            </Form.Item>
                        </div>
                        <Fields
                            settings
                            fields={items.slice(templateIndex + 1)}
                        />
                    </>
                );
            }
        }
        return <Fields settings fields={items} />;
    };
    return (
        <Page title="系统配置">
            <Card className="settings-card">
                <ErrorNotice error={config.error || error} />
                <Tabs
                    tabBarStyle={{ margin: 0 }}
                    activeKey={group}
                    onChange={(key) => {
                        flush();
                        setGroup(key);
                    }}
                    items={tabOrder.map((key) => ({
                        key,
                        label: captions[key],
                    }))}
                />
                <Form
                    layout="horizontal"
                    colon={false}
                    form={form}
                    onValuesChange={changed}
                >
                    {group === "frontend" && (
                        <Alert
                            className="settings-notice"
                            type="warning"
                            message={
                                <span>
                                    如果你采用前后分离的方式部署 V2Board
                                    管理端，那么本页配置将不会生效。了解{" "}
                                    <a href="https://docs.v2board.com/use/advanced.html#%E5%89%8D%E7%AB%AF%E5%88%86%E7%A6%BB">
                                        前后分离
                                    </a>
                                </span>
                            }
                        />
                    )}
                    {group === "app" && (
                        <Alert
                            className="settings-notice"
                            type="warning"
                            message="用于自有客户端(APP)的版本管理及更新"
                        />
                    )}
                    {renderSettingsFields(regular.length ? regular : fields)}
                    {regular.length > 0 && advanced.length > 0 && (
                        <Collapse
                            ghost
                            items={[
                                {
                                    key: "advanced",
                                    label: "高级设置",
                                    children: renderSettingsFields(advanced),
                                },
                            ]}
                        />
                    )}
                    {group === "safe" && (
                        <PasskeyDeployment
                            beforeLoad={async () => {
                                flush();
                                await queue.current;
                                if (Object.keys(pending.current).length)
                                    throw new Error(
                                        "配置尚未保存成功，请先重试保存",
                                    );
                            }}
                        />
                    )}
                </Form>
                <div className="settings-save-state">
                    <span>{saveState}</span>
                    <Space>
                        {error && (
                            <Button
                                onClick={() => {
                                    flush();
                                }}
                            >
                                重试保存
                            </Button>
                        )}
                    </Space>
                </div>
            </Card>
            {webhook && (
                <Editor
                    title="设置 Telegram Webhook"
                    fields={[
                        {
                            key: "telegram_bot_token",
                            label: "Bot Token",
                            kind: "password",
                            required: true,
                        },
                        {
                            key: "telegram_webhook_domain",
                            label: "Webhook 域名",
                            kind: "text",
                            required: true,
                            placeholder: "https://bot.example.com",
                            help: "填写 HTTPS 域名，回调路径由系统自动追加。",
                        },
                    ]}
                    initial={{
                        telegram_bot_token:
                            formValues.telegram_bot_token ??
                            values.telegram_bot_token,
                        telegram_webhook_domain:
                            formValues.telegram_webhook_domain ??
                            values.telegram_webhook_domain,
                    }}
                    close={() => setWebhook(false)}
                    save={async (v) => {
                        flush();
                        await queue.current;
                        await api("/config/save", v, "POST");
                        for (const key of Object.keys(v))
                            delete pending.current[key];
                        saved.current = { ...saved.current, ...v };
                        form.setFieldsValue(v);
                        setError("");
                        setSaveState("已保存");
                        config.reload();
                        await api("/config/setTelegramWebhook", {}, "POST");
                        act.message.success("Webhook 已设置");
                    }}
                />
            )}
        </Page>
    );
}

export function Themes() {
    const themes = useResource("/theme/getThemes");
    const [selected, setSelected] = useState(""),
        [config, setConfig] = useState<Row>({}),
        [error, setError] = useState(""),
        [editing, setEditing] = useState(false);
    const act = useAction();
    useEffect(() => {
        if (themes.data?.active) setSelected(themes.data.active);
    }, [themes.data]);
    const info = themes.data?.themes?.[selected] ?? {};
    const fields: Field[] = (info.configs ?? []).map((f: Row) => ({
        key: f.field_name,
        label: f.label ?? f.field_label ?? f.field_name,
        kind:
            f.field_type === "textarea"
                ? "textarea"
                : f.field_type === "switch"
                  ? "switch"
                  : f.field_type === "select"
                    ? "select"
                    : "text",
        help: f.description,
        options: Array.isArray(f.select_options ?? f.options)
            ? (f.select_options ?? f.options)
            : Object.entries(f.select_options ?? f.options ?? {}).map(
                  ([value, label]) => ({ value, label: String(label) }),
              ),
    }));
    return (
        <Page title="主题配置">
            <Alert
                className="theme-notice"
                type="warning"
                message={
                    <span>
                        如果你采用前后分离的方式部署V2board，那么主题配置将不会生效。了解{" "}
                        <b>
                            <a href="https://docs.v2board.com/use/advanced.html#%E5%89%8D%E7%AB%AF%E5%88%86%E7%A6%BB">
                                前后分离
                            </a>
                        </b>
                    </span>
                }
            />
            <ErrorNotice error={themes.error || error} />
            {Object.entries(themes.data?.themes ?? {}).map(([name, theme]) => (
                <div className="theme-tile" key={name}>
                    <div>
                        <h2>{name}</h2>
                        <p>{(theme as Row).description}</p>
                    </div>
                    <Space>
                        <Button
                            shape="round"
                            ghost
                            disabled={name === themes.data?.active}
                            onClick={() =>
                                act
                                    .run(() =>
                                        api(
                                            "/config/save",
                                            { frontend_theme: name },
                                            "POST",
                                        ).then(themes.reload),
                                    )
                                    .catch(() => {})
                            }
                        >
                            {name === themes.data?.active
                                ? "当前主题"
                                : "激活主题"}
                        </Button>
                        <Button
                            shape="round"
                            ghost
                            aria-label="编辑主题"
                            loading={act.busy}
                            onClick={() =>
                                act
                                    .run(async () => {
                                        const result = await api(
                                            "/theme/getThemeConfig",
                                            { name },
                                            "POST",
                                        );
                                        setConfig(result.data ?? {});
                                        setSelected(name);
                                        setEditing(true);
                                    }, "")
                                    .catch(() => {})
                            }
                        >
                            主题设置
                        </Button>
                    </Space>
                </div>
            ))}
            {editing && (
                <Editor
                    title="主题设置"
                    presentation="modal"
                    fields={fields}
                    initial={config}
                    close={() => setEditing(false)}
                    save={async (values) => {
                        const bytes = new TextEncoder().encode(
                            JSON.stringify(values),
                        );
                        const encoded = btoa(
                            Array.from(bytes, (c) =>
                                String.fromCharCode(c),
                            ).join(""),
                        );
                        await api(
                            "/theme/saveThemeConfig",
                            { name: selected, config: encoded },
                            "POST",
                        );
                        setConfig(values);
                        act.message.success("主题已保存");
                    }}
                />
            )}
        </Page>
    );
}
