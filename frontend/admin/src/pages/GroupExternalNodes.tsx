import { useState } from "react";
import { Button, Drawer, Form, Input, Space, Switch, Table, Tag } from "antd";
import { PlusOutlined } from "@ant-design/icons";
import { api, ApiError } from "../api";
import { ErrorNotice, Fields, payloadValues, useAction, useResource } from "../components";
import { SortButtons, useTableSort } from "../TableSort";
import type { Field, Row } from "../types";

const base = "/server/group/external";
const protocols = ["vless", "vmess", "trojan", "shadowsocks", "hysteria", "tuic", "anytls", "naive", "mieru"];
const option = (values: string[]) => values.map(value => ({ value, label: value === "hysteria" ? "Hysteria2" : value }));
const field = (key: string, label: string, extra: Partial<Field> = {}): Field => ({ key, label, kind: "text", span: 24, ...extra });
const secure = ["trojan", "hysteria", "tuic", "anytls", "naive"];
const errorMessage = (error: unknown) => error instanceof ApiError ? Object.values(error.fields).flat().join("；") || error.message : (error as Error).message;
const defaultConfig = (protocol: string) => ({ protocol, network: ["hysteria", "tuic"].includes(protocol) ? "udp" : "tcp", tls: secure.includes(protocol) ? 1 : 0 });

function fields(values: Row): Field[] {
    const protocol = values.protocol ?? "vless", tls = Number(values.tls ?? 0);
    const networks = ["vless", "vmess"].includes(protocol) ? ["tcp", "ws", "grpc", "http", "httpupgrade", "xhttp"] :
        protocol === "trojan" ? ["tcp", "ws", "grpc"] : ["naive", "mieru"].includes(protocol) ? ["tcp", "udp"] :
        ["hysteria", "tuic"].includes(protocol) ? ["udp"] : ["tcp"];
    return [
        field("name", "节点名称", { required: true }),
        field("group_id", "权限组", { kind: "select", multiple: true, reference: "groups", required: true }),
        field("show", "显示", { kind: "switch", default: 1 }),
        field("protocol", "节点协议", { kind: "select", options: option(protocols), required: true }),
        field("host", "服务器地址", { required: true, placeholder: "域名或 IP，不包含协议和端口" }),
        field("port", "连接端口", { kind: "number", required: true, min: 1 }),
        ...(["vless", "vmess", "tuic"].includes(protocol) ? [field("auth_uuid", "UUID", { required: true })] : []),
        ...(["naive", "mieru"].includes(protocol) ? [field("auth_username", "用户名", { required: true })] : []),
        ...(!["vless", "vmess"].includes(protocol) ? [field("auth_password", "连接密码", { kind: "password", required: true })] : []),
        field("network", "传输方式", { kind: "select", options: option(networks), required: true }),
        ...(["vless", "vmess"].includes(protocol) ? [field("tls", "安全类型", {
            kind: "select", options: [{ value: 0, label: "无" }, { value: 1, label: "TLS" }, ...(protocol === "vless" ? [{ value: 2, label: "Reality" }] : [])],
        })] : []),
        ...(tls ? [field("server_name", "SNI"), ...(protocol === "naive" ? [] : [field("allow_insecure", "允许不安全", { kind: "switch", default: 0 })]),
            ...(["vless", "vmess"].includes(protocol) ? [field("fingerprint", "TLS 指纹")] : []),
            ...(tls === 2 ? [field("public_key", "Reality 公钥", { required: true }), field("short_id", "Short ID")] : [])] : []),
        ...(protocol === "vless" ? [field("flow", "Flow", { kind: "select", options: [{ value: "", label: "无" }, { value: "xtls-rprx-vision", label: "xtls-rprx-vision" }] })] : []),
        ...(protocol === "shadowsocks" ? [field("cipher", "加密方式", { kind: "select", required: true,
            options: option(["aes-128-gcm", "aes-192-gcm", "aes-256-gcm", "chacha20-ietf-poly1305", "2022-blake3-aes-128-gcm", "2022-blake3-aes-256-gcm"]) }),
            field("obfs", "混淆", { kind: "select", options: [{ value: "", label: "无" }, { value: "http", label: "HTTP" }] })] : []),
        ...(protocol === "hysteria" ? [field("obfs", "混淆", { kind: "select", options: [{ value: "", label: "无" }, { value: "salamander", label: "salamander" }] }),
            ...(values.obfs ? [field("obfs_password", "混淆密码", { kind: "password", required: true })] : [])] : []),
        ...(protocol === "tuic" ? [field("congestion_control", "拥塞控制", { kind: "select", options: option(["bbr", "cubic", "new_reno"]), default: "bbr" }),
            field("udp_relay_mode", "UDP 中继模式", { kind: "select", options: option(["native", "quic"]), default: "native" }),
            field("disable_sni", "禁用 SNI", { kind: "switch", default: 0 }), field("zero_rtt_handshake", "0-RTT 握手", { kind: "switch", default: 0 })] : []),
        ...(["ws", "grpc", "http", "httpupgrade", "xhttp"].includes(values.network) || protocol === "mieru" || (protocol === "shadowsocks" && values.obfs) ?
            [field("network_settings", "传输配置", { kind: "json", nested:
                protocol === "mieru" ? [field("mtu", "MTU", { kind: "number" }), field("multiplexing", "多路复用"), field("handshake_mode", "握手模式"), field("traffic_pattern", "流量模式")] :
                values.network === "ws" ? [field("path", "路径"), { key: "headers", label: "请求头", kind: "json" }] :
                values.network === "grpc" ? [field("serviceName", "服务名称")] :
                [field("path", "路径"), field("host", "Host"), ...(values.network === "xhttp" ? [field("mode", "模式"), field("extra", "Extra", { kind: "json" })] : [])],
            })] : []),
    ];
}

