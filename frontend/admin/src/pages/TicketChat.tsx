import { useEffect, useRef, useState } from "react";
import {
    Alert,
    Button,
    Divider,
    Input,
    Modal,
    Spin,
    Table,
    Tag,
    Tooltip,
} from "antd";
import { SolutionOutlined, UserOutlined } from "@ant-design/icons";
import dayjs from "dayjs";
import { api } from "../api";
import { DataCard, useAction, useResource } from "../components";
import type { Row } from "../types";
import UserEditor from "./UserEditor";

function trafficSize(value: number) {
    const bytes = Number(value) || 0;
    const unit =
        bytes > 0
            ? Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), 4)
            : 0;
    return (
        (bytes / 1024 ** unit).toFixed(unit ? 2 : 0) +
        " " +
        ["B", "KB", "MB", "GB", "TB"][unit]
    );
}

function TrafficRecords({
    userId,
    close,
}: {
    userId: number;
    close: () => void;
}) {
    const [current, setCurrent] = useState(1);
    const [pageSize, setPageSize] = useState(10);
    const resource = useResource("/stat/getStatUser", {
        user_id: userId,
        current,
        pageSize,
    });
    return (
        <Modal
            open
            title="流量记录"
            width="calc(100% - 20px)"
            style={{ maxWidth: 1000, top: 20 }}
            className="ticket-traffic-modal"
            footer={null}
            onCancel={close}
        >
            <DataCard error={resource.error} loading={resource.loading}>
                <Table<Row>
                    rowKey="id"
                    dataSource={resource.data ?? []}
                    size="small"
                    scroll={{ x: 450 }}
                    pagination={{
                        current,
                        pageSize,
                        total: resource.response.total,
                        showSizeChanger: true,
                        onChange: (page, size) => {
                            setCurrent(size === pageSize ? page : 1);
                            setPageSize(size);
                        },
                    }}
                    columns={[
                        {
                            title: "日期",
                            dataIndex: "record_at",
                            render: (value) =>
                                dayjs(Number(value) * 1000).format(
                                    "YYYY-MM-DD",
                                ),
                        },
                        {
                            title: "上行",
                            dataIndex: "u",
                            align: "right",
                            render: trafficSize,
                        },
                        {
                            title: "下行",
                            dataIndex: "d",
                            align: "right",
                            render: trafficSize,
                        },
                        {
                            title: "倍率",
                            dataIndex: "server_rate",
                            align: "right",
                        },
                    ]}
                />
            </DataCard>
        </Modal>
    );
}

