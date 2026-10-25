import { useEffect, useState } from "react";
import {
    Alert,
    App,
    Button,
    Card,
    Form,
    Input,
    Modal,
    Space,
    Table,
    Typography,
} from "antd";
import { api, session } from "../api";
import { passkeyAvailable, passkeyGrant, passkeyRegister } from "../passkey";

export default function Passkeys() {
    const { message } = App.useApp();
    const [keys, setKeys] = useState<any[]>([]),
        [enabled, setEnabled] = useState(false),
        [busy, setBusy] = useState(false);
    const [operation, setOperation] = useState<any>(null),
        [form] = Form.useForm();
    const load = async () => {
        const config = (await api("/api/v1/passport/passkey/config")).data;
        setEnabled(!!config?.enabled);
        if (config?.enabled)
            setKeys((await api("/api/v1/user/passkey/fetch")).data.credentials);
    };
    useEffect(() => {
        load().catch((e) => message.error(e.message));
    }, []);
    const open = (action: string, row?: any) => {
        form.resetFields();
        form.setFieldsValue({ name: row?.name });
        setOperation({ action, row });
    };
    const submit = async (usePassword: boolean) => {
        try {
            const values = await form.validateFields(
                usePassword
                    ? undefined
                    : operation.action === "admin_revoke"
                      ? ["email"]
                      : ["name"],
            );
            setBusy(true);
            const { action, row } = operation;
            let target = row?.id;
            if (action === "admin_revoke") {
                const users = await api("/user/fetch", {
                    filter: [
                        { key: "email", condition: "=", value: values.email },
                    ],
                    page_size: 2,
                });
                const user = users.data?.find(
                    (u: any) => u.email === values.email,
                );
                if (!user) throw new Error("用户不存在");
                target = user.id;
            }
            const reauth_token = await passkeyGrant(
                action,
                target,
                usePassword ? values.password : undefined,
            );
            let result: any;
            if (action === "register")
                result = await passkeyRegister(values.name, reauth_token);
            else
                result = await api(
                    action === "admin_revoke"
                        ? "/passkey/revoke"
                        : "/api/v1/user/passkey/" + action,
                    {
                        action,
                        target_id: target,
                        name: values.name,
                        reauth_token,
                    },
                    "POST",
                );
            setOperation(null);
            form.resetFields();
            if (result.data?.sign_out) {
                session.clear();
                window.dispatchEvent(new Event("admin-session-expired"));
                return;
            }
            message.success("操作成功");
            await load();
        } catch (e: any) {
            if (!e.errorFields)
                message.error(e.message || "操作已取消，请重试");
        } finally {
            setBusy(false);
            form.setFieldValue("password", "");
        }
    };
    return (
        <Card title="Passkey 管理">
            {!enabled ? (
                <Alert
                    type="info"
                    message="请先在系统配置 → 安全中配置并启用 Passkey"
                />
            ) : (
                <>
                    {!passkeyAvailable() && (
                        <Alert
                            type="warning"
                            message="管理 Passkey 需要通过 HTTPS 访问，并使用支持 WebAuthn 的浏览器"
                        />
                    )}
                    <Space style={{ marginBottom: 16 }}>
                        <Button
                            disabled={!passkeyAvailable() || keys.length >= 10}
                            onClick={() => open("register")}
                        >
                            添加 Passkey
                        </Button>
                        <Button
                            disabled={!passkeyAvailable()}
                            onClick={() => open("admin_revoke")}
                        >
                            撤销用户 Passkey
                        </Button>
                    </Space>
                    <Table
                        rowKey="id"
                        dataSource={keys}
                        pagination={false}
                        columns={[
                            { title: "名称", dataIndex: "name" },
                            {
                                title: "创建时间",
                                dataIndex: "created_at",
                                render: (v: number) =>
                                    new Date(v * 1000).toLocaleString(),
                            },
                            {
                                title: "最近使用",
                                dataIndex: "last_used_at",
                                render: (v: number) =>
                                    v
                                        ? new Date(v * 1000).toLocaleString()
                                        : "尚未使用",
                            },
                            {
                                title: "操作",
                                render: (_, row) => (
                                    <Space>
                                        <Button
                                            type="link"
                                            disabled={!passkeyAvailable()}
                                            onClick={() => open("rename", row)}
                                        >
                                            重命名
                                        </Button>
                                        <Button
                                            type="link"
                                            danger
                                            disabled={!passkeyAvailable()}
                                            onClick={() => open("delete", row)}
                                        >
                                            删除
                                        </Button>
                                    </Space>
                                ),
                            },
                        ]}
                    />
                    <Typography.Paragraph type="secondary">
                        最多绑定 10 个
                        Passkey。添加、重命名和删除均需要重新验证身份。删除密钥会撤销使用该密钥登录的会话。
                    </Typography.Paragraph>
                </>
            )}
            <Modal
                title="验证身份并继续"
                open={!!operation}
                closable={!busy}
                maskClosable={!busy}
                onCancel={() => {
                    setOperation(null);
                    form.resetFields();
                }}
                footer={
                    <Space>
                        <Button
                            disabled={busy}
                            onClick={() => {
                                setOperation(null);
                                form.resetFields();
                            }}
                        >
                            取消
                        </Button>
                        {keys.length > 0 && (
                            <Button
                                loading={busy}
                                onClick={() => submit(false)}
                            >
                                使用 Passkey 验证
                            </Button>
                        )}
                        <Button
                            type="primary"
                            loading={busy}
                            onClick={() => submit(true)}
                        >
                            使用密码验证
                        </Button>
                    </Space>
                }
            >
                <Form layout="vertical" form={form}>
                    {["register", "rename"].includes(operation?.action) && (
                        <Form.Item
                            label="名称"
                            name="name"
                            rules={[{ required: true, max: 80 }]}
                        >
                            <Input maxLength={80} />
                        </Form.Item>
                    )}
                    {operation?.action === "admin_revoke" && (
                        <>
                            <Alert
                                message="撤销该用户全部 Passkey 并注销全部登录会话，密码与订阅不变。"
                                type="warning"
                            />
                            <Form.Item
                                label="用户邮箱"
                                name="email"
                                rules={[{ required: true, type: "email" }]}
                            >
                                <Input />
                            </Form.Item>
                        </>
                    )}
                    {operation?.action === "delete" && (
                        <Alert
                            type="warning"
                            message={
                                "确认删除 " +
                                operation.row.name +
                                "？当前会话使用此密钥登录时也会退出。"
                            }
                        />
                    )}
                    <Form.Item
                        label="当前账号密码"
                        name="password"
                        rules={[{ required: true }]}
                    >
                        <Input.Password autoComplete="current-password" />
                    </Form.Item>
                </Form>
            </Modal>
        </Card>
    );
}
