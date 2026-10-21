import { useEffect, useMemo, useState } from "react";
import {
    Alert,
    Card,
    Col,
    Drawer,
    Row as GridRow,
    Spin,
    Statistic,
    Table,
    Tag,
} from "antd";
import {
    DataCard,
    ErrorNotice,
    KeyValues,
    Page,
    formatDate,
    useResource,
} from "../components";
import OriginalIcon from "../OriginalIcon";
import Chart from "../Chart";
import { incomeOptions, rankingOptions } from "../chart-options";
import type { Row } from "../types";
const names: Record<string, string> = {
    failedJobs: "失败任务",
    jobsPerMinute: "每分钟任务数",
    processes: "工作进程",
    recentJobs: "近期任务",
    pausedMasters: "暂停的管理进程",
};

export function Overview() {
    const stats = useResource("/stat/getOverride"),
        orders = useResource("/stat/getOrder"),
        config = useResource("/config/fetch");
    const currency = config.data?.site?.currency ?? "CNY",
        data = stats.data ?? {};
    const chart = useMemo(
        () => incomeOptions(Array.isArray(orders.data) ? orders.data : []),
        [orders.data],
    );
    const commissionFilters = () =>
        sessionStorage.setItem(
            "admin-order-initial-filters",
            JSON.stringify([
                { key: "status", condition: "=", value: 3 },
                { key: "commission_status", condition: "=", value: 0 },
                { key: "commission_balance", condition: ">", value: 0 },
            ]),
        );
    return (
        <div className="dashboard-page">
            <Page title="仪表盘">
                {!!data.ticket_pending_total && (
                    <Alert
                        className="overview-notice"
                        type="error"
                        message={
                            <span>
                                有 {data.ticket_pending_total} 条工单等待处理{" "}
                                <a href="#/ticket">立即处理</a>
                            </span>
                        }
                    />
                )}
                {!!data.commission_pending_total && (
                    <Alert
                        className="overview-notice"
                        type="error"
                        message={
                            <span>
                                有 {data.commission_pending_total}{" "}
                                笔佣金等待确认{" "}
                                <a href="#/order" onClick={commissionFilters}>
                                    立即处理
                                </a>
                            </span>
                        }
                    />
                )}
                <DataCard
                    error={stats.error || orders.error}
                    loading={stats.loading || orders.loading}
                >
                    <div className="overview-summary">
                        <div className="overview-shortcuts">
                            {[
                                ["/config/system", "系统设置", "si-equalizer"],
                                ["/order", "订单管理", "si-list"],
                                ["/plan", "订阅管理", "si-bag"],
                                ["/user", "用户管理", "si-users"],
                            ].map(([path, label, icon]) => (
                                <a key={path} href={"#" + path}>
                                    <OriginalIcon name={icon} />
                                    <span>{label}</span>
                                </a>
                            ))}
                        </div>
                        <div className="overview-hero">
                            {[
                                {
                                    label: "在线人数",
                                    value: data.online_user ?? 0,
                                    icon: "fa-users",
                                },
                                {
                                    label: "今日收入",
                                    value: (data.day_income ?? 0) / 100,
                                    icon: "fa-chart-line",
                                    precision: 2,
                                    suffix: currency,
                                },
                                {
                                    label: "实时注册",
                                    value: data.day_register_total ?? 0,
                                    icon: "fa-user",
                                },
                            ].map((item) => (
                                <div key={item.label} className="overview-stat">
                                    <OriginalIcon
                                        className="overview-stat-icon"
                                        name={item.icon}
                                    />
                                    <Statistic
                                        title={item.label}
                                        value={item.value}
                                        precision={item.precision}
                                        suffix={item.suffix}
                                    />
                                </div>
                            ))}
                        </div>
                        <div className="overview-secondary">
                            {[
                                [
                                    ((data.month_income ?? 0) / 100).toFixed(
                                        2,
                                    ) +
                                        " " +
                                        currency,
                                    "本月收入",
                                ],
                                [
                                    (
                                        (data.last_month_income ?? 0) / 100
                                    ).toFixed(2) +
                                        " " +
                                        currency,
                                    "上月收入",
                                ],
                                [
                                    (
                                        (data.commission_last_month_payout ??
                                            0) / 100
                                    ).toFixed(2) +
                                        " " +
                                        currency,
                                    "上月佣金支出",
                                ],
                                [
                                    data.month_register_total ?? "-",
                                    "本月新增用户",
                                ],
                            ].map(([value, label]) => (
                                <div key={String(label)}>
                                    <span>{value}</span>
                                    <label>{label}</label>
                                </div>
                            ))}
                        </div>
                        <div className="overview-chart">
                            <Chart option={chart} label="订单统计趋势" />
                        </div>
                    </div>
                </DataCard>
                <div className="rank-grid">
                    {[
                        ["getServerTodayRank", "今日节点流量排行"],
                        ["getServerLastRank", "昨日节点流量排行"],
                        ["getUserTodayRank", "今日用户流量排行"],
                        ["getUserLastRank", "昨日用户流量排行"],
                    ].map(([endpoint, title]) => (
                        <Ranking
                            key={endpoint}
                            endpoint={endpoint}
                            title={title}
                        />
                    ))}
                </div>
            </Page>
        </div>
    );
}
function Ranking({ endpoint, title }: { endpoint: string; title: string }) {
    const resource = useResource("/stat/" + endpoint),
        option = useMemo(
            () =>
                rankingOptions(
                    Array.isArray(resource.data) ? resource.data : [],
                ),
            [resource.data],
        );
    return (
        <Card className="rank-card" title={title}>
            <ErrorNotice error={resource.error} />
            <Spin spinning={resource.loading}>
                <div className="ranking-chart">
                    <Chart option={option} label={title} />
                </div>
            </Spin>
        </Card>
    );
}

