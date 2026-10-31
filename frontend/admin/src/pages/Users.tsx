import { copyText } from "../api";
import {
    FilterOutlined,
    SelectOutlined,
    UserAddOutlined,
    DownOutlined,
    FileExcelOutlined,
    MailOutlined,
    StopOutlined,
    DeleteOutlined,
    EditOutlined,
    PlusOutlined,
    CopyOutlined,
    ReloadOutlined,
    AccountBookOutlined,
    UsergroupAddOutlined,
    SolutionOutlined,
} from "@ant-design/icons";
import { Dropdown, Drawer, Tooltip } from "antd";
import { useRef, useState } from "react";
import dayjs from "dayjs";
import { Badge, Button, Input, Space, Table, Tag, Tabs } from "antd";
import type { Filter, Row } from "../types";
import { api, downloadApi } from "../api";
import {
    DataCard,
    formatDate,
    formatMoney,
    KeyValues,
    Page,
    useAction,
    useReferences,
    useResource,
} from "../components";
import UserEditor from "./UserEditor";
import UserGenerateModal from "./UserGenerateModal";
import UserMailModal from "./UserMailModal";
import { Filters, convertFilters } from "../UserFilters";
export { Filters, convertFilters } from "../UserFilters";
import { labels } from "../schema/resources";
function initialUserFilters(): Filter[] {
    try {
        const value = sessionStorage.getItem("admin-user-initial-filters");
        sessionStorage.removeItem("admin-user-initial-filters");
        const filters = value ? (JSON.parse(value) as Filter[]) : [];
        return filters;
    } catch {
        return [];
    }
}
export default function Users() {
    const [filtersOpen, setFiltersOpen] = useState(false);
    const [initialFilterState] = useState(() => {
        const draft = initialUserFilters();
        return { draft };
    });
    const [draft, setDraft] = useState<Filter[]>(initialFilterState.draft);
    const [filters, setFilters] = useState<Filter[]>(initialFilterState.draft);
    const [current, setCurrent] = useState(1);
    const [pageSize, setSize] = useState(20);
    const [sort, setSort] = useState({ sort: "created_at", sort_type: "DESC" });
    const [selected, setSelected] = useState<React.Key[]>([]);
    const [editing, setEditing] = useState<Row>();
    const [generate, setGenerate] = useState(false);
    const [mail, setMail] = useState(false);
    const [detail, setDetail] = useState<Row>();
    const [detailTab, setDetailTab] = useState("info");
    const contextUser = useRef<Row>();
    const refs = useReferences();
    const act = useAction();
    const resource = useResource("/user/fetch", {
        current,
        pageSize,
        ...sort,
        filter: convertFilters(filters),
    });
    const scope = {
        filter: convertFilters(filters),
        ...sort,
        ...(selected.length ? { ids: selected } : {}),
    };
    const apply = () => {
        try {
            convertFilters(draft);
            setFilters(draft);
            setCurrent(1);
            setSelected([]);
            setFiltersOpen(false);
        } catch (e) {
            act.message.error((e as Error).message);
        }
    };
    const open = async (row: Row) => {
        const res = await api("/user/getUserInfoById", { id: row.id });
        setEditing({
            ...res.data,
            invite_user_email: res.data.invite_user?.email,
        });
    };
    const save = async (data: Row) => {
        await api("/user/update", { ...data, id: editing!.id }, "POST");
        act.message.success("用户已保存");
        resource.reload();
    };
    const banOrDelete = (kind: string) =>
        act.modal.confirm({
            title: kind === "ban" ? "封禁用户？" : "删除用户及其订单和工单？",
            content: `影响 ${selected.length || resource.response.total || 0} 个${selected.length ? "选中的" : "符合当前筛选的"}用户。`,
            okButtonProps: { danger: true },
            onOk: async () => {
                await api("/user/" + kind, scope, "POST");
                setSelected([]);
                resource.reload();
            },
        });
    const reset = (row: Row) =>
        act.modal.confirm({
            title: "重置 " + row.email + " 的订阅令牌？",
            content: "重置后原订阅地址和连接凭据失效。",
            onOk: async () => {
                await api("/user/resetSecret", { id: row.id }, "POST");
                resource.reload();
            },
        });
    const rowAction = (row: Row, key: string) => {
        if (key === "edit") act.run(() => open(row), "").catch(() => {});
        else if (key === "reset") reset(row);
        else if (key === "delete")
            act.modal.confirm({
                title: "删除用户 " + row.email + "？",
                okButtonProps: { danger: true },
                onOk: async () => {
                    await api("/user/delUser", { id: row.id }, "POST");
                    resource.reload();
                },
            });
        else if (key === "assign") {
            sessionStorage.setItem("admin-order-assign-email", row.email);
            location.hash = "/order";
        } else if (key === "copy") {
            act.run(async () => {
                let subscribeUrl = row.subscribe_url;
                if (typeof subscribeUrl !== "string" || !subscribeUrl.trim()) {
                    const user = (
                        await api("/user/fetch", {
                            current: 1,
                            pageSize: 10,
                            filter: [
                                {
                                    key: "id",
                                    condition: "=",
                                    value: row.id,
                                },
                            ],
                        })
                    ).data?.[0];
                    subscribeUrl = user?.subscribe_url;
                }
                if (typeof subscribeUrl !== "string" || !subscribeUrl.trim())
                    throw new Error("无法获取用户订阅地址");
                await copyText(subscribeUrl);
            }, "订阅地址已复制").catch(() => {});
        } else if (key === "orders") {
            sessionStorage.setItem(
                "admin-order-initial-filters",
                JSON.stringify([
                    { key: "user_id", condition: "=", value: row.id },
                ]),
            );
            location.hash = "/order";
        } else if (key === "invites") {
            const next: Filter[] = [
                { key: "invite_user_id", condition: "=", value: row.id },
            ];
            setDraft(next);
            setFilters(next);
            setCurrent(1);
            setSelected([]);
            setFiltersOpen(true);
        } else if (key === "traffic") {
            setDetail(row);
            setDetailTab("traffic");
        }
    };
    const rowMenuItems = [
        { key: "edit", label: "编辑", icon: <EditOutlined /> },
        { key: "assign", label: "分配订单", icon: <PlusOutlined /> },
        { key: "copy", label: "复制订阅URL", icon: <CopyOutlined /> },
        {
            key: "reset",
            label: "重置UUID及订阅URL",
            icon: <ReloadOutlined />,
            danger: true,
        },
        { key: "orders", label: "TA的订单", icon: <AccountBookOutlined /> },
        {
            key: "invites",
            label: "TA的邀请",
            icon: <UsergroupAddOutlined />,
        },
        {
            key: "traffic",
            label: "TA的流量记录",
            icon: <SolutionOutlined />,
        },
        {
            key: "delete",
            label: "删除用户",
            icon: <DeleteOutlined />,
            danger: true,
        },
    ];
    const rowMenu = (row?: Row) => ({
        items: rowMenuItems,
        onClick: ({ key }: { key: string }) => {
            const target = row ?? contextUser.current;
            if (target) rowAction(target, key);
        },
    });
    const detailOrders = useResource(
        "/order/fetch",
        {
            filter: [{ key: "user_id", condition: "=", value: detail?.id }],
            pageSize: 100,
        },
        !!detail && detailTab === "orders",
    );
    const traffic = useResource(
        "/stat/getStatUser",
        { user_id: detail?.id, pageSize: 100 },
        !!detail && detailTab === "traffic",
    );
    return (
        <Page title="用户管理">
            <DataCard
                error={resource.error || refs.error}
                loading={resource.loading}
            >
                <div className="table-toolbar">
                    <Space size={10}>
                        <Button.Group>
                            <Button
                                aria-label="过滤器"
                                icon={<FilterOutlined />}
                                onClick={() => {
                                    setDraft(filters);
                                    setFiltersOpen(true);
                                }}
                            >
                                过滤器
                                {filters.length
                                    ? " (" + filters.length + ")"
                                    : ""}
                            </Button>
                            <Dropdown
                                menu={{
                                    items: [
                                        {
                                            key: "export",
                                            label: "导出CSV",
                                            icon: <FileExcelOutlined />,
                                        },
                                        {
                                            key: "mail",
                                            label: "发送邮件",
                                            icon: <MailOutlined />,
                                        },
                                        {
                                            key: "ban",
                                            label: "批量封禁",
                                            icon: <StopOutlined />,
                                            danger: true,
                                            disabled:
                                                !selected.length &&
                                                !filters.length,
                                        },
                                        {
                                            key: "delete",
                                            label: "批量删除",
                                            icon: <DeleteOutlined />,
                                            danger: true,
                                            disabled:
                                                !selected.length &&
                                                !filters.length,
                                        },
                                    ],
                                    onClick: ({ key }) => {
                                        if (key === "export")
                                            act.run(
                                                () =>
                                                    downloadApi(
                                                        "/user/dumpCSV",
                                                        scope,
                                                        "users.csv",
                                                    ),
                                                "已导出",
                                            ).catch(() => {});
                                        else if (key === "mail") setMail(true);
                                        else
                                            banOrDelete(
                                                key === "ban"
                                                    ? "ban"
                                                    : "allDel",
                                            );
                                    },
                                }}
                            >
                                <Button
                                    aria-label="操作"
                                    icon={<SelectOutlined />}
                                >
                                    操作
                                </Button>
                            </Dropdown>
                        </Button.Group>
                        <Button
                            icon={<UserAddOutlined />}
                            aria-label="添加用户"
                            title="添加用户"
                            onClick={() => setGenerate(true)}
                        />
                    </Space>
                </div>
                <Drawer
                    open={filtersOpen}
                    title="过滤器"
                    width={360}
                    className="user-filter-drawer"
                    onClose={() => setFiltersOpen(false)}
                    footer={
                        <Space
                            style={{
                                display: "flex",
                                justifyContent: "flex-end",
                            }}
                        >
                            <Button
                                aria-label="重置筛选"
                                disabled={!draft.length}
                                onClick={() => {
                                    setDraft([]);
                                    setFilters([]);
                                    setCurrent(1);
                                    setSelected([]);
                                    setFiltersOpen(false);
                                }}
                            >
                                重置
                            </Button>
                            <Button onClick={() => setFiltersOpen(false)}>
                                取消
                            </Button>
                            <Button
                                type="primary"
                                aria-label="筛选用户"
                                onClick={apply}
                            >
                                检索
                            </Button>
                        </Space>
                    }
                >
                    <Filters
                        value={draft}
                        onChange={setDraft}
                        references={refs.plans}
                    />
                </Drawer>
                {!!selected.length && (
                    <div className="selection-toolbar">
                        <span>已选 {selected.length} 个用户</span>
                        <Button
                            size="small"
                            onClick={() =>
                                act
                                    .run(
                                        () =>
                                            downloadApi(
                                                "/user/dumpCSV",
                                                scope,
                                                "users.csv",
                                            ),
                                        "已导出",
                                    )
                                    .catch(() => {})
                            }
                        >
                            导出选中用户
                        </Button>
                        <Button size="small" onClick={() => setMail(true)}>
                            发送邮件
                        </Button>
                        <Button
                            size="small"
                            danger
                            onClick={() => banOrDelete("ban")}
                        >
                            封禁选中用户
                        </Button>
                    </div>
                )}
                <Dropdown trigger={["contextMenu"]} menu={rowMenu()}>
                    <div
                        className="user-table-context-menu"
                        onContextMenu={(event) => {
                            if (
                                !(event.target instanceof HTMLElement) ||
                                !event.target.closest("tr[data-row-key]")
                            ) {
                                event.preventDefault();
                                event.stopPropagation();
                            }
                        }}
                    >
                        <Table<Row>
                            rowKey="id"
                            dataSource={resource.data ?? []}
                            scroll={{ x: 1700 }}
                            rowSelection={{
                                selectedRowKeys: selected,
                                onChange: setSelected,
                            }}
                            pagination={{
                                current,
                                pageSize,
                                total: resource.response.total,
                                onChange: (p, s) => {
                                    setCurrent(p);
                                    setSize(s);
                                    setSelected([]);
                                },
                                showSizeChanger: true,
                            }}
                            onChange={(_, __, sorting) => {
                                const s = Array.isArray(sorting)
                                    ? sorting[0]
                                    : sorting;
                                if (s.field && s.order)
                                    setSort({
                                        sort: String(s.columnKey ?? s.field),
                                        sort_type:
                                            s.order === "ascend"
                                                ? "ASC"
                                                : "DESC",
                                    });
                            }}
                            onRow={(row) => ({
                                onContextMenu: (event) => {
                                    if (
                                        event.target instanceof HTMLElement &&
                                        event.target.closest(
                                            ".ant-checkbox-wrapper",
                                        )
                                    ) {
                                        event.preventDefault();
                                        event.stopPropagation();
                                        return;
                                    }
                                    contextUser.current = row;
                                },
                            })}
                            columns={[
                                {
                                    title: "ID",
                                    dataIndex: "id",
                                    sorter: true,
                                    width: 80,
                                },
                                {
                                    title: "邮箱",
                                    dataIndex: "email",
                                    render: (v, r) => {
                                        const lastOnline =
                                            Number(r.t) > 0
                                                ? Number(r.t)
                                                : null;
                                        const online =
                                            lastOnline != null &&
                                            Date.now() / 1000 -
                                                Number(lastOnline) <=
                                                600;
                                        return (
                                            <span
                                                title={
                                                    lastOnline == null
                                                        ? "从未在线"
                                                        : "最后在线" +
                                                          dayjs(
                                                              lastOnline * 1000,
                                                          ).format(
                                                              "YYYY-MM-DD HH:mm:ss",
                                                          )
                                                }
                                            >
                                                <Badge
                                                    status={
                                                        online
                                                            ? "success"
                                                            : "default"
                                                    }
                                                />
                                                <a
                                                    onClick={() => {
                                                        setDetail(r);
                                                        setDetailTab("info");
                                                    }}
                                                >
                                                    {v}
                                                </a>
                                            </span>
                                        );
                                    },
                                },
                                {
                                    title: "状态",
                                    dataIndex: "banned",
                                    key: "banned",
                                    sorter: true,
                                    render: (v) => (
                                        <Tag color={v ? "red" : "green"}>
                                            {v ? "封禁" : "正常"}
                                        </Tag>
                                    ),
                                },
                                {
                                    title: "订阅",
                                    dataIndex: "plan_name",
                                    key: "plan_id",
                                    sorter: true,
                                    render: (v) => v || "-",
                                },
                                {
                                    title: "权限组",
                                    dataIndex: "group_id",
                                    key: "group_id",
                                    sorter: true,
                                    render: (v) =>
                                        refs.groups.find(
                                            (g: Row) =>
                                                Number(g.id) === Number(v),
                                        )?.name ?? "-",
                                },
                                {
                                    title: "已用(G)",
                                    dataIndex: "total_used",
                                    sorter: true,
                                    render: (v, r) => (
                                        <Tag
                                            color={
                                                Number(v) >
                                                Number(r.transfer_enable)
                                                    ? "red"
                                                    : "green"
                                            }
                                        >
                                            {(
                                                Number(v ?? 0) / 1073741824
                                            ).toFixed(2)}
                                        </Tag>
                                    ),
                                },
                                {
                                    title: "流量(G)",
                                    dataIndex: "transfer_enable",
                                    sorter: true,
                                    render: (v) =>
                                        v == null
                                            ? "-"
                                            : (Number(v) / 1073741824).toFixed(
                                                  2,
                                              ),
                                },
                                {
                                    title: "设备数",
                                    dataIndex: "device_limit",
                                    sorter: (a, b) =>
                                        Number(a.alive_ip ?? 0) -
                                        Number(b.alive_ip ?? 0),
                                    render: (_, r) => (
                                        <span title={r.ips || undefined}>
                                            {Number(r.alive_ip ?? 0)} /{" "}
                                            {r.device_limit == null
                                                ? "∞"
                                                : r.device_limit}
                                        </span>
                                    ),
                                },
                                {
                                    title: "到期时间",
                                    dataIndex: "expired_at",
                                    width: 180,
                                    sorter: true,
                                    render: (v) => (
                                        <Tag
                                            color={
                                                v != null &&
                                                Number(v) < Date.now() / 1000
                                                    ? "red"
                                                    : "green"
                                            }
                                        >
                                            {v === null
                                                ? "长期有效"
                                                : v === undefined
                                                  ? "-"
                                                  : dayjs(
                                                        Number(v) * 1000,
                                                    ).format(
                                                        "YYYY/MM/DD HH:mm",
                                                    )}
                                        </Tag>
                                    ),
                                },
                                {
                                    title: (
                                        <Tooltip title="累计拉取次数，包含失败请求；清理或重置审计明细不会减少此次数。">
                                            <span>拉取订阅次数</span>
                                        </Tooltip>
                                    ),
                                    dataIndex: "subscribe_count",
                                    width: 140,
                                    sorter: true,
                                    render: (v) => Number(v ?? 0),
                                },
                                {
                                    title: "余额",
                                    dataIndex: "balance",
                                    sorter: true,
                                    render: formatMoney,
                                },
                                {
                                    title: "佣金",
                                    dataIndex: "commission_balance",
                                    sorter: true,
                                    render: formatMoney,
                                },
                                {
                                    title: "加入时间",
                                    dataIndex: "created_at",
                                    sorter: true,
                                    render: (v) =>
                                        v == null
                                            ? "-"
                                            : dayjs(Number(v) * 1000).format(
                                                  "YYYY/MM/DD HH:mm",
                                              ),
                                },
                                {
                                    title: "操作",
                                    fixed: "right",
                                    width: 90,
                                    render: (_, r) => (
                                        <Dropdown
                                            trigger={["click"]}
                                            menu={rowMenu(r)}
                                        >
                                            <a className="row-menu-link">
                                                操作 <DownOutlined />
                                            </a>
                                        </Dropdown>
                                    ),
                                },
                            ]}
                        />
                    </div>
                </Dropdown>
            </DataCard>
            {editing && (
                <UserEditor
                    key={editing.id}
                    user={editing}
                    plans={refs.plans}
                    save={save}
                    close={() => setEditing(undefined)}
                />
            )}
            {generate && (
                <UserGenerateModal
                    plans={refs.plans}
                    close={() => setGenerate(false)}
                    onCreated={resource.reload}
                />
            )}
            {mail && (
                <UserMailModal
                    scope={scope}
                    recipientLabel={
                        selected.length
                            ? "选中用户"
                            : filters.length
                              ? "过滤用户"
                              : "全部用户"
                    }
                    recipientCount={
                        selected.length || resource.response.total || 0
                    }
                    close={() => setMail(false)}
                    onSent={() => act.message.success("邮件已加入队列")}
                />
            )}
            {detail && (
                <Drawer
                    open
                    title={detail.email}
                    width={850}
                    onClose={() => setDetail(undefined)}
                >
                    <Tabs
                        activeKey={detailTab}
                        onChange={setDetailTab}
                        items={[
                            {
                                key: "info",
                                label: "用户详情",
                                children: (
                                    <>
                                        <KeyValues
                                            data={Object.fromEntries(
                                                Object.entries(detail)
                                                    .filter(
                                                        ([key]) =>
                                                            ![
                                                                "password",
                                                                "password_salt",
                                                                "password_algo",
                                                            ].includes(key),
                                                    )
                                                    .map(([k, v]) => [
                                                        labels[k] ?? k,
                                                        v,
                                                    ]),
                                            )}
                                        />
                                        <Input.TextArea
                                            readOnly
                                            value={detail.subscribe_url}
                                        />
                                        <Button
                                            onClick={() => {
                                                copyText(detail.subscribe_url)
                                                    .then(() =>
                                                        act.message.success(
                                                            "订阅地址已复制",
                                                        ),
                                                    )
                                                    .catch(() =>
                                                        act.message.error(
                                                            "复制失败",
                                                        ),
                                                    );
                                            }}
                                        >
                                            复制订阅地址
                                        </Button>
                                    </>
                                ),
                            },
                            {
                                key: "orders",
                                label: "订单记录",
                                children: (
                                    <DataCard
                                        loading={detailOrders.loading}
                                        error={detailOrders.error}
                                    >
                                        <Table
                                            rowKey="id"
                                            dataSource={detailOrders.data ?? []}
                                            columns={[
                                                "trade_no",
                                                "plan_name",
                                                "period",
                                                "total_amount",
                                                "status",
                                            ].map((k) => ({
                                                title: labels[k],
                                                dataIndex: k,
                                                render: (v) =>
                                                    k === "total_amount"
                                                        ? formatMoney(v)
                                                        : String(v ?? "—"),
                                            }))}
                                        />
                                    </DataCard>
                                ),
                            },
                            {
                                key: "traffic",
                                label: "流量记录",
                                children: (
                                    <DataCard
                                        loading={traffic.loading}
                                        error={traffic.error}
                                    >
                                        <Table
                                            rowKey="id"
                                            dataSource={traffic.data ?? []}
                                            columns={[
                                                "record_at",
                                                "u",
                                                "d",
                                                "server_rate",
                                            ].map((k) => ({
                                                title:
                                                    k === "record_at"
                                                        ? "日期"
                                                        : k,
                                                dataIndex: k,
                                                render: (v) =>
                                                    k === "record_at"
                                                        ? formatDate(v)
                                                        : String(v ?? "—"),
                                            }))}
                                        />
                                    </DataCard>
                                ),
                            },
                        ]}
                    />
                </Drawer>
            )}
        </Page>
    );
}