function editable(record: Row): Row {
    const config = record.config ?? defaultConfig("vless");
    return { ...config, ...config.tls_settings, name: record.name, group_id: record.group_id, show: record.show ?? 1,
        auth_uuid: config.auth?.uuid, auth_password: config.auth?.password, auth_username: config.auth?.username,
        network_settings: JSON.stringify(config.network_settings ?? {}, null, 2) };
}

function ExternalEditor({ record, groups, close, saved }: { record: Row; groups: Row[]; close: () => void; saved: () => void }) {
    const [form] = Form.useForm(), values = Form.useWatch((data: Row) => data, { form, preserve: true }) ?? editable(record);
    const [uri, setUri] = useState(""), [error, setError] = useState(""), [busy, setBusy] = useState(false), [parsing, setParsing] = useState(false);
    const initial = editable(record);
    return <Drawer open title={record.id ? "编辑外部节点" : "添加外部节点"} width={540} className="external-node-editor" push={false}
        onClose={() => !busy && close()} maskClosable={!busy} destroyOnClose
        footer={<Space style={{ display: "flex", justifyContent: "flex-end" }}><Button aria-label="取消" disabled={busy} onClick={close}>取消</Button><Button aria-label="保存" type="primary" loading={busy} onClick={() => form.submit()}>保存</Button></Space>}>
        <ErrorNotice error={error} />
        <div style={{ marginBottom: 20 }}>
            <label htmlFor="external-node-uri">从节点链接导入</label>
            <Input.TextArea id="external-node-uri" value={uri} onChange={event => setUri(event.target.value)} rows={3} placeholder="vless://…、ss://… 等节点链接" />
            <Button loading={parsing} disabled={!uri.trim() || busy} style={{ marginTop: 8 }} onClick={async () => {
                setParsing(true); setError("");
                try {
                    const { data } = await api(base + "/parse", { uri }, "POST");
                    const current = form.getFieldsValue(true);
                    const parsed = editable({ ...data, group_id: current.group_id, show: current.show });
                    form.resetFields(); form.setFieldsValue(parsed);
            } catch (e) { setError(errorMessage(e)); } finally { setParsing(false); }
            }}>解析链接</Button>
        </div>
        <Form form={form} layout="vertical" requiredMark={false} disabled={busy || parsing} initialValues={initial}
            onValuesChange={changed => {
                if (changed.protocol) form.setFieldsValue({ ...defaultConfig(changed.protocol), network_settings: "{}", obfs: undefined, flow: undefined });
            }}
            onFinish={async () => {
                setBusy(true); setError("");
                try {
                    const data = payloadValues(fields(values), form.getFieldsValue(true));
                    const { name, group_id, show, auth_uuid, auth_password, auth_username, server_name, allow_insecure, fingerprint, public_key, short_id, ...config } = data;
                    await api(base + "/save", { ...(record.id ? { id: record.id } : {}), name, group_id, show,
                        config: { ...config, auth: { uuid: auth_uuid, password: auth_password, username: auth_username },
                            tls_settings: { server_name, allow_insecure, fingerprint, public_key, short_id } } }, "POST");
                    saved(); close();
                } catch (e) { setError(errorMessage(e)); } finally { setBusy(false); }
            }}><Fields fields={fields(values)} references={{ groups }} /></Form>
    </Drawer>;
}

