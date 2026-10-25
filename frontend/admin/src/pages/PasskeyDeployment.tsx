import { useState } from "react";
import {
    Alert,
    App,
    Button,
    Form,
    Input,
    Modal,
    Space,
    Typography,
} from "antd";
import { api, copyText, download } from "../api";

export default function PasskeyDeployment({
    beforeLoad,
}: {
    beforeLoad: () => Promise<void>;
}) {
    const { message } = App.useApp();
    const [busy, setBusy] = useState(false);
    const [info, setInfo] = useState<any>(null);
    const load = async () => {
        if (busy) return;
        setBusy(true);
        try {
            await beforeLoad();
            setInfo((await api("/config/passkeyDeployment")).data);
        } catch (error: any) {
            message.error(error.message);
        } finally {
            setBusy(false);
        }
    };
    const json = JSON.stringify(info?.document ?? { origins: [] }, null, 2);
    return (
        <div className="settings-fields">
            <Form.Item
                label={
                    <div className="settings-label">
                        <strong>ROR 部署</strong>
                        <span>
                            所有域名共用固定 RP ID，声明随已保存配置自动生成。
                        </span>
                    </div>
                }
            >
                <Button loading={busy} onClick={load}>
                    部署信息与声明文件
                </Button>
            </Form.Item>
            <Modal
                title="Passkey 多域名部署"
                open={!!info}
                onCancel={() => setInfo(null)}
                footer={<Button onClick={() => setInfo(null)}>关闭</Button>}
                width={640}
            >
                <Space direction="vertical" size={16} style={{ width: "100%" }}>
                    <Alert
                        type={info?.enabled ? "info" : "warning"}
                        showIcon
                        message={
                            info?.enabled
                                ? "以下内容取自后台已保存配置"
                                : "Passkey 尚未启用或配置未完成"
                        }
                    />
                    <div>
                        <strong>固定 RP ID</strong>
                        <Input readOnly value={info?.rp_id ?? ""} />
                    </div>
                    <div>
                        <strong>公开声明地址</strong>
                        <Input
                            readOnly
                            value={
                                info?.well_known_url ?? "请先配置正式 RP 域名"
                            }
                        />
                    </div>
                    <Typography.Paragraph>
                        RP 域名指向本后端时，声明由路由自动提供，无需手动创建文件。
                        独立静态前端可将请求转发到后端，或在前端网站根目录创建{" "}
                        <Typography.Text code>.well-known</Typography.Text>
                        目录，再把下载的{" "}
                        <Typography.Text code>webauthn</Typography.Text>
                        文件放入其中。webauthn 是没有扩展名的文件，不是目录。
                        最终访问地址为{" "}
                        <Typography.Text code>
                            /.well-known/webauthn
                        </Typography.Text>
                        ，返回类型为 application/json。
                    </Typography.Paragraph>
                    <Input.TextArea
                        readOnly
                        value={json}
                        autoSize={{ minRows: 4, maxRows: 12 }}
                    />
                    <Space wrap>
                        <Button
                            disabled={!info?.configured}
                            onClick={() =>
                                copyText(json)
                                    .then(() => message.success("声明已复制"))
                                    .catch((error) =>
                                        message.error(error.message),
                                    )
                            }
                        >
                            复制声明
                        </Button>
                        <Button
                            disabled={!info?.configured}
                            onClick={() =>
                                download(
                                    json + "\n",
                                    "webauthn",
                                    // Chromium otherwise appends .json to this
                                    // extensionless standard filename. The live
                                    // well-known endpoint remains application/json.
                                    "application/octet-stream",
                                )
                            }
                        >
                            下载声明文件
                        </Button>
                    </Space>
                    <Typography.Text type="secondary">
                        RP 域名须保持 HTTPS 443 可达；关联来源也须加入加密 API
                        中间件的 CORS
                        白名单。独立上传的声明在域名列表改变后需要重新上传。按最多
                        5 种不同注册域标签规划，并非 5 个网址。
                    </Typography.Text>
                </Space>
            </Modal>
        </div>
    );
}