export function Queue() {
    const stats = useResource("/system/getQueueStats");
    const workload = useResource("/system/getQueueWorkload");
    const status = useResource("/system/getSystemStatus");
    const masters = useResource("/system/getQueueMasters");
    const [current, setCurrent] = useState(1);
    const logs = useResource("/system/getSystemLog", {
        current,
        page_size: 20,
    });
    const [detail, setDetail] = useState<Row>();
    useEffect(() => {
        const timer = setInterval(() => {
            if (document.hidden) return;
            stats.reload();
            workload.reload();
            status.reload();
            masters.reload();
        }, 3000);
        return () => clearInterval(timer);
    }, []);
    return (
        <Page title="队列监控">
            <div className="queue-summary">
                <DataCard
                    title="总览"
                    error={stats.error || status.error}
                    loading={stats.loading}
                >
                    <GridRow gutter={[0, 0]}>
                        {[
                            ["jobsPerMinute", "当前作业量"],
                            ["recentJobs", "近一小时处理量"],
                            ["failedJobs", "7日内报错数量"],
                        ].map(([key, label]) => (
                            <Col xs={24} sm={12} xl={6} key={key}>
                                <div className="queue-summary-stat">
                                    <div>{label}</div>
                                    <Statistic value={stats.data?.[key] ?? 0} />
                                </div>
                            </Col>
                        ))}
                        <Col xs={24} sm={12} xl={6}>
                            <div className="queue-summary-stat queue-status">
                                <div>状态</div>
                                {stats.data && (
                                    <div className="queue-summary-status">
                                        <span>
                                            {stats.data.status
                                                ? "运行中"
                                                : "未启动"}
                                        </span>
                                        <OriginalIcon
                                            className="queue-status-icon"
                                            name={
                                                stats.data.status
                                                    ? "si-check"
                                                    : "si-close"
                                            }
                                        />
                                    </div>
                                )}
                            </div>
                        </Col>
                    </GridRow>
                </DataCard>
            </div>
            <DataCard
                title="当前作业详情"
                error={workload.error}
                loading={workload.loading}
            >
                <Table<Row>
                    rowKey="name"
                    dataSource={(workload.data ?? []).filter(
                        (queue: Row) => queue.name !== "default",
                    )}
                    pagination={false}
                    columns={[
                        {
                            title: "队列名称",
                            dataIndex: "name",
                            render: (name) =>
                                (
                                    ({
                                        order_handle: "订单队列",
                                        send_email: "邮件队列",
                                        send_email_mass: "邮件群发队列",
                                        send_telegram: "Telegram消息队列",
                                        stat: "统计队列",
                                        traffic_fetch: "流量消费队列",
                                    }) as Record<string, string>
                                )[name] ?? name,
                        },
                        { title: "作业量", dataIndex: "processes" },
                        { title: "任务量", dataIndex: "length" },
                        {
                            title: "占用时间",
                            dataIndex: "wait",
                            align: "right",
                            render: (wait) => `${wait ?? 0}s`,
                        },
                    ]}
                />
            </DataCard>
            <DataCard
                title="监控状态"
                error={stats.error || status.error}
                loading={stats.loading}
            >
                <GridRow gutter={[20, 20]}>
                    {[
                        "failedJobs",
                        "jobsPerMinute",
                        "processes",
                        "recentJobs",
                        "pausedMasters",
                    ].map((k) => (
                        <Col xs={12} md={4} key={k}>
                            <Statistic
                                title={names[k]}
                                value={stats.data?.[k] ?? 0}
                            />
                        </Col>
                    ))}
                </GridRow>
                <p>
                    <Tag color={stats.data?.status ? "green" : "red"}>
                        队列：{stats.data?.status ? "运行中" : "未运行"}
                    </Tag>
                    <Tag color={status.data?.schedule ? "green" : "red"}>
                        调度：{status.data?.schedule ? "运行中" : "未运行"}
                    </Tag>
                </p>
            </DataCard>
            <DataCard
                title="队列管理进程"
                error={masters.error}
                loading={masters.loading}
            >
                <Table<Row>
                    rowKey={(r) => r.name ?? r.pid}
                    dataSource={
                        Array.isArray(masters.data ?? masters.response)
                            ? (masters.data ?? masters.response)
                            : Object.values(
                                  masters.data ?? masters.response ?? {},
                              ).filter(
                                  (row: any) =>
                                      row &&
                                      typeof row === "object" &&
                                      row.name,
                              )
                    }
                    columns={["name", "pid", "status"].map((k) => ({
                        title: { name: "名称", pid: "PID", status: "状态" }[k],
                        dataIndex: k,
                    }))}
                />
            </DataCard>
            <DataCard
                title="系统日志"
                error={logs.error}
                loading={logs.loading}
            >
                <Table<Row>
                    rowKey="id"
                    dataSource={logs.data ?? []}
                    pagination={{
                        current,
                        pageSize: 20,
                        total: logs.response.total,
                        onChange: setCurrent,
                    }}
                    columns={[
                        { title: "等级", dataIndex: "level" },
                        {
                            title: "内容",
                            dataIndex: "title",
                            render: (v, r) => (
                                <a onClick={() => setDetail(r)}>{v}</a>
                            ),
                        },
                        { title: "请求地址", dataIndex: "uri" },
                        {
                            title: "时间",
                            dataIndex: "created_at",
                            render: formatDate,
                        },
                    ]}
                />
            </DataCard>
            {detail && (
                <Drawer
                    title="日志详情"
                    open
                    width={850}
                    onClose={() => setDetail(undefined)}
                >
                    <KeyValues data={detail} />
                </Drawer>
            )}
        </Page>
    );
}