export default function GroupExternalNodes({ group, groups, close, changed }: { group: Row; groups: Row[]; close: () => void; changed: () => void }) {
    const resource = useResource(base + "/fetch", { group_id: group.id }), action = useAction();
    const [editing, setEditing] = useState<Row>();
    const sorter = useTableSort(resource.data, async rows => { await api(base + "/sort", { group_id: group.id, ids: rows.map(row => row.id) }, "POST"); resource.reload(); });
    const reload = () => { resource.reload(); changed(); };
    return <Drawer open title={`${group.name} · 外部节点`} width={1100} onClose={close} className="group-external-nodes" destroyOnClose>
        <ErrorNotice error={resource.error} />
        <Space style={{ marginBottom: 16 }} wrap>
            <Button aria-label="添加外部节点" icon={<PlusOutlined />} disabled={sorter.editing} onClick={() => setEditing({ group_id: [Number(group.id)], show: 1 })}>添加外部节点</Button>
            <SortButtons sorter={sorter} disabled={!sorter.rows.length} />
        </Space>
        <p className="text-muted">外部节点不参与流量统计。移除节点或用户到期后，已下载的连接凭据仍需在外部节点上撤销。</p>
        <p className="text-muted">当前导出格式不支持的协议、传输或特殊字符会被省略；通用链接订阅保留完整连接信息。</p>
        <Table rowKey="id" dataSource={sorter.rows} loading={resource.loading} onRow={sorter.onRow} scroll={{ x: 850 }} pagination={{ defaultPageSize: 20, showSizeChanger: true }} columns={[
            ...(sorter.editing ? [sorter.column] : []),
            { title: "显示", width: 65, render: (_, row) => <Switch size="small" checked={!!row.show} disabled={sorter.editing || action.busy} onChange={show => action.run(async () => { await api(base + "/update", { id: row.id, show: show ? 1 : 0 }, "POST"); reload(); }).catch(() => {})} /> },
            { title: "名称", dataIndex: "name" },
            { title: "协议", width: 100, render: (_, row) => row.config.protocol === "hysteria" ? "Hysteria2" : row.config.protocol },
            { title: "地址", render: (_, row) => `${row.config.host}:${row.config.port}` },
            { title: "权限组", render: (_, row) => row.group_id.map((id: number) => <Tag key={id}>{groups.find(g => Number(g.id) === Number(id))?.name ?? `未找到的权限组（ID：${id}）`}</Tag>) },
            { title: "操作", width: 110, fixed: "right", render: (_, row) => <Space className="inline-row-actions" size={6}>
                <a aria-disabled={sorter.editing} onClick={() => !sorter.editing && setEditing(row)}>编辑</a><span aria-hidden="true">|</span>
                <a aria-disabled={sorter.editing} onClick={() => !sorter.editing && action.modal.confirm({ title: `删除 ${row.name}？`, content: "删除后，所有关联权限组的新订阅将不再包含此节点。", okButtonProps: { danger: true }, onOk: async () => { await api(base + "/drop", { id: row.id }, "POST"); reload(); } })}>删除</a>
            </Space> },
        ]} />
        {editing && <ExternalEditor key={editing.id ?? "new"} record={editing} groups={groups} close={() => setEditing(undefined)} saved={() => { action.message.success("保存成功"); reload(); }} />}
    </Drawer>;
}
