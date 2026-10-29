import {
    DownOutlined,
    PlusOutlined,
    QuestionCircleOutlined,
    UserOutlined,
} from "@ant-design/icons";
import { useEffect, useState } from "react";
import {
    Alert,
    Button,
    Badge,
    Dropdown,
    Input,
    Select,
    Space,
    Switch,
    Table,
    Tag,
    Tooltip,
} from "antd";
import { api, copyText } from "../api";
import { batchPayload, nodeKey, nodeTypes } from "../domain.mjs";
import { commonNodeFields, protocolFields } from "../schema/nodes";
import {
    DataCard,
    Editor,
    Page,
    useAction,
    useReferences,
    useResource,
} from "../components";
import type { Row } from "../types";
import NodeEditor from "./NodeEditor";
import { nodeEditorFields, nodeSubmissionFields } from "../schema/node-editor";
const nodeTypeOrder: Array<keyof typeof nodeTypes> = [
    "v2node",
    "shadowsocks",
    "vmess",
    "trojan",
    "hysteria",
    "tuic",
    "vless",
    "anytls",
    "naive",
];
const nodeTypeColors: Record<string, string> = {
    shadowsocks: "#489851",
    vmess: "#cb3180",
    trojan: "#eab854",
    hysteria: "#1a1a1a",
    tuic: "#9400d3",
    vless: "#4080ff",
    anytls: "#ff8c00",
    naive: "#13a8a8",
    v2node: "#ff0000",
};
export default function Nodes() {
    const resource = useResource("/server/manage/getNodes");
    const refs = useReferences();
    const action = useAction();
    const [selected, setSelected] = useState<React.Key[]>([]);
    const [search, setSearch] = useState("");
    const [type, setType] = useState<string>();
    const [groupFilter, setGroupFilter] = useState<string[]>([]);
    const [editing, setEditing] = useState<Row>();
    const [batch, setBatch] = useState(false);
    const [patchKeys, setPatchKeys] = useState<string[]>(["rate"]);
    const [ordered, setOrdered] = useState<Row[]>([]);
    const [sorting, setSorting] = useState(false);
    const [dirty, setDirty] = useState(false);
    const [drag, setDrag] = useState<string>();
    const all: Row[] = Array.isArray(resource.data)
        ? resource.data
        : (Object.values(resource.data ?? {}).flat() as Row[]);
    useEffect(() => {
        setOrdered(all);
        setSelected([]);
        setDirty(false);
    }, [resource.data]);
    const filtered = ordered.filter(
        (n) =>
            (!type || n.type === type) &&
            (!groupFilter.length ||
                (n.group_id ?? []).some((id: any) =>
                    groupFilter.includes(String(id)),
                )) &&
            (!search ||
                [n.name, n.host, n.id, ...(n.tags ?? [])]
                    .join(" ")
                    .toLowerCase()
                    .includes(search.toLowerCase())),
    );
    const chosen = ordered.filter((n) => selected.includes(nodeKey(n)));
    const types = Array.from(new Set(chosen.map((n) => n.type)));
    const available = [
        ...commonNodeFields,
        ...(types.length === 1
            ? protocolFields[types[0]].filter(
                  (f) =>
                      types[0] !== "v2node" ||
                      chosen.every((n) =>
                          nodeSubmissionFields(
                              nodeEditorFields(n.type, n),
                          ).some((visible) => visible.key === f.key),
                      ),
              )
            : []),
    ];
    const batchFields = available
        .filter((f) => patchKeys.includes(f.key))
        .map((f) => ({
            ...f,
            required: !["parent_id", "route_id", "tags", "sort"].includes(
                f.key,
            ),
            default: undefined,
        }));
    const execute = async (kind: string, changes?: Row) => {
        await api(
            "/server/manage/batch",
            batchPayload(chosen, kind, changes),
            "POST",
        );
        action.message.success(`已处理 ${chosen.length} 个节点`);
        resource.reload();
    };
    const confirmBatch = (kind: string) =>
        action.modal.confirm({
            title: kind === "delete" ? "删除选中的节点？" : "复制选中的节点？",
            content: `本次操作仅影响选中的 ${chosen.length} 个节点。`,
            okButtonProps: { danger: kind === "delete" },
            onOk: () => execute(kind),
        });
    const save = async (values: Row) => {
        if (
            ["anytls", "v2node"].includes(editing!.type) &&
            values.padding_scheme != null
        )
            values.padding_scheme = JSON.stringify(values.padding_scheme);
        if (editing!.id)
            await api(
                "/server/manage/batch",
                batchPayload([editing!], "update", values),
                "POST",
            );
        else await api("/server/" + editing!.type + "/save", values, "POST");
        action.message.success("节点已保存");
        resource.reload();
    };
    const single = async (n: Row, kind: string) => {
        await api("/server/" + n.type + "/" + kind, { id: n.id }, "POST");
        resource.reload();
    };
    const move = (n: Row, offset: number) => {
        const list = [...ordered],
            i = list.findIndex((x) => nodeKey(x) === nodeKey(n)),
            j = i + offset;
        if (j < 0 || j >= list.length) return;
        [list[i], list[j]] = [list[j], list[i]];
        setOrdered(list);
        setDirty(true);
    };
    const saveSort = async () => {
        const payload: Row = Object.fromEntries(
            Object.keys(nodeTypes).map((t) => [t, {}]),
        );
        ordered.forEach((n, i) => (payload[n.type][n.id] = i + 1));
        await api("/server/manage/sort", payload, "POST");
        action.message.success("排序已保存");
        resource.reload();
    };
    const menus = (n: Row) => [
        { key: "edit", label: "编辑" },
        { key: "copy", label: "复制" },
        ...(n.type === "v2node" && n.install_command
            ? [{ key: "install", label: "安装命令" }]
            : []),
        { key: "delete", label: "删除", danger: true },
    ];
    const onMenu = (n: Row, key: string) => {
        if (key === "edit") setEditing(n);
        else if (key === "copy")
            action.run(() => single(n, "copy")).catch(() => {});
        else if (key === "install")
            action.modal.info({
                title: "V2node 安装命令",
                width: 750,
                content: (
                    <>
                        <Input.TextArea
                            value={n.install_command}
                            readOnly
                            autoSize={{ minRows: 4, maxRows: 10 }}
                        />
                        <Button
                            onClick={() =>
                                action
                                    .run(
                                        () => copyText(n.install_command),
                                        "安装命令已复制",
                                    )
                                    .catch(() => {})
                            }
                        >
                            复制安装命令
                        </Button>
                    </>
                ),
            });
        else
            action.modal.confirm({
                title: "删除节点 " + n.name + "？",
                okButtonProps: { danger: true },
                onOk: () => single(n, "drop"),
            });
    };
    return (
        <Page title="节点管理">
            <DataCard
                error={resource.error || refs.error}
                loading={resource.loading}
            >
                <div className="table-toolbar">
                    <Space size={8} wrap>
                        <Dropdown
                            menu={{
                                items: nodeTypeOrder.map((key) => ({
                                    key,
                                    label: (
                                        <Tag color={nodeTypeColors[key]}>
                                            {nodeTypes[key]}
                                        </Tag>
                                    ),
                                })),
                                onClick: ({ key }) => setEditing({ type: key }),
                            }}
                        >
                            <Button
                                icon={<PlusOutlined />}
                                aria-label="添加节点"
                                title="添加节点"
                            />
                        </Dropdown>
                        <Input
                            placeholder="输入任意关键字搜索"
                            allowClear
                            style={{ width: 200 }}
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                    </Space>
                    <Space>
                        <Button
                            type="primary"
                            onClick={() => {
                                if (!sorting) setSorting(true);
                                else
                                    action
                                        .run(saveSort)
                                        .then(() => setSorting(false))
                                        .catch(() => {});
                            }}
                        >
                            {sorting ? "保存排序" : "编辑排序"}
                        </Button>
                    </Space>
                </div>
                {selected.length > 0 && (
                    <div className="selection-toolbar">
                        <span>已选 {selected.length} 个节点</span>
                        <Button size="small" onClick={() => setBatch(true)}>
                            批量编辑
                        </Button>
                        <Button
                            size="small"
                            onClick={() =>
                                execute("update", { show: 1 }).catch((e) =>
                                    action.message.error(e.message),
                                )
                            }
                        >
                            批量显示
                        </Button>
                        <Button
                            size="small"
                            onClick={() =>
                                execute("update", { show: 0 }).catch((e) =>
                                    action.message.error(e.message),
                                )
                            }
                        >
                            批量隐藏
                        </Button>
                        <Button
                            size="small"
                            onClick={() => confirmBatch("copy")}
                        >
                            批量复制
                        </Button>
                        <Button
                            size="small"
                            danger
                            onClick={() => confirmBatch("delete")}
                        >
                            批量删除
                        </Button>
                        <Button size="small" onClick={() => setSelected([])}>
                            取消选择
                        </Button>
                    </div>
                )}
                <Table<Row>
                    onChange={(_, filters) => {
                        setType(filters.type?.[0] as string | undefined);
                        setGroupFilter((filters.group_id ?? []).map(String));
                    }}
                    rowKey={nodeKey}
                    dataSource={filtered}
                    scroll={{ x: 1300 }}
                    pagination={{ defaultPageSize: 20, showSizeChanger: true }}
                    rowSelection={{
                        selectedRowKeys: selected,
                        onChange: setSelected,
                        preserveSelectedRowKeys: true,
                    }}
                    onRow={(n) => ({
                        draggable: sorting,
                        onDragStart: () => setDrag(nodeKey(n)),
                        onDragOver: (e) => e.preventDefault(),
                        onDrop: () => {
                            if (!drag || drag === nodeKey(n)) return;
                            const list = [...ordered];
                            const from = list.findIndex(
                                    (x) => nodeKey(x) === drag,
                                ),
                                to = list.findIndex(
                                    (x) => nodeKey(x) === nodeKey(n),
                                );
                            list.splice(to, 0, list.splice(from, 1)[0]);
                            setOrdered(list);
                            setDirty(true);
                        },
                    })}
                    columns={[
                        {
                            title: "节点ID",
                            dataIndex: "id",
                            key: "type",
                            width: 150,
                            filters: nodeTypeOrder.map((value) => ({
                                value,
                                text: nodeTypes[value],
                            })),
                            filterMultiple: false,
                            onFilter: (value, node) =>
                                node.type === String(value).toLowerCase(),
                            render: (id, node) => (
                                <Tag
                                    color={
                                        nodeTypeColors[node.type] ?? "default"
                                    }
                                >
                                    {nodeTypes[node.type] ?? node.type}{" "}
                                    {node.parent_id
                                        ? `${id} => ${node.parent_id}`
                                        : id}
                                </Tag>
                            ),
                        },
                        {
                            title: "显隐",
                            dataIndex: "show",
                            width: 70,
                            render: (visible, node) => (
                                <Switch
                                    size="small"
                                    checked={!!visible}
                                    onChange={(next) =>
                                        action
                                            .run(() =>
                                                api(
                                                    "/server/" +
                                                        node.type +
                                                        "/update",
                                                    {
                                                        id: node.id,
                                                        show: next ? 1 : 0,
                                                    },
                                                    "POST",
                                                ).then(resource.reload),
                                            )
                                            .catch(() => {})
                                    }
                                />
                            ),
                        },
                        {
                            title: (
                                <Tooltip
                                    title={
                                        <>
                                            <Badge status="error" /> 未运行
                                            <br />
                                            <Badge status="warning" />{" "}
                                            无人使用或服务端上报异常
                                            <br />
                                            <Badge status="processing" />{" "}
                                            运行正常
                                        </>
                                    }
                                >
                                    节点 <QuestionCircleOutlined />
                                </Tooltip>
                            ),
                            dataIndex: "name",
                            render: (name, node) => {
                                const badge =
                                    node.available_status === 0
                                        ? "error"
                                        : node.available_status === 1
                                          ? "warning"
                                          : node.available_status === 2
                                            ? "processing"
                                            : "default";
                                return (
                                    <Dropdown
                                        trigger={["contextMenu"]}
                                        menu={{
                                            items: menus(node),
                                            onClick: ({ key }) =>
                                                onMenu(node, key),
                                        }}
                                    >
                                        <a onClick={() => setEditing(node)}>
                                            <Space size={6}>
                                                <Badge status={badge} />
                                                <Tooltip
                                                    title={
                                                        node.tags?.length
                                                            ? `标签：${node.tags.join("、")}`
                                                            : "点击编辑节点；右键打开操作菜单"
                                                    }
                                                >
                                                    <span>{name}</span>
                                                </Tooltip>
                                            </Space>
                                        </a>
                                    </Dropdown>
                                );
                            },
                        },
                        {
                            title: "地址",
                            dataIndex: "host",
                            render: (host, node) => {
                                const address = `${host ?? ""}:${node.port ?? ""}`;
                                return (
                                    <span
                                        role="button"
                                        tabIndex={0}
                                        style={{ cursor: "pointer" }}
                                        onClick={() =>
                                            action
                                                .run(
                                                    () => copyText(address),
                                                    "复制成功",
                                                )
                                                .catch(() => {})
                                        }
                                        onKeyDown={(event) => {
                                            if (
                                                event.key === "Enter" ||
                                                event.key === " "
                                            ) {
                                                event.preventDefault();
                                                action
                                                    .run(
                                                        () => copyText(address),
                                                        "复制成功",
                                                    )
                                                    .catch(() => {});
                                            }
                                        }}
                                    >
                                        {address}
                                    </span>
                                );
                            },
                        },
                        {
                            title: (
                                <Tooltip title="根据服务端上报频率而定">
                                    人数 <QuestionCircleOutlined />
                                </Tooltip>
                            ),
                            dataIndex: "online",
                            width: 130,
                            render: (value) => (
                                <>
                                    <UserOutlined /> {value ?? 0}
                                </>
                            ),
                        },
                        {
                            title: (
                                <Tooltip title="使用的流量将乘以倍率进行扣除">
                                    倍率 <QuestionCircleOutlined />
                                </Tooltip>
                            ),
                            dataIndex: "rate",
                            align: "center",
                            render: (value) => (
                                <Tag style={{ minWidth: 60 }}>{value} x</Tag>
                            ),
                        },
                        {
                            title: "权限组",
                            dataIndex: "group_id",
                            filters: refs.groups.map((group: Row) => ({
                                text: group.name,
                                value: group.id,
                            })),
                            filterMultiple: true,
                            render: (value) =>
                                (value ?? [])
                                    .map(
                                        (id: any) =>
                                            refs.groups.find(
                                                (g: Row) => g.id === id,
                                            )?.name ?? id,
                                    )
                                    .join(", "),
                        },
                        {
                            title: "操作",
                            fixed: "right",
                            width: sorting ? 190 : 90,
                            render: (_, node) => (
                                <Space size={8}>
                                    <Dropdown
                                        menu={{
                                            items: menus(node),
                                            onClick: ({ key }) =>
                                                onMenu(node, key),
                                        }}
                                    >
                                        <a className="row-menu-link">
                                            操作 <DownOutlined />
                                        </a>
                                    </Dropdown>
                                    {sorting && (
                                        <>
                                            <Button
                                                size="small"
                                                onClick={() => move(node, -1)}
                                            >
                                                ↑
                                            </Button>
                                            <Button
                                                size="small"
                                                onClick={() => move(node, 1)}
                                            >
                                                ↓
                                            </Button>
                                        </>
                                    )}
                                </Space>
                            ),
                        },
                    ]}
                />
            </DataCard>
            {editing && (
                <NodeEditor
                    key={nodeKey(editing)}
                    node={editing}
                    references={{
                        ...refs,
                        parents: ordered.filter(
                            (n) =>
                                n.type === editing.type && n.id !== editing.id,
                        ),
                    }}
                    save={save}
                    close={() => setEditing(undefined)}
                />
            )}
            {batch && (
                <Editor
                    key={"batch-" + patchKeys.join(",")}
                    title={`批量编辑 ${chosen.length} 个节点`}
                    fields={batchFields}
                    references={{
                        ...refs,
                        parents:
                            types.length === 1
                                ? ordered.filter(
                                      (n) =>
                                          n.type === types[0] &&
                                          !selected.includes(nodeKey(n)),
                                  )
                                : [],
                    }}
                    close={() => setBatch(false)}
                    save={(values) => execute("update", values)}
                >
                    <Alert
                        showIcon
                        type="info"
                        message="只修改下方勾选的字段，其他配置保留。清空可选字段会移除对应配置。"
                    />
                    <Select
                        aria-label="批量修改字段"
                        mode="multiple"
                        style={{ width: "100%", margin: "18px 0" }}
                        value={patchKeys}
                        options={available.map((f) => ({
                            label: f.label,
                            value: f.key,
                        }))}
                        onChange={setPatchKeys}
                    />
                </Editor>
            )}
        </Page>
    );
}
