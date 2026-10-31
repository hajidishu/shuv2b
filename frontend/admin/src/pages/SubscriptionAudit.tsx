import { useState } from "react";
import {
    Button,
    Alert,
    Col,
    Descriptions,
    Drawer,
    Form,
    InputNumber,
    Modal,
    Row as GridRow,
    Segmented,
    Space,
    Table,
    Statistic,
    Tag,
    Tooltip,
    Typography,
} from "antd";
import {
    FilterOutlined,
    ReloadOutlined,
    SettingOutlined,
} from "@ant-design/icons";
import { api } from "../api";
import {
    DataCard,
    ErrorNotice,
    formatDate,
    Page,
    useAction,
    useResource,
} from "../components";
import { convertFilters, Filters } from "../UserFilters";
import type { Filter, Row } from "../types";

const results = [
    { value: "success", label: "成功", color: "green" },
    { value: "unavailable", label: "账号不可用", color: "orange" },
    { value: "error", label: "失败", color: "red" },
];
export default function SubscriptionAudit() {
    const [filters, setFilters] = useState<Filter[]>(() => {
            const value = sessionStorage.getItem("subscription-audit-filters");
            sessionStorage.removeItem("subscription-audit-filters");
            try {
                return value ? JSON.parse(value) : [];
            } catch {
                return [];
            }
        }),
        [draft, setDraft] = useState<Filter[]>([]);
    const [open, setOpen] = useState(false),
        [settingsOpen, setSettingsOpen] = useState(false);
    const [current, setCurrent] = useState(1),
        [pageSize, setPageSize] = useState(20);
    const [saving, setSaving] = useState(false),
        [error, setError] = useState("");
    const [form] = Form.useForm();
    const [dimension, setDimension] = useState("ip"),
        [detailId, setDetailId] = useState<number | null>(null);
    const act = useAction();
    const resource = useResource("/subscribe-audit/fetch", {
        current,
        pageSize,
        filter: filters,
    });
    const settings = resource.response.settings;
    const stats = useResource("/subscribe-audit/stats", {
        dimension,
        filter: filters,
    });
    const detail = useResource(
        "/subscribe-audit/detail",
        { id: detailId },
        detailId !== null,
    );
    const selected =
        detail.data && String(detail.data.id) === String(detailId)
            ? detail.data
            : null;
    const http = selected?.request_details;
    const rankLabel =
        dimension === "user"
            ? "用户邮箱"
            : dimension === "ua"
              ? "客户端 UA"
              : "IP地址";
    const refresh = async () => {
        resource.reload();
        try {
            await act.run(
                () =>
                    api("/subscribe-audit/stats", {
                        dimension,
                        filter: filters,
                        fresh: 1,
                    }),
                "",
            );
            stats.reload();
        } catch {
            /* useAction displays the error */
        }
    };
    const saveSettings = async (values: Row) => {
        setSaving(true);
        setError("");
        try {
            await api("/subscribe-audit/settings", values, "POST");
            setSettingsOpen(false);
            setCurrent(1);
            resource.reload();
            stats.reload();
            act.message.success("保留设置已保存，旧记录将自动清理");
        } catch (error) {
            setError((error as Error).message);
        } finally {
            setSaving(false);
        }
    };
    return (
        <Page title="订阅审计">
            <DataCard
                title={
                    <div className="audit-stats-heading">
                        <span>订阅拉取统计</span>
                        <Segmented
                            aria-label="统计维度"
                            value={dimension}
                            onChange={(value) => setDimension(String(value))}
                            options={[
                                { label: "IP排行", value: "ip" },
                                { label: "UA排行", value: "ua" },
                                { label: "用户排行", value: "user" },
                            ]}
                        />
                    </div>
                }
                loading={stats.loading}
                error={stats.error}
            >
                <GridRow gutter={[24, 16]} className="audit-statistics">
                    {[
                        ["保留记录数", "total"],
                        ["涉及用户", "users"],
                        ["来源 IP 数", "ips"],
                        ["UA 数", "uas"],
                    ].map(([label, key]) => (
                        <Col xs={12} md={6} key={key}>
                            <Statistic
                                title={label}
                                value={stats.data?.[key] ?? 0}
                            />
                        </Col>
                    ))}
                </GridRow>
                <Table<Row>
                    className="audit-ranking"
                    size="small"
                    rowKey={(row) => String(row.value)}
                    pagination={false}
                    dataSource={stats.data?.ranking ?? []}
                    scroll={{ x: 600 }}
                    columns={[
                        {
                            title: "排名",
                            width: 65,
                            render: (_, _row, index) => index + 1,
                        },
                        {
                            title: rankLabel,
                            dataIndex: "label",
                            render: (label) => (
                                <Tooltip title={label}>
                                    <span className="audit-ranking-label">
                                        {label}
                                    </span>
                                </Tooltip>
                            ),
                        },
                        {
                            title: "拉取次数",
                            dataIndex: "count",
                            width: 110,
                            align: "right",
                        },
                        {
                            title: "最后拉取",
                            dataIndex: "last_at",
                            width: 180,
                            render: formatDate,
                        },
                    ]}
                />
                <p className="audit-stats-note">
                    仅统计保留明细，与用户管理的累计次数不同 · 前 10 名 · 统计最多缓存 60
                    秒，点击刷新可立即更新
                </p>
            </DataCard>
            <DataCard loading={resource.loading} error={resource.error}>
                <div className="table-toolbar audit-toolbar">
                    <Space wrap>
                        <Button
                            aria-label="过滤器"
                            icon={<FilterOutlined />}
                            onClick={() => {
                                setDraft(filters);
                                setOpen(true);
                            }}
                        >
                            过滤器{filters.length ? ` (${filters.length})` : ""}
                        </Button>
                        <Button
                            aria-label="刷新"
                            icon={<ReloadOutlined />}
                            onClick={refresh}
                            loading={act.busy}
                        >
                            刷新
                        </Button>
                        <Button
                            aria-label="保留设置"
                            icon={<SettingOutlined />}
                            disabled={!settings}
                            onClick={() => {
                                form.setFieldsValue(settings);
                                setError("");
                                setSettingsOpen(true);
                            }}
                        >
                            保留设置
                        </Button>
                    </Space>
                    {settings && (
                        <span className="audit-retention">
                            保留 {settings.retention_days} 天 · 最多{" "}
                            {Number(settings.max_records).toLocaleString()} 条
                        </span>
                    )}
                </div>
                <Table<Row>
                    className="audit-records"
                    rowKey="id"
                    size="small"
                    dataSource={resource.data ?? []}
                    scroll={{ x: 1390 }}
                    pagination={{
                        current,
                        pageSize,
                        total: resource.response.total ?? 0,
                        showSizeChanger: true,
                        pageSizeOptions: [10, 20, 50, 100],
                        showTotal: (total) => `共 ${total} 条`,
                        onChange: (page, size) => {
                            setCurrent(size !== pageSize ? 1 : page);
                            setPageSize(size);
                        },
                    }}
                    columns={[
                        {
                            title: "拉取时间",
                            dataIndex: "requested_at",
                            width: 180,
                            render: (value, record) => (
                                <a onClick={() => setDetailId(record.id)}>
                                    {formatDate(value)}
                                </a>
                            ),
                        },
                        {
                            title: "用户邮箱",
                            dataIndex: "email",
                            width: 230,
                            render: (value) => (
                                <span className="break-word">{value}</span>
                            ),
                        },
                        {
                            title: "IP地址",
                            dataIndex: "ip",
                            width: 230,
                            render: (value) => value || "未知",
                        },
                        {
                            title: "客户端 UA",
                            dataIndex: "ua",
                            width: 330,
                            render: (value) => (
                                <Tooltip title={value}>
                                    <span className="audit-ua">
                                        {value || "未提供"}
                                    </span>
                                </Tooltip>
                            ),
                        },
                        {
                            title: "订阅格式",
                            dataIndex: "format",
                            width: 110,
                            render: (value) => value || "-",
                        },
                        {
                            title: "结果",
                            dataIndex: "result",
                            width: 130,
                            render: (value) => {
                                const result = results.find(
                                    (result) => result.value === value,
                                );
                                return (
                                    <Tag color={result?.color}>
                                        {result?.label ?? value}
                                    </Tag>
                                );
                            },
                        },
                        { title: "HTTP状态", dataIndex: "status", width: 90 },
                        {
                            title: "操作",
                            width: 80,
                            fixed: "right",
                            render: (_, record) => (
                                <a onClick={() => setDetailId(record.id)}>
                                    详情
                                </a>
                            ),
                        },
                    ]}
                />
            </DataCard>
            <Drawer
                title="过滤器"
                width={360}
                open={open}
                className="subscription-audit-filter-drawer"
                onClose={() => setOpen(false)}
                footer={
                    <Space
                        style={{ display: "flex", justifyContent: "flex-end" }}
                    >
                        <Button
                            aria-label="重置"
                            onClick={() => {
                                setFilters([]);
                                setDraft([]);
                                setCurrent(1);
                                setOpen(false);
                            }}
                        >
                            重置
                        </Button>
                        <Button onClick={() => setOpen(false)}>取消</Button>
                        <Button
                            aria-label="检索"
                            type="primary"
                            onClick={() => {
                                try {
                                    setFilters(convertFilters(draft));
                                    setCurrent(1);
                                    setOpen(false);
                                } catch (error) {
                                    act.message.error((error as Error).message);
                                }
                            }}
                        >
                            检索
                        </Button>
                    </Space>
                }
            >
                <Filters
                    value={draft}
                    onChange={setDraft}
                    keys={[
                        "email",
                        "user_id",
                        "ip",
                        "ua",
                        "requested_at",
                        "format",
                        "result",
                    ]}
                    fieldLabels={{
                        email: "用户邮箱",
                        user_id: "用户ID",
                        ip: "IP地址",
                        ua: "客户端 UA",
                        requested_at: "拉取时间",
                        format: "订阅格式",
                        result: "结果",
                    }}
                    valueOptions={{ result: results }}
                />
            </Drawer>
            <Drawer
                title="订阅拉取详情"
                open={detailId !== null}
                onClose={() => setDetailId(null)}
                width="min(800px, 100vw)"
                className="audit-detail-drawer"
                destroyOnHidden
            >
                <ErrorNotice error={detail.error} />
                {detail.loading && <p>正在加载…</p>}
                {selected && (
                    <>
                        <Descriptions
                            title="基本信息"
                            size="small"
                            bordered
                            column={1}
                        >
                            <Descriptions.Item label="用户邮箱">
                                {selected.email}
                            </Descriptions.Item>
                            <Descriptions.Item label="用户当前订阅链接">
                                {selected.current_subscribe_url ? (
                                    <Typography.Text copyable className="break-word">
                                        {selected.current_subscribe_url}
                                    </Typography.Text>
                                ) : "用户已删除或订阅令牌不存在"}
                            </Descriptions.Item>
                            <Descriptions.Item label="拉取时间">
                                {formatDate(selected.requested_at)}
                            </Descriptions.Item>
                            <Descriptions.Item label="客户端 IP">
                                {selected.ip || "未知"}
                            </Descriptions.Item>
                            <Descriptions.Item label="客户端 UA">
                                <span className="audit-ua">
                                    {selected.ua || "未提供"}
                                </span>
                            </Descriptions.Item>
                            <Descriptions.Item label="订阅格式">
                                {selected.format || "-"}
                            </Descriptions.Item>
                            <Descriptions.Item label="处理结果">
                                {results.find(
                                    (result) =>
                                        result.value === selected.result,
                                )?.label ?? selected.result}
                            </Descriptions.Item>
                            <Descriptions.Item label="HTTP状态">
                                {selected.status}
                            </Descriptions.Item>
                        </Descriptions>
                        {http ? (
                            <>
                                <Descriptions
                                    title="HTTP 请求"
                                    size="small"
                                    bordered
                                    column={1}
                                >
                                    <Descriptions.Item label="请求方法">
                                        {http.method}
                                    </Descriptions.Item>
                                    <Descriptions.Item label="请求地址">
                                        <span className="break-word">
                                            {http.url}
                                        </span>
                                    </Descriptions.Item>
                                    <Descriptions.Item label="服务端收到的协议">
                                        {http.protocol || "未知"}
                                    </Descriptions.Item>
                                    <Descriptions.Item label="连接来源 IP">
                                        {http.remote_ip || "未知"}
                                    </Descriptions.Item>
                                    <Descriptions.Item label="服务端处理耗时">
                                        {http.duration_ms} ms
                                    </Descriptions.Item>
                                    {http.leak_probe?.length > 0 && (
                                        <Descriptions.Item label="关联排查任务">
                                            {http.leak_probe.map(
                                                (mark: Row) => (
                                                    <a
                                                        key={mark.round_id}
                                                        href="#/leak-probe"
                                                        onClick={() => {
                                                            sessionStorage.setItem(
                                                                "leak-probe-task",
                                                                String(
                                                                    mark.task_id,
                                                                ),
                                                            );
                                                            sessionStorage.setItem(
                                                                "leak-probe-round",
                                                                String(
                                                                    mark.round_id,
                                                                ),
                                                            );
                                                        }}
                                                    >
                                                        查看任务 #{mark.task_id}{" "}
                                                        ·{" "}
                                                        {String(
                                                            mark.branch,
                                                        ).toUpperCase()}
                                                        组
                                                    </a>
                                                ),
                                            )}
                                        </Descriptions.Item>
                                    )}
                                </Descriptions>
                                <p className="audit-settings-help">
                                    订阅 token 按实际请求显示；Cookie、Authorization 等凭据仍脱敏。
                                    历史记录中已脱敏的 token 无法还原，可复制上方用户当前订阅链接。
                                </p>
                                {http.truncated && (
                                    <Alert
                                        type="info"
                                        message="请求信息过长，部分内容已截断"
                                    />
                                )}
                                <Table
                                    title={() => "查询参数"}
                                    tableLayout="fixed"
                                    size="small"
                                    rowKey="name"
                                    pagination={false}
                                    dataSource={Object.entries(
                                        http.query ?? {},
                                    ).map(([name, value]) => ({ name, value }))}
                                    columns={[
                                        {
                                            title: "参数",
                                            dataIndex: "name",
                                            width: 180,
                                        },
                                        {
                                            title: "值",
                                            dataIndex: "value",
                                            render: (value) => (
                                                <pre className="audit-http-value">
                                                    {typeof value === "string"
                                                        ? value
                                                        : JSON.stringify(
                                                              value,
                                                              null,
                                                              2,
                                                          )}
                                                </pre>
                                            ),
                                        },
                                    ]}
                                />
                                <Table
                                    title={() => "请求头"}
                                    tableLayout="fixed"
                                    size="small"
                                    rowKey="name"
                                    pagination={false}
                                    dataSource={Object.entries(
                                        http.headers ?? {},
                                    ).map(([name, value]) => ({ name, value }))}
                                    columns={[
                                        {
                                            title: "请求头",
                                            dataIndex: "name",
                                            width: 180,
                                        },
                                        {
                                            title: "值",
                                            dataIndex: "value",
                                            render: (value) => (
                                                <pre className="audit-http-value">
                                                    {String(value)}
                                                </pre>
                                            ),
                                        },
                                    ]}
                                />
                            </>
                        ) : (
                            <Alert
                                type="info"
                                message="此记录未保存 HTTP 请求详情（详情功能上线前的记录不会补录）"
                            />
                        )}
                    </>
                )}
            </Drawer>
            <Modal
                title="订阅审计保留设置"
                width={460}
                open={settingsOpen}
                onCancel={() => setSettingsOpen(false)}
                onOk={() => form.submit()}
                confirmLoading={saving}
                okButtonProps={{ "aria-label": "保存" }}
                okText="保存"
                cancelText="取消"
                destroyOnHidden
            >
                <ErrorNotice error={error} />
                <Form
                    form={form}
                    layout="vertical"
                    onFinish={saveSettings}
                    requiredMark={false}
                >
                    <Form.Item
                        name="retention_days"
                        label="保留天数"
                        rules={[
                            {
                                required: true,
                                type: "integer",
                                min: 1,
                                max: 3650,
                            },
                        ]}
                    >
                        <InputNumber
                            min={1}
                            max={3650}
                            precision={0}
                            addonAfter="天"
                            style={{ width: "100%" }}
                        />
                    </Form.Item>
                    <Form.Item
                        name="max_records"
                        label="最大记录条数"
                        rules={[
                            {
                                required: true,
                                type: "integer",
                                min: 1,
                                max: 100000000,
                            },
                        ]}
                    >
                        <InputNumber
                            min={1}
                            max={100000000}
                            precision={0}
                            addonAfter="条"
                            style={{ width: "100%" }}
                        />
                    </Form.Item>
                    <p className="audit-settings-help">
                        超过任意限制时，系统自动删除最旧记录。降低上限或缩短天数会清理已有记录，删除后无法恢复。
                    </p>
                </Form>
            </Modal>
        </Page>
    );
}
