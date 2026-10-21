import { Radio } from "antd";
import { useState } from "react";
import { Button, Divider, Input, Space, Table, Tag } from "antd";
import { api } from "../api";
import {
    DataCard,
    formatDate,
    Page,
    useAction,
    useResource,
} from "../components";
import type { Row } from "../types";
export default function Tickets({ id }: { id?: string }) {
    const [status, setStatus] = useState<number>(0);
    const [reply, setReply] = useState<number[]>([]);
    const [email, setEmail] = useState("");
    const [current, setPage] = useState(1);
    const [message, setMessage] = useState("");
    const act = useAction();
    const resource = useResource(
        "/ticket/fetch",
        id
            ? { id }
            : {
                  current,
                  pageSize: 20,
                  status,
                  reply_status: reply.length ? reply : undefined,
                  email: email || undefined,
              },
    );
    const ticket = resource.data;
    const close = (ticketId: number) =>
        act.modal.confirm({
            title: "关闭此工单？",
            onOk: () =>
                api("/ticket/close", { id: ticketId }, "POST").then(
                    resource.reload,
                ),
        });
    if (id)
        return (
            <Page title={ticket?.subject ?? "工单详情"}>
                <DataCard error={resource.error} loading={resource.loading}>
                    <div className="table-toolbar">
                        <Space>
                            <Button
                                onClick={() => {
                                    sessionStorage.setItem(
                                        "admin-user-initial-filters",
                                        JSON.stringify([
                                            {
                                                key: "id",
                                                condition: "=",
                                                value: ticket?.user_id,
                                            },
                                        ]),
                                    );
                                    location.hash = "/user";
                                }}
                            >
                                用户管理
                            </Button>
                            <Button href="#/ticket">返回工单列表</Button>
                            <Button
                                disabled={ticket?.status === 1}
                                onClick={() => close(Number(id))}
                            >
                                关闭工单
                            </Button>
                        </Space>
                        <Space></Space>
                    </div>
                    <p>
                        用户 ID：{ticket?.user_id}{" "}
                        <Tag>{ticket?.status ? "已关闭" : "处理中"}</Tag>
                    </p>
                    <div className="ticket-thread">
                        {(ticket?.message ?? []).map((m: Row) => (
                            <div
                                className={
                                    m.is_me
                                        ? "ticket-message staff"
                                        : "ticket-message"
                                }
                                key={m.id}
                            >
                                <strong>{m.is_me ? "管理员" : "用户"}</strong>
                                <small>{formatDate(m.created_at)}</small>
                                <p>{m.message}</p>
                            </div>
                        ))}
                    </div>
                    <Input.TextArea
                        aria-label="工单回复"
                        placeholder="输入内容回复工单..."
                        value={message}
                        onChange={(e) => setMessage(e.target.value)}
                        autoSize={{ minRows: 4, maxRows: 12 }}
                    />
                    <Button
                        className="filter-actions"
                        type="primary"
                        disabled={!message.trim() || ticket?.status === 1}
                        loading={act.busy}
                        onClick={() =>
                            act
                                .run(async () => {
                                    await api(
                                        "/ticket/reply",
                                        { id: Number(id), message },
                                        "POST",
                                    );
                                    setMessage("");
                                    resource.reload();
                                }, "回复成功")
                                .catch(() => {})
                        }
                    >
                        回复工单
                    </Button>
                </DataCard>
            </Page>
        );
    return (
        <Page title="工单管理">
            <DataCard error={resource.error} loading={resource.loading}>
                <div className="table-toolbar">
                    <Radio.Group
                        value={status ?? 0}
                        onChange={(e) => {
                            setStatus(e.target.value);
                            setPage(1);
                        }}
                    >
                        <Radio.Button value={0}>已开启</Radio.Button>
                        <Radio.Button value={1}>已关闭</Radio.Button>
                    </Radio.Group>
                    <Input
                        aria-label="工单邮箱搜索"
                        placeholder="输入邮箱搜索"
                        style={{ width: 183 }}
                        value={email}
                        allowClear
                        onChange={(e) => {
                            setEmail(e.target.value);
                            setPage(1);
                        }}
                    />
                </div>{" "}
                <Table<Row>
                    rowKey="id"
                    onChange={(_, filters) => {
                        setReply((filters.reply_status ?? []).map(Number));
                        setPage(1);
                    }}
                    dataSource={ticket ?? []}
                    pagination={{
                        current,
                        pageSize: 20,
                        total: resource.response.total,
                        onChange: setPage,
                    }}
                    columns={[
                        { title: "ID", dataIndex: "id" },
                        {
                            title: "主题",
                            dataIndex: "subject",
                            render: (v, r) => (
                                <a href={"#/ticket/" + r.id}>{v}</a>
                            ),
                        },
                        {
                            title: "等级",
                            dataIndex: "level",
                            render: (v) => ["低", "中", "高"][v] ?? v,
                        },
                        {
                            title: "回复状态",
                            dataIndex: "reply_status",
                            filters: [
                                { text: "待回复", value: 0 },
                                { text: "已回复", value: 1 },
                            ],
                            filteredValue: reply,
                            render: (v) => (v ? "已回复" : "待回复"),
                        },
                        {
                            title: "创建时间",
                            dataIndex: "created_at",
                            render: formatDate,
                        },
                        {
                            title: "更新时间",
                            dataIndex: "updated_at",
                            render: formatDate,
                        },
                        {
                            title: "操作",
                            width: 120,
                            align: "right",
                            render: (_, r) => (
                                <span className="inline-row-actions">
                                    <a href={"#/ticket/" + r.id}>查看</a>
                                    <Divider type="vertical" />
                                    <a
                                        aria-disabled={r.status === 1}
                                        onClick={() =>
                                            r.status !== 1 && close(r.id)
                                        }
                                    >
                                        关闭
                                    </a>
                                </span>
                            ),
                        },
                    ]}
                />
            </DataCard>
        </Page>
    );
}
