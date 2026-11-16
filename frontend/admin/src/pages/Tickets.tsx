import { Radio } from "antd";
import { useEffect, useRef, useState } from "react";
import type { MouseEvent } from "react";
import { Divider, Input, Table } from "antd";
import { api } from "../api";
import {
    DataCard,
    formatDate,
    Page,
    useAction,
    useResource,
} from "../components";
import type { Row } from "../types";
import TicketChat from "./TicketChat";
export default function Tickets({ id }: { id?: string }) {
    return id ? <TicketChat key={id} id={id} /> : <TicketList />;
}

function TicketList() {
    const [status, setStatus] = useState<number>(0);
    const [reply, setReply] = useState<number[]>([]);
    const [email, setEmail] = useState("");
    const [current, setPage] = useState(1);
    const act = useAction();
    const resource = useResource("/ticket/fetch", {
        current,
        pageSize: 20,
        status,
        reply_status: reply.length ? reply : undefined,
        email: email || undefined,
    });
    const ticket = resource.data;
    const reload = useRef(resource.reload);
    reload.current = resource.reload;
    useEffect(() => {
        const refresh = () => reload.current();
        window.addEventListener("focus", refresh);
        return () => window.removeEventListener("focus", refresh);
    }, []);
    const openChat = (
        event: MouseEvent<HTMLAnchorElement>,
        ticketId: number,
    ) => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey)
            return;
        if (
            /mobile|ipad/i.test(navigator.userAgent) ||
            (/Macintosh/i.test(navigator.userAgent) &&
                navigator.maxTouchPoints > 1)
        )
            return;
        event.preventDefault();
        const url = new URL(location.href);
        url.hash = "/ticket/" + ticketId;
        const popup = window.open(
            url.href,
            "_blank",
            "popup=yes,height=600,width=800,top=0,left=0,toolbar=no,menubar=no,scrollbars=no,resizable=yes,location=no,status=no",
        );
        if (popup) {
            popup.opener = null;
            popup.focus();
        } else location.hash = "/ticket/" + ticketId;
    };
    const close = (ticketId: number) =>
        act.modal.confirm({
            title: "关闭此工单？",
            onOk: () =>
                api("/ticket/close", { id: ticketId }, "POST").then(
                    resource.reload,
                ),
        });
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
                                <a
                                    href={"#/ticket/" + r.id}
                                    onClick={(event) => openChat(event, r.id)}
                                >
                                    {v}
                                </a>
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
                                    <a
                                        href={"#/ticket/" + r.id}
                                        onClick={(event) =>
                                            openChat(event, r.id)
                                        }
                                    >
                                        查看
                                    </a>
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
