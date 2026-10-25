import type { MenuProps } from "antd";
import React, { useEffect, useState } from "react";
import {
    App as AntApp,
    Alert,
    Button,
    ConfigProvider,
    Drawer,
    Dropdown,
    Form,
    Input,
    Layout,
    Menu,
    Modal,
    Select,
    Space,
    Spin,
} from "antd";
import {
    BarChartOutlined,
    BlockOutlined,
    BulbOutlined,
    CommentOutlined,
    ControlOutlined,
    CreditCardOutlined,
    DashboardOutlined,
    DownOutlined,
    GiftOutlined,
    GlobalOutlined,
    HighlightOutlined,
    MenuOutlined,
    SettingOutlined,
    ShoppingOutlined,
    StarOutlined,
    SwapOutlined,
    TeamOutlined,
    ToolOutlined,
    UnorderedListOutlined,
    UserOutlined,
} from "@ant-design/icons";
import zhCN from "antd/locale/zh_CN";
import { api, session } from "./api";
import OriginalIcon from "./OriginalIcon";
import { Page } from "./components";
import { resourceSchemas } from "./schema/resources";
import Nodes from "./pages/Nodes";
import Users from "./pages/Users";
import Resources from "./pages/Resources";
import Settings, { Themes } from "./pages/Settings";
import Payments from "./pages/Payments";
import Orders from "./pages/Orders";
import SubscriptionAudit from "./pages/SubscriptionAudit";
import LeakProbe from "./pages/LeakProbe";
import Passkeys from "./pages/Passkeys";
import { passkeyAvailable, passkeyLogin } from "./passkey";
import Tickets from "./pages/Tickets";
import { Overview, Queue } from "./pages/Overview";
type UiTheme = { sidebar: string; header: string; color: string };
const sections = [
    { label: "", items: [["/dashboard", "仪表盘"]] },
    {
        label: "设置",
        items: [
            ["/config/system", "系统配置"],
            ["/config/payment", "支付配置"],
            ["/config/theme", "主题配置"],
        ],
    },
    {
        label: "服务器",
        items: [
            ["/server/manage", "节点管理"],
            ["/server/group", "权限组管理"],
            ["/server/route", "路由管理"],
        ],
    },
    {
        label: "财务",
        items: [
            ["/plan", "订阅管理"],
            ["/order", "订单管理"],
            ["/coupon", "优惠券管理"],
            ["/giftcard", "礼品卡管理"],
        ],
    },
    {
        label: "用户",
        items: [
            ["/user", "用户管理"],
            ["/notice", "公告管理"],
            ["/ticket", "工单管理"],
            ["/knowledge", "知识库管理"],
            ["/subscribe-audit", "订阅审计"],
            ["/leak-probe", "内鬼排查"],
        ],
    },
    { label: "指标", items: [["/queue", "队列监控"]] },
];
const icons: Record<string, React.ReactNode> = {
    "/dashboard": <OriginalIcon name="si-speedometer" />,
    "/config/system": <OriginalIcon name="si-equalizer" />,
    "/config/payment": <OriginalIcon name="si-credit-card" />,
    "/config/theme": <OriginalIcon name="si-magic-wand" />,
    "/server/manage": <OriginalIcon name="si-layers" />,
    "/server/group": <OriginalIcon name="si-wrench" />,
    "/server/route": <OriginalIcon name="si-shuffle" />,
    "/plan": <OriginalIcon name="si-bag" />,
    "/order": <OriginalIcon name="si-list" />,
    "/coupon": <OriginalIcon name="si-present" />,
    "/giftcard": <OriginalIcon name="si-star" />,
    "/user": <OriginalIcon name="si-users" />,
    "/notice": <OriginalIcon name="si-speech" />,
    "/ticket": <OriginalIcon name="si-support" />,
    "/knowledge": <OriginalIcon name="si-bulb" />,
    "/subscribe-audit": <OriginalIcon name="si-list" />,
    "/leak-probe": <OriginalIcon name="si-magnifier" />,
    "/queue": <OriginalIcon name="si-bar-chart" />,
};
function usePath() {
    const [path, setPath] = useState(location.hash.slice(1) || "/dashboard");
    useEffect(() => {
        const update = () => setPath(location.hash.slice(1) || "/dashboard");
        window.addEventListener("hashchange", update);
        return () => window.removeEventListener("hashchange", update);
    }, []);
    return path;
}
function Login({ done }: { done: () => void }) {
    const [passkeyEnabled, setPasskeyEnabled] = useState(false);
    useEffect(() => {
        api("/api/v1/passport/passkey/config")
            .then((r) => setPasskeyEnabled(!!r.data?.enabled))
            .catch(() => {});
    }, []);
    const [error, setError] = useState(""),
        [busy, setBusy] = useState(false),
        [recoveryOpen, setRecoveryOpen] = useState(false);
    return (
        <div
            className="login-page"
            style={
                window.settings?.background_url
                    ? {
                          backgroundImage:
                              "url(" +
                              JSON.stringify(window.settings.background_url) +
                              ")",
                      }
                    : {}
            }
        >
            <div className="login-box">
                {window.settings?.logo ? (
                    <img
                        className="login-logo"
                        src={window.settings.logo}
                        alt={window.settings.title ?? "V2Board"}
                        style={{
                            display: "block",
                            maxWidth: "100%",
                            maxHeight: 80,
                            margin: "0 auto 12px",
                        }}
                    />
                ) : (
                    <h1>{window.settings?.title ?? "V2Board"}</h1>
                )}
                <p>管理员登录</p>
                {error && <Alert type="error" message={error} showIcon />}
                <Form
                    layout="vertical"
                    onFinish={async (values) => {
                        setBusy(true);
                        setError("");
                        try {
                            const res = await api(
                                "/api/v1/passport/auth/login",
                                values,
                                "POST",
                            );
                            if (!res.data?.is_admin)
                                throw new Error("此账号没有管理员权限");
                            session.set(res.data.auth_data);
                            location.hash = "/dashboard";
                            done();
                        } catch (e) {
                            setError((e as Error).message);
                        } finally {
                            setBusy(false);
                        }
                    }}
                >
                    <Form.Item
                        label="邮箱"
                        name="email"
                        rules={[
                            {
                                required: true,
                                type: "email",
                                message: "请输入正确的邮箱",
                            },
                        ]}
                    >
                        <Input autoComplete="username" />
                    </Form.Item>
                    <Form.Item
                        label="密码"
                        name="password"
                        rules={[{ required: true, message: "请输入密码" }]}
                    >
                        <Input.Password autoComplete="current-password" />
                    </Form.Item>
                    <Button
                        type="primary"
                        htmlType="submit"
                        loading={busy}
                        block
                    >
                        登录
                    </Button>
                    <Button
                        type="link"
                        block
                        onClick={() => setRecoveryOpen(true)}
                    >
                        忘记密码
                    </Button>
                    {passkeyEnabled && (
                        <Button
                            block
                            disabled={!passkeyAvailable()}
                            loading={busy}
                            onClick={async () => {
                                setBusy(true);
                                setError("");
                                try {
                                    const data = await passkeyLogin();
                                    if (!data?.is_admin || !data.auth_data)
                                        throw new Error("此账号没有管理员权限");
                                    session.set(data.auth_data);
                                    location.hash = "/dashboard";
                                    done();
                                } catch (e: any) {
                                    setError(
                                        e.name === "NotAllowedError"
                                            ? "Passkey 操作已取消或超时，请重试"
                                            : e.message,
                                    );
                                } finally {
                                    setBusy(false);
                                }
                            }}
                        >
                            使用 Passkey 登录
                            {!passkeyAvailable() ? "（需要 HTTPS）" : ""}
                        </Button>
                    )}
                </Form>
            </div>
            <Modal
                title="忘记密码"
                open={recoveryOpen}
                onCancel={() => setRecoveryOpen(false)}
                onOk={() => setRecoveryOpen(false)}
                okText="我知道了"
                cancelButtonProps={{ style: { display: "none" } }}
            >
                <p>请在站点目录下执行以下命令找回管理员密码：</p>
                <code>php artisan reset:password 管理员邮箱</code>
            </Modal>
        </div>
    );
}
class PageErrorBoundary extends React.Component<
    { children: React.ReactNode },
    { error: string }