export default function TicketChat({ id }: { id: string }) {
    const resource = useResource("/ticket/fetch", { id });
    const ticket: Row | undefined = resource.data?.id
        ? resource.data
        : undefined;
    const [message, setMessage] = useState("");
    const [editor, setEditor] = useState<{
        user: Row;
        plans: Row[];
        groups: Row[];
    }>();
    const [userLoading, setUserLoading] = useState(false);
    const [trafficOpen, setTrafficOpen] = useState(false);
    const messages = useRef<HTMLDivElement>(null);
    const autoScroll = useRef(true);
    const reload = useRef(resource.reload);
    reload.current = resource.reload;
    const act = useAction();
    const closed = Number(ticket?.status) === 1;
    const lastMessage = ticket?.message?.at(-1);

    useEffect(() => {
        const timer = window.setInterval(() => {
            if (!document.hidden) reload.current();
        }, 5000);
        const refresh = () => {
            if (!document.hidden) reload.current();
        };
        document.addEventListener("visibilitychange", refresh);
        return () => {
            window.clearInterval(timer);
            document.removeEventListener("visibilitychange", refresh);
        };
    }, []);

    useEffect(() => {
        if (autoScroll.current && messages.current)
            messages.current.scrollTop = messages.current.scrollHeight;
    }, [ticket?.message?.length, lastMessage?.id, lastMessage?.message]);

    useEffect(() => {
        const previous = document.title;
        document.title = `${ticket?.subject ?? "工单"} #${id}`;
        return () => {
            document.title = previous;
        };
    }, [id, ticket?.subject]);

    const send = () => {
        if (!ticket || closed || act.busy || !message.trim()) return;
        const submitted = message;
        act.run(async () => {
            await api(
                "/ticket/reply",
                { id: Number(id), message: submitted },
                "POST",
            );
            setMessage((current) => (current === submitted ? "" : current));
            autoScroll.current = true;
            reload.current();
        }, "回复成功").catch(() => {});
    };

    const openUser = async () => {
        if (!ticket) return;
        setUserLoading(true);
        try {
            const [user, plans, groups] = await Promise.all([
                api("/user/getUserInfoById", { id: ticket.user_id }),
                api("/plan/fetch"),
                api("/server/group/fetch"),
            ]);
            setEditor({
                user: {
                    ...user.data,
                    invite_user_email: user.data.invite_user?.email,
                },
                plans: plans.data ?? [],
                groups: groups.data ?? [],
            });
        } catch (error) {
            act.message.error((error as Error).message);
        } finally {
            setUserLoading(false);
        }
    };

    const closeTicket = () =>
        act.modal.confirm({
            title: "关闭此工单？",
            onOk: async () => {
                await api("/ticket/close", { id: Number(id) }, "POST");
                reload.current();
            },
        });

    return (
        <main className="ticket-chat" aria-label="工单对话">
            <header className="ticket-chat-header">
                <div className="ticket-chat-subject">
                    <strong title={ticket?.subject}>
                        {ticket?.subject ?? "工单详情"}
                    </strong>
                    {ticket && (
                        <Tag color={closed ? "green" : undefined}>
                            {closed ? "已关闭" : "处理中"}
                        </Tag>
                    )}
                </div>
                <div className="ticket-chat-controls">
                    <Tooltip title="用户管理">
                        <Button
                            type="text"
                            aria-label="用户管理"
                            icon={<UserOutlined />}
                            loading={userLoading}
                            disabled={!ticket}
                            onClick={openUser}
                        />
                    </Tooltip>
                    <Divider type="vertical" />
                    <Tooltip title="TA的流量记录">
                        <Button
                            type="text"
                            aria-label="TA的流量记录"
                            icon={<SolutionOutlined />}
                            disabled={!ticket}
                            onClick={() => setTrafficOpen(true)}
                        />
                    </Tooltip>
                </div>
            </header>
            {resource.error && (
                <Alert
                    type="error"
                    showIcon
                    message={resource.error}
                    action={
                        <Button size="small" onClick={() => reload.current()}>
                            重试
                        </Button>
                    }
                />
            )}
            <div
                className="ticket-chat-messages"
                ref={messages}
                role="log"
                aria-label="工单消息"
                onScroll={() => {
                    const node = messages.current;
                    if (node)
                        autoScroll.current =
                            node.scrollHeight -
                                node.scrollTop -
                                node.clientHeight <
                            60;
                }}
            >
                {!ticket && !resource.error && (
                    <div className="ticket-chat-loading">
                        <Spin />
                    </div>
                )}
                {(ticket?.message ?? []).map((entry: Row) => (
                    <div
                        className={
                            "ticket-chat-entry" + (entry.is_me ? " staff" : "")
                        }
                        key={entry.id}
                    >
                        <time
                            dateTime={new Date(
                                Number(entry.created_at) * 1000,
                            ).toISOString()}
                        >
                            {dayjs(Number(entry.created_at) * 1000).format(
                                "YYYY/MM/DD HH:mm",
                            )}
                        </time>
                        <div className="ticket-chat-bubble">
                            {entry.message}
                        </div>
                    </div>
                ))}
            </div>
            <footer className="ticket-chat-footer">
                <div className="ticket-chat-compose">
                    <Input.TextArea
                        aria-label="工单回复"
                        placeholder={
                            closed ? "工单已关闭" : "输入内容回复工单..."
                        }
                        value={message}
                        onChange={(event) => setMessage(event.target.value)}
                        disabled={!ticket || closed}
                        autoSize={{ minRows: 1, maxRows: 4 }}
                        onKeyDown={(event) => {
                            if (
                                event.key === "Enter" &&
                                !event.shiftKey &&
                                !event.nativeEvent.isComposing
                            ) {
                                event.preventDefault();
                                send();
                            }
                        }}
                    />
                    <Button
                        loading={act.busy}
                        disabled={!ticket || closed || !message.trim()}
                        onClick={send}
                    >
                        发送
                    </Button>
                </div>
                <div className="ticket-chat-footer-actions">
                    <a href="#/ticket">返回工单列表</a>
                    <Button
                        type="link"
                        size="small"
                        disabled={!ticket || closed}
                        onClick={closeTicket}
                    >
                        关闭工单
                    </Button>
                </div>
            </footer>
            {editor && (
                <UserEditor
                    user={editor.user}
                    plans={editor.plans}
                    groups={editor.groups}
                    close={() => setEditor(undefined)}
                    save={async (values) => {
                        await api(
                            "/user/update",
                            { ...values, id: editor.user.id },
                            "POST",
                        );
                        act.message.success("用户已保存");
                    }}
                />
            )}
            {trafficOpen && ticket && (
                <TrafficRecords
                    userId={ticket.user_id}
                    close={() => setTrafficOpen(false)}
                />
            )}
        </main>
    );
}
