import {
    DownOutlined,
    FilterOutlined,
    PlusOutlined,
    QuestionCircleOutlined,
} from "@ant-design/icons";
import { useState } from "react";
import {
    Badge,
    Button,
    Checkbox,
    Collapse,
    Drawer,
    Dropdown,
    Modal,
    Space,
    Spin,
    Table,
    Tag,
    Tooltip,
} from "antd";
import { api } from "../api";
import { periods } from "../domain.mjs";
import {
    DataCard,
    Editor,
    ErrorNotice,
    Page,
    useAction,
    useReferences,
    useResource,
} from "../components";
import { orderFields } from "../schema/resources";
import { convertFilters, Filters } from "./Users";
import type { Filter, Row } from "../types";

const statuses = ["未支付", "已支付", "已取消", "已完成", "已折抵"];
const commissions = ["待确认", "发放中", "已发放", "无效"];
const orderTypes: Row = {
    1: "新购",
    2: "续费",
    3: "变更",
    4: "流量包",
    9: "充值",
};
const money = (value: any) => (Number(value ?? 0) / 100).toFixed(2);
const date = (value: any) =>
    value == null
        ? "-"
        : new Intl.DateTimeFormat("zh-CN", {
              year: "numeric",
              month: "2-digit",
              day: "2-digit",
              hour: "2-digit",
              minute: "2-digit",
              hour12: false,
          }).format(new Date(value * 1000));