> {
    state = { error: "" };
    static getDerivedStateFromError(error: Error) {
        return { error: error.message };
    }
    render() {
        return this.state.error ? (
            <Alert
                type="error"
                message="页面发生异常"
                description={this.state.error}
                action={
                    <Button onClick={() => location.reload()}>重新加载</Button>
                }
            />
        ) : (
            this.props.children
        );
    }
}
function Shell({
    appearance,
    changeAppearance,
}: {
    appearance: UiTheme;
    changeAppearance: (value: UiTheme | null) => void;
}) {
    const path = usePath();
    const { message } = AntApp.useApp();
    const [authenticated, setAuth] = useState(!!session.get()),
        [checking, setChecking] = useState(!!session.get()),
        [collapsed, setCollapsed] = useState(innerWidth < 900),
        [identity, setIdentity] = useState("管理员"),
        [themePanel, setThemePanel] = useState(false);
    useEffect(() => {
        let live = true;
        if (session.get())
            api("/api/v1/user/info")
                .then((r) => {
                    if (live) setIdentity(r.data?.email ?? "管理员");
                })
                .catch(() => {
                    if (live) setAuth(false);
                })
                .finally(() => {
                    if (live) setChecking(false);
                });
        const expired = () => {
            setAuth(false);
            setChecking(false);
            location.hash = "/login";
        };
        window.addEventListener("admin-session-expired", expired);
        return () => {
            live = false;
            window.removeEventListener("admin-session-expired", expired);
        };
    }, [authenticated]);
    const selected = path.startsWith("/ticket/")
        ? "/ticket"
        : path === "/"
          ? "/dashboard"
          : path;
    const name =
        sections
            .flatMap((s) => s.items)
            .find(([key]) => key === selected)?.[1] ?? "管理后台";
    let page: React.ReactNode;
    if (resourceSchemas[selected]) page = <Resources path={selected} />;
    else
        switch (selected) {
            case "/passkey":
                page = <Passkeys />;
                break;
            case "/dashboard":
                page = <Overview />;
                break;
            case "/user":
                page = <Users />;
                break;
            case "/server/manage":
                page = <Nodes />;
                break;
            case "/config/system":
                page = <Settings />;
                break;
            case "/config/payment":
                page = <Payments />;
                break;
            case "/config/theme":
                page = <Themes />;
                break;
            case "/order":
                page = <Orders />;
                break;
            case "/subscribe-audit":
                page = <SubscriptionAudit />;
                break;
            case "/leak-probe":
                page = <LeakProbe />;
                break;
            case "/ticket":
                page = (
                    <Tickets
                        id={
                            path.startsWith("/ticket/")
                                ? path.split("/")[2]
                                : undefined
                        }
                    />
                );
                break;
            case "/queue":
                page = <Queue />;
                break;
            default:
                page = (
                    <Page title="页面不存在">
                        <Button href="#/dashboard">返回仪表盘</Button>
                    </Page>
                );
        }
    if (checking)
        return (
            <div className="checking">
                <Spin size="large" />
            </div>
        );
    if (!authenticated || path === "/login")
        return (
            <Login
                done={() => {
                    setAuth(true);
                    setChecking(true);
                }}
            />
        );
    const menuItems = sections.flatMap<NonNullable<MenuProps["items"]>[number]>(
        (section) => {
            const children = section.items.map(([key, label]) => ({
                key,
                label,
                icon: icons[key],
            }));
            return section.label
                ? [
                      {
                          type: "group" as const,
                          label: collapsed ? undefined : section.label,
                          children,
                      },
                  ]
                : children;
        },
    );
    return (
        <Layout className="admin-layout">
            <Layout.Sider
                width={250}
                collapsedWidth={64}
                collapsed={collapsed}
                theme={appearance.sidebar === "dark" ? "dark" : "light"}
                className="admin-sidebar"
            >
                <a className="brand" href="#/dashboard">
                    {window.settings?.logo && (
                        <img src={window.settings.logo} alt="Logo" />
                    )}
                    {!collapsed && (
                        <span>{window.settings?.title ?? "V2Board"}</span>
                    )}
                </a>
                <Menu
                    className="sidebar-menu"
                    mode="inline"
                    theme={appearance.sidebar === "dark" ? "dark" : "light"}
                    selectedKeys={[selected]}
                    items={menuItems}
                    onClick={({ key }) => {
                        location.hash = key;
                        if (innerWidth < 700) setCollapsed(true);
                    }}
                />
                {!collapsed && (
                    <div className="sidebar-version">
                        {window.settings?.title ?? "V2Board"} v
                        {window.settings?.version ?? "1.7.5"}
                    </div>
                )}
            </Layout.Sider>
            <Layout className="admin-main">
                <Layout.Header
                    className={
                        "admin-header " +
                        (appearance.header === "light" ? "light" : "")
                    }
                >
                    <Space>
                        <Button
                            type="text"
                            className="header-button menu-toggle"
                            icon={<OriginalIcon name="fa-bars" />}
                            onClick={() => setCollapsed(!collapsed)}
                            aria-label="折叠菜单"
                        />
                        <span>{name}</span>
                    </Space>
                    <Space size={22}>
                        <Button
                            type="text"
                            className="header-button"
                            icon={<OriginalIcon name="fa-sun" />}
                            aria-label="界面设置"
                            onClick={() => setThemePanel(true)}
                        />
                        <Dropdown
                            menu={{
                                items: [
                                    { key: "fullscreen", label: "全屏" },
                                    { key: "passkey", label: "Passkey 管理" },
                                    { key: "logout", label: "退出登录" },
                                ],
                                onClick: async ({ key }) => {
                                    if (key === "passkey")
                                        location.hash = "/passkey";
                                    else if (key === "logout") {
                                        session.clear();
                                        setAuth(false);
                                        location.hash = "/login";
                                    } else
                                        try {
                                            if (document.fullscreenElement)
                                                await document.exitFullscreen();
                                            else
                                                await document.documentElement.requestFullscreen();
                                        } catch {
                                            message.error("无法切换全屏");
                                        }
                                },
                            }}
                        >
                            <a className="account-menu">
                                <OriginalIcon name="fa-user-circle" />
                                <span className="identity">{identity}</span>
                                <DownOutlined />
                            </a>
                        </Dropdown>
                    </Space>
                </Layout.Header>
                <Layout.Content className="admin-content">
                    <PageErrorBoundary key={path}>{page}</PageErrorBoundary>
                </Layout.Content>
            </Layout>
            <Drawer
                open={themePanel}
                title="界面设置"
                width={360}
                onClose={() => setThemePanel(false)}
            >
                <Form layout="vertical">
                    <Form.Item label="侧栏风格">
                        <Select
                            value={appearance.sidebar}
                            options={[
                                { value: "light", label: "亮色" },
                                { value: "dark", label: "深色" },
                            ]}
                            onChange={(sidebar) =>
                                changeAppearance({ ...appearance, sidebar })
                            }
                        />
                    </Form.Item>
                    <Form.Item label="顶栏风格">
                        <Select
                            value={appearance.header}
                            options={[
                                { value: "dark", label: "主题色" },
                                { value: "light", label: "亮色" },
                            ]}
                            onChange={(header) =>
                                changeAppearance({ ...appearance, header })
                            }
                        />
                    </Form.Item>
                    <Form.Item label="主题色">
                        <Select
                            value={appearance.color}
                            options={[
                                { value: "default", label: "默认蓝色" },
                                { value: "darkblue", label: "暗蓝色" },
                                { value: "black", label: "黑色" },
                                { value: "green", label: "绿色" },
                            ]}
                            onChange={(color) =>
                                changeAppearance({ ...appearance, color })
                            }
                        />
                    </Form.Item>
                    <Button onClick={() => changeAppearance(null)}>
                        跟随系统设置
                    </Button>
                </Form>
            </Drawer>
        </Layout>
    );
}
export default function Root() {
    const defaults: UiTheme = {
        sidebar: window.settings?.theme?.sidebar ?? "light",
        header: window.settings?.theme?.header ?? "dark",
        color: window.settings?.theme?.color ?? "default",
    };
    const storageKey = "v2board-admin-appearance";
    const [appearance, setAppearance] = useState<UiTheme>(() => {
        try {
            return (
                JSON.parse(localStorage.getItem(storageKey) ?? "null") ??
                defaults
            );
        } catch {
            return defaults;
        }
    });
    const color =
        (
            {
                green: "#2ea866",
                darkblue: "#244c83",
                black: "#333333",
                default: "#0665d0",
            } as Record<string, string>
        )[appearance.color] ?? "#0665d0";
    document.documentElement.style.setProperty("--brand", color);
    return (
        <ConfigProvider
            locale={zhCN}
            button={{ autoInsertSpace: false }}
            theme={{
                token: {
                    colorPrimary: color,
                    borderRadius: 4,
                    fontSize: 14,
                    colorText: "#495057",
                    colorBgLayout: "#f0f3f8",
                    fontFamily:
                        'Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
                },
                components: {
                    Table: {
                        headerBg: "#fafafa",
                        headerColor: "#333",
                        headerSplitColor: "transparent",
                        cellPaddingBlock: 16,
                    },
                    Layout: { headerHeight: 52 },
                    Menu: {
                        itemHeight: 38,
                        itemColor: "#555d65",
                        itemSelectedColor: "#000",
                        itemSelectedBg: "#e1edfc",
                    },
                },
            }}
        >
            <AntApp>
                <Shell
                    appearance={appearance}
                    changeAppearance={(value) => {
                        if (value) {
                            localStorage.setItem(
                                storageKey,
                                JSON.stringify(value),
                            );
                            setAppearance(value);
                        } else {
                            localStorage.removeItem(storageKey);
                            setAppearance(defaults);
                        }
                    }}
                />
            </AntApp>
        </ConfigProvider>
    );
}
