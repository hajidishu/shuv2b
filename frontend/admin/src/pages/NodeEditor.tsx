import { useEffect, useState } from "react";
import { Button, Drawer, Form, Input, Modal, Space } from "antd";
import { ApiError, api } from "../api";
import {
    displayValues,
    ErrorNotice,
    Fields,
    payloadValues,
} from "../components";
import { nodeFields } from "../schema/nodes";
import { nodeEditorFields, nodeSubmissionFields } from "../schema/node-editor";
import type { Row } from "../types";

export default function NodeEditor({
    node,
    references,
    save,
    close,
}: {
    node: Row;
    references: Row;
    save: (values: Row) => Promise<void>;
    close: () => void;
}) {
    const [form] = Form.useForm(),
        [busy, setBusy] = useState(false),
        [error, setError] = useState("");
    const [addingGroup, setAddingGroup] = useState(false),
        [groupName, setGroupName] = useState(""),
        [groupBusy, setGroupBusy] = useState(false),
        [groups, setGroups] = useState<Row[]>();
    const defaults = displayValues(nodeFields(node.type), node);
    if (!node.id) {
        defaults.port = node.port;
        defaults.server_port = node.server_port;
        if (node.type === "v2node") defaults.protocol = node.protocol;
        if (node.type === "shadowsocks")
            defaults.cipher = node.cipher ?? "chacha20-ietf-poly1305";
        if (node.type === "hysteria") defaults.version = node.version ?? 1;
        if (["v2node", "tuic"].includes(node.type))
            defaults.congestion_control = node.congestion_control ?? "cubic";
    }
    const values = Form.useWatch([], form) ?? defaults;
    const context = { ...defaults, ...values };
    const fields = nodeEditorFields(node.type, context);
    const groupField = fields.find((f) => f.key === "group_id");
    if (groupField)
        groupField.labelAction = {
            label: "添加权限组",
            onClick: () => setAddingGroup(true),
        };
    useEffect(() => form.setFieldsValue(defaults), []);
    const submit = async (v: Row) => {
        setBusy(true);
        setError("");
        try {
            const payload = payloadValues(nodeSubmissionFields(fields), v);
            // The current V2node API requires these neutral flags even for other protocols.
            // Keep them out of the editor and do not overwrite them on existing nodes.
            if (node.type === "v2node" && !node.id) {
                payload.disable_sni ??= 0;
                payload.zero_rtt_handshake ??= 0;
                payload.network ??= "tcp";
                payload.tls ??= 0;
            }
            await save(payload);
            close();
        } catch (e) {
            setError((e as Error).message);
            if (e instanceof ApiError)
                form.setFields(
                    Object.entries(e.fields).map(([name, errors]) => ({
                        name,
                        errors: errors as string[],
                    })),
                );
        } finally {
            setBusy(false);
        }
    };
    return (
        <Drawer
            open
            title={node.id ? "编辑节点" : "新建节点"}
            aria-label={node.id ? "编辑节点" : "新建节点"}
            width="80%"
            styles={{ wrapper: { maxWidth: 500 } }}
            className="node-editor"
            onClose={close}
            destroyOnHidden
            footer={
                <Space>
                    <Button onClick={close}>取消</Button>
                    <Button
                        type="primary"
                        aria-label="保存"
                        loading={busy}
                        onClick={() => form.submit()}
                    >
                        提交
                    </Button>
                </Space>
            }
        >
            <ErrorNotice error={error} />
            <Form
                form={form}
                requiredMark={false}
                layout="vertical"
                onFinish={submit}
                onValuesChange={(changed) => {
                    if (changed.protocol) {
                        if (
                            ["anytls", "hysteria2", "trojan", "tuic"].includes(
                                changed.protocol,
                            )
                        )
                            form.setFieldValue("tls", 1);
                        else if (changed.protocol === "shadowsocks")
                            form.setFieldValue("tls", 0);
                        if (
                            changed.protocol === "shadowsocks" ||
                            changed.protocol === "tuic" ||
                            changed.protocol === "hysteria2" ||
                            (changed.protocol === "trojan" &&
                                !["tcp", "ws", "grpc"].includes(
                                    form.getFieldValue("network"),
                                ))
                        )
                            form.setFieldValue("network", "tcp");
                    }
                    if (changed.version) form.setFieldValue("obfs", "");
                }}
            >
                <Fields
                    fields={fields}
                    references={{
                        ...references,
                        groups: groups ?? references.groups,
                    }}
                />
            </Form>
            {node.type === "v2node" && (
                <div className="node-install-command">
                    <label htmlFor="node-install-command">一键安装指令</label>
                    <Input.TextArea
                        id="node-install-command"
                        readOnly
                        value={node.install_command}
                        rows={4}
                    />
                </div>
            )}
            <Modal
                open={addingGroup}
                title="添加权限组"
                okText="添加"
                cancelText="取消"
                confirmLoading={groupBusy}
                onCancel={() => setAddingGroup(false)}
                onOk={async () => {
                    if (!groupName.trim()) return;
                    setGroupBusy(true);
                    try {
                        await api(
                            "/server/group/save",
                            { name: groupName.trim() },
                            "POST",
                        );
                        const list = (await api("/server/group/fetch")).data;
                        setGroups(list);
                        const created = [...list]
                            .reverse()
                            .find((g) => g.name === groupName.trim());
                        if (created)
                            form.setFieldValue("group_id", [
                                ...(form.getFieldValue("group_id") ?? []),
                                created.id,
                            ]);
                        setAddingGroup(false);
                        setGroupName("");
                    } catch (e) {
                        setError((e as Error).message);
                    } finally {
                        setGroupBusy(false);
                    }
                }}
            >
                <Input
                    aria-label="权限组名称"
                    placeholder="请输入组名"
                    value={groupName}
                    onChange={(e) => setGroupName(e.target.value)}
                />
            </Modal>
        </Drawer>
    );
}