export default function Orders() {
    const [filtersOpen, setFiltersOpen] = useState(false),
        [filters, setFilters] = useState<Filter[]>(() => {
            try {
                const v = sessionStorage.getItem("admin-order-initial-filters");
                sessionStorage.removeItem("admin-order-initial-filters");
                return v ? JSON.parse(v) : [];
            } catch {
                return [];
            }
        });
    const draftFilters = (items: Filter[]) =>
        items.map((f) => ({
            ...f,
            value:
                f.key === "commission_balance"
                    ? String(Number(f.value) / 100)
                    : f.value,
        }));
    const [draft, setDraft] = useState<Filter[]>(() => draftFilters(filters)),
        [current, setPage] = useState(1),
        [commission, setCommission] = useState(false),
        [assignEmail] = useState(() => {
            const email =
                sessionStorage.getItem("admin-order-assign-email") ?? "";
            sessionStorage.removeItem("admin-order-assign-email");
            return email;
        }),
        [assign, setAssign] = useState(() => !!assignEmail),
        [detail, setDetail] = useState<Row>();
    const refs = useReferences(),
        act = useAction();
    const resource = useResource("/order/fetch", {
        current,
        pageSize: 10,
        filter: filters,
        is_commission: commission ? 1 : 0,
    });
    const operate = (row: Row, kind: string) =>
        act.modal.confirm({
            title:
                kind === "paid"
                    ? "确认此订单已经收到款项？"
                    : "取消该待支付订单？",
            content: row.trade_no,
            onOk: () =>
                api("/order/" + kind, { trade_no: row.trade_no }, "POST").then(
                    resource.reload,
                ),
        });
    const markCommission = (row: Row, status: number) =>
        act
            .run(() =>
                api(
                    "/order/update",
                    { trade_no: row.trade_no, commission_status: status },
                    "POST",
                ).then(resource.reload),
            )
            .catch(() => {});
    const openDetail = async (row: Row) => {
        setDetail({ ...row, loading: true });
        try {
            const order = (await api("/order/detail", { id: row.id }, "POST"))
                .data;
            const info = async (id: any) => {
                if (!id) return {};
                try {
                    return (
                        (await api("/user/getUserInfoById", { id })).data ?? {}
                    );
                } catch {
                    return {};
                }
            };
            const [user, invite] = await Promise.all([
                info(order.user_id),
                info(order.invite_user_id),
            ]);
            setDetail({
                ...order,
                email: user.email ?? `用户 #${order.user_id}`,
                invite_email: invite.email,
            });
        } catch (e) {
            setDetail({ ...row, error: (e as Error).message });
        }
    };
    const pendingMenu = (row: Row) => (
        <Dropdown
            trigger={["click"]}
            menu={{
                items: [
                    { key: "paid", label: "已支付" },
                    { key: "cancel", label: "取消", danger: true },
                ],
                onClick: ({ key }) => operate(row, key),
            }}
        >
            <a className="order-mark-link">
                标记为 <DownOutlined />
            </a>
        </Dropdown>
    );
    return (
        <div className="orders-page">
            <Page title="订单管理">
                <DataCard
                    error={resource.error || refs.error}
                    loading={resource.loading}
                >
                    <div className="table-toolbar">
                        <Space size={10}>
                            <Button
                                icon={<FilterOutlined />}
                                aria-label="过滤器"
                                onClick={() => {
                                    setDraft(draftFilters(filters));
                                    setFiltersOpen(true);
                                }}
                            >
                                过滤器
                            </Button>
                            <Button
                                icon={<PlusOutlined />}
                                aria-label="分配订单"
                                onClick={() => setAssign(true)}
                            >
                                添加订单
                            </Button>
                        </Space>
                    </div>
                    <Table<Row>
                        className="orders-table"
                        rowKey="id"
                        dataSource={resource.data ?? []}
                        scroll={{ x: 1050 }}
                        pagination={{
                            current,
                            pageSize: 10,
                            total: resource.response.total,
                            onChange: setPage,
                            showSizeChanger: false,
                        }}
                        columns={[
                            {
                                title: "# 订单号",
                                dataIndex: "trade_no",
                                render: (value, row) => (
                                    <a
                                        title={value}
                                        href="#/order"
                                        onClick={(event) => {
                                            event.preventDefault();
                                            void openDetail(row);
                                        }}
                                    >
                                        {String(value).slice(0, 3)}...
                                        {String(value).slice(-3)}
                                    </a>
                                ),
                            },
                            {
                                title: "类型",
                                dataIndex: "type",
                                render: (value) => orderTypes[value] ?? "-",
                            },
                            { title: "订阅计划", dataIndex: "plan_name" },
                            {
                                title: "周期",
                                dataIndex: "period",
                                align: "center",
                                render: (value) => (
                                    <Tag className="order-period">
                                        {periods[value] ?? value}
                                    </Tag>
                                ),
                            },
                            {
                                title: "支付金额",
                                dataIndex: "total_amount",
                                align: "right",
                                render: money,
                            },
                            {
                                title: (
                                    <span>
                                        订单状态{" "}
                                        <Tooltip title="标记为[已支付]后将会由系统进行开通后并完成">
                                            <QuestionCircleOutlined />
                                        </Tooltip>
                                    </span>
                                ),
                                dataIndex: "status",
                                render: (value, row) => (
                                    <span className="order-status">
                                        <Badge
                                            status={
                                                (
                                                    [
                                                        "error",
                                                        "processing",
                                                        "default",
                                                        "success",
                                                        "default",
                                                    ] as const
                                                )[value] ?? "default"
                                            }
                                            text={statuses[value] ?? value}
                                        />
                                        {value === 0 && pendingMenu(row)}
                                    </span>
                                ),
                            },
                            {
                                title: "佣金金额",
                                dataIndex: "commission_balance",
                                align: "right",
                                render: (value, row) =>
                                    [0, 2].includes(row.status) ||
                                    !Number(value)
                                        ? "-"
                                        : money(value),
                            },
                            {
                                title: (
                                    <span>
                                        佣金状态{" "}
                                        <Tooltip title="标记为[有效]后将会由系统处理后发放到用户并完成">
                                            <QuestionCircleOutlined />
                                        </Tooltip>
                                    </span>
                                ),
                                dataIndex: "commission_status",
                                render: (value, row) => {
                                    if (
                                        [0, 2].includes(row.status) ||
                                        !Number(row.commission_balance)
                                    )
                                        return "-";
                                    const state = (
                                        <Badge
                                            status={
                                                (
                                                    [
                                                        "default",
                                                        "processing",
                                                        "success",
                                                        "error",
                                                    ] as const
                                                )[value] ?? "default"
                                            }
                                            text={commissions[value] ?? value}
                                        />
                                    );
                                    return (
                                        <span className="order-status">
                                            {state}
                                            {value !== 2 && (
                                                <Dropdown
                                                    trigger={["click"]}
                                                    menu={{
                                                        items: [
                                                            {
                                                                key: "0",
                                                                label: "待确认",
                                                                disabled:
                                                                    value === 0,
                                                            },
                                                            {
                                                                key: "1",
                                                                label: "有效",
                                                                disabled:
                                                                    value === 1,
                                                            },
                                                            {
                                                                key: "3",
                                                                label: "无效",
                                                                disabled:
                                                                    value === 3,
                                                            },
                                                        ],
                                                        onClick: ({ key }) =>
                                                            markCommission(
                                                                row,
                                                                Number(key),
                                                            ),
                                                    }}
                                                >
                                                    <a className="order-mark-link">
                                                        标记为 <DownOutlined />
                                                    </a>
                                                </Dropdown>
                                            )}
                                        </span>
                                    );
                                },
                            },
                            {
                                title: "创建时间",
                                dataIndex: "created_at",
                                align: "right",
                                render: date,
                            },
                        ]}
                    />
                </DataCard>
                <Drawer
                    open={filtersOpen}
                    title="过滤器"
                    width={360}
                    onClose={() => setFiltersOpen(false)}
                    className="order-filter-drawer"
                    footer={
                        <Space>
                            <Button
                                onClick={() => {
                                    setFilters([]);
                                    setDraft([]);
                                    setPage(1);
                                    setCommission(false);
                                    setFiltersOpen(false);
                                }}
                            >
                                重置
                            </Button>
                            <Button
                                type="primary"
                                onClick={() => {
                                    try {
                                        setFilters(convertFilters(draft));
                                        setPage(1);
                                        setFiltersOpen(false);
                                    } catch (e) {
                                        act.message.error((e as Error).message);
                                    }
                                }}
                            >
                                筛选订单
                            </Button>
                        </Space>
                    }
                >
                    <Filters
                        value={draft}
                        onChange={setDraft}
                        fieldLabels={{
                            status: "订单状态",
                            type: "订单类型",
                            commission_balance: "佣金金额",
                            callback_no: "回调单号",
                            user_id: "用户 ID",
                        }}
                        valueOptions={{
                            status: statuses.map((label, value) => ({
                                label,
                                value,
                            })),
                            commission_status: commissions.map(
                                (label, value) => ({ label, value }),
                            ),
                            type: Object.entries(orderTypes).map(
                                ([value, label]) => ({
                                    value: Number(value),
                                    label: String(label),
                                }),
                            ),
                        }}
                        keys={[
                            "email",
                            "trade_no",
                            "status",
                            "type",
                            "commission_status",
                            "user_id",
                            "invite_user_id",
                            "callback_no",
                            "commission_balance",
                        ]}
                    />
                    <Checkbox
                        checked={commission}
                        onChange={(e) => {
                            setCommission(e.target.checked);
                            setPage(1);
                        }}
                    >
                        仅看佣金订单
                    </Checkbox>
                </Drawer>
                {assign && (
                    <Editor
                        title="订单分配"
                        presentation="modal"
                        submitLabel="确定"
                        initial={{ email: assignEmail }}
                        fields={orderFields.map((f) => ({ ...f, span: 12 }))}
                        references={refs}
                        close={() => setAssign(false)}
                        save={async (values) => {
                            await api("/order/assign", values, "POST");
                            resource.reload();
                            act.message.success("已分配订单");
                        }}
                    />
                )}
                {detail && (
                    <Modal
                        open
                        title="订单信息"
                        width={520}
                        className="original-editor-modal order-detail-modal"
                        footer={null}
                        onCancel={() => setDetail(undefined)}
                    >
                        <ErrorNotice error={detail.error ?? ""} />
                        <Spin spinning={!!detail.loading}>
                            <div className="order-detail-fields">
                                {[
                                    ["邮箱", detail.email],
                                    ["订单号", detail.trade_no],
                                    [
                                        "订单周期",
                                        periods[detail.period] ?? detail.period,
                                    ],
                                    ["订单状态", statuses[detail.status]],
                                    [
                                        "订阅计划",
                                        refs.plans.find(
                                            (p: Row) => p.id === detail.plan_id,
                                        )?.name ?? "-",
                                    ],
                                    ["回调单号", detail.callback_no || "-"],
                                    ["支付金额", money(detail.total_amount)],
                                    ["余额支付", money(detail.balance_amount)],
                                    ["优惠金额", money(detail.discount_amount)],
                                    ["退回金额", money(detail.refund_amount)],
                                    ["折抵金额", money(detail.surplus_amount)],
                                    ["创建时间", date(detail.created_at)],
                                    ["更新时间", date(detail.updated_at)],
                                    ...(detail.invite_user_id &&
                                    detail.status === 3
                                        ? [
                                              [
                                                  "邀请人",
                                                  detail.invite_email ??
                                                      detail.invite_user_id,
                                              ],
                                              [
                                                  "佣金金额",
                                                  money(
                                                      detail.commission_balance,
                                                  ),
                                              ],
                                              [
                                                  "实际发放",
                                                  money(
                                                      detail.actual_commission_balance,
                                                  ),
                                              ],
                                              [
                                                  "佣金状态",
                                                  commissions[
                                                      detail.commission_status
                                                  ],
                                              ],
                                          ]
                                        : []),
                                ].map(([label, value]) => (
                                    <div key={String(label)}>
                                        <label>{label}</label>
                                        <span>
                                            {label === "邮箱" &&
                                            detail.email?.includes("@") ? (
                                                <a
                                                    href="#/user"
                                                    onClick={() =>
                                                        sessionStorage.setItem(
                                                            "admin-user-initial-filters",
                                                            JSON.stringify([
                                                                {
                                                                    key: "email",
                                                                    condition:
                                                                        "=",
                                                                    value: detail.email,
                                                                },
                                                            ]),
                                                        )
                                                    }
                                                >
                                                    {value}
                                                </a>
                                            ) : label === "邀请人" &&
                                              detail.invite_user_id ? (
                                                <a
                                                    href="#/user"
                                                    title="查看TA邀请的人"
                                                    onClick={() =>
                                                        sessionStorage.setItem(
                                                            "admin-user-initial-filters",
                                                            JSON.stringify([
                                                                {
                                                                    key: "invite_user_id",
                                                                    condition:
                                                                        "=",
                                                                    value: detail.invite_user_id,
                                                                },
                                                            ]),
                                                        )
                                                    }
                                                >
                                                    {value}
                                                </a>
                                            ) : (
                                                (value ?? "-")
                                            )}
                                        </span>
                                    </div>
                                ))}
                            </div>
                            {(detail.payment_adjustments?.length ?? 0) > 0 && (
                                <Collapse
                                    ghost
                                    items={[
                                        {
                                            key: "payment-adjustments",
                                            label: "补充收款已转入余额",
                                            children: (
                                                <Table<Row>
                                                    size="small"
                                                    pagination={false}
                                                    rowKey={(_, index) =>
                                                        String(index)
                                                    }
                                                    dataSource={
                                                        detail.payment_adjustments
                                                    }
                                                    columns={[
                                                        {
                                                            title: "金额",
                                                            dataIndex:
                                                                "wallet_amount",
                                                            render: money,
                                                        },
                                                        {
                                                            title: "原因",
                                                            dataIndex: "reason",
                                                            render: (value) =>
                                                                ({
                                                                    canceled_order:
                                                                        "取消后到账",
                                                                    additional_payment:
                                                                        "重复付款",
                                                                    order_changed:
                                                                        "订单已变更",
                                                                })[
                                                                    value as string
                                                                ] ?? value,
                                                        },
                                                        {
                                                            title: "时间",
                                                            dataIndex:
                                                                "created_at",
                                                            render: date,
                                                        },
                                                    ]}
                                                />
                                            ),
                                        },
                                    ]}
                                />
                            )}
                            {(detail.commission_log?.length ?? 0) +
                                (detail.surplus_orders?.length ?? 0) >
                                0 && (
                                <Collapse
                                    ghost
                                    items={[
                                        {
                                            key: "records",
                                            label: "佣金及折抵记录",
                                            children: (
                                                <>
                                                    {(
                                                        detail.commission_log ??
                                                        []
                                                    ).map((r: Row) => (
                                                        <p key={r.id}>
                                                            {r.trade_no}：
                                                            {money(
                                                                r.get_amount,
                                                            )}
                                                        </p>
                                                    ))}
                                                    {(
                                                        detail.surplus_orders ??
                                                        []
                                                    ).map((r: Row) => (
                                                        <p key={r.id}>
                                                            {r.trade_no}：
                                                            {money(
                                                                r.total_amount,
                                                            )}
                                                        </p>
                                                    ))}
                                                </>
                                            ),
                                        },
                                    ]}
                                />
                            )}
                        </Spin>
                    </Modal>
                )}
            </Page>
        </div>
    );
}
