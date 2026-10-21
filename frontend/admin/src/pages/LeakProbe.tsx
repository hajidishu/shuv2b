import { useEffect, useState } from "react";
import {
    Alert,
    Button,
    Col,
    Descriptions,
    Drawer,
    Form,
    Input,
    InputNumber,
    Modal,
    Row as GridRow,
    Select,
    Space,
    Statistic,
    Table,
    Tag,
    Timeline,
} from "antd";
import {
    PlusOutlined,
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
import { Filters, convertFilters } from "../UserFilters";
import type { Filter, Row } from "../types";

const statuses: Row = {
    active: "观察中",
    paused: "已暂停",
    completed: "已结束",
    identified: "候选已收敛",
};
const stateColors: Row = {
    active: "blue",
    paused: "orange",
    completed: "default",
    identified: "purple",
};
const results = [
    { value: "unknown", label: "未确认" },
    { value: "normal", label: "正常" },
    { value: "abnormal", label: "人工确认异常" },
];
const events: Row = {
    created: "创建任务",
    results: "保存人工结果",
    pause: "暂停",
    resume: "恢复",
    finish: "结束任务",
    advance: "推进分支",
    round_started: "开始新一轮",
    identified: "候选收敛",
};
const stamp = (value: any) => (value ? formatDate(value) : "尚无记录");
const nodeLabel = (node: Row) =>
    `${node.server?.name ?? "节点已删除"} · ${node.node_type} #${node.node_id} · ${node.server?.host ?? "-"}`;

export default function LeakProbe() {
    const [page, setPage] = useState(1),
        [taskId, setTaskId] = useState<number | null>(() => {
            const value = sessionStorage.getItem("leak-probe-task");
            sessionStorage.removeItem("leak-probe-task");
            return value ? Number(value) : null;
        });
    const [roundId, setRoundId] = useState<number | null>(() => {
            const value = sessionStorage.getItem("leak-probe-round");
            sessionStorage.removeItem("leak-probe-round");
            return value ? Number(value) : null;
        }),
        [branch, setBranch] = useState<string>(),
        [memberPage, setMemberPage] = useState(1),
        [email, setEmail] = useState("");
    const [createOpen, setCreateOpen] = useState(false),
        [poolOpen, setPoolOpen] = useState(false),
        [advanceOpen, setAdvanceOpen] = useState(false);
    const [scope, setScope] = useState("all"),
        [filters, setFilters] = useState<Filter[]>([]),
        [preview, setPreview] = useState<Row | null>(null);
    const [error, setError] = useState(""),
        [saving, setSaving] = useState(false),
        [nodeKey, setNodeKey] = useState<string>();
    const [selectedBranch, setSelectedBranch] = useState("a");
    const [createForm] = Form.useForm(),
        [resultForm] = Form.useForm(),
        [advanceForm] = Form.useForm();
    const act = useAction();
    const tasks = useResource("/leak-probe/fetch", { current: page });
    const pool = useResource("/leak-probe/nodes");
    const plans = useResource("/plan/fetch");
    const detail = useResource(
        "/leak-probe/detail",
        { id: taskId },
        taskId !== null,
    );
    const task =
        detail.data && Number(detail.data.id) === taskId ? detail.data : null;
    const rounds: Row[] = task ? (detail.response.rounds ?? []) : [];
    const selectedRound =
        rounds.find((round) => Number(round.id) === roundId) ??
        rounds.find(
            (round) => Number(round.id) === Number(task?.current_round_id),
        );
    const currentRound = rounds.find(
        (round) => Number(round.id) === Number(task?.current_round_id),
    );
    useEffect(() => {
        if (
            selectedRound &&
            task &&
            ["active", "paused"].includes(task.status) &&
            selectedRound.id === task.current_round_id
        ) {
            resultForm.resetFields();
            resultForm.setFieldsValue({
                result_a: selectedRound.result_a,
                result_b: selectedRound.result_b,
                note: selectedRound.note,
            });
        }
    }, [
        selectedRound?.id,
        selectedRound?.result_a,
        selectedRound?.result_b,
        selectedRound?.note,
    ]);
    const members = useResource(
        "/leak-probe/members",
        {
            round_id: selectedRound?.id,
            branch,
            email,
            current: memberPage,
            pageSize: 20,
        },
        !!selectedRound,
    );
    const mutable = task && ["active", "paused"].includes(task.status);
    const ready = (pool.data ?? []).filter(
        (node: Row) =>
            ["ready", "retired"].includes(node.state) && node.config_matches,
    );
    const nodeOptions = ready.map((node: Row) => ({
        value: Number(node.id),
        label: nodeLabel(node),
    }));
    const advanceNodeOptions = (pool.data ?? [])
        .filter(
            (node: Row) =>
                node.config_matches &&
                (["ready", "retired"].includes(node.state) ||
                    (node.state === "active" &&
                        [currentRound?.a_node_id, currentRound?.b_node_id]
                            .map(Number)
                            .includes(Number(node.id)))),
        )
        .map((node: Row) => ({
            value: Number(node.id),
            label: nodeLabel(node),
        }));
    const refresh = () => {
        tasks.reload();
        detail.reload();
        members.reload();
        pool.reload();
    };
    const openTask = (id: number) => {
        setTaskId(id);
        setRoundId(null);
        setBranch(undefined);
        setEmail("");
        setMemberPage(1);
        setError("");
    };
    const mutate = async (action: string, extra: Row = {}) => {
        if (!task) return;
        await act.run(() =>
            api(
                "/leak-probe/update",
                {
                    id: task.id,
                    round_id: task.current_round_id,
                    action,
                    ...extra,
                },
                "POST",
            ),
        );
        refresh();
    };
    const confirm = (action: string, title: string, content: string) =>
        act.modal.confirm({ title, content, onOk: () => mutate(action) });
    const saveResults = async (values: Row) => {
        try {
            await mutate("results", values);
        } catch {}
    };
    const submitTask = async () => {
        setError("");
        setSaving(true);
        try {
            const values = await createForm.validateFields();
            if (!preview) throw new Error("请先预览并确认参与名单");
            const response = await api(
                "/leak-probe/create",
                {
                    ...values,
                    filter:
                        scope === "filters"
                            ? convertFilters(filters)
                            : undefined,
                    expected_count: preview.count,
                    expected_hash: preview.hash,
                },
                "POST",
            );
            setCreateOpen(false);
            refresh();
            openTask(response.data.id);
        } catch (error) {
            setError((error as Error).message || "请检查表单");
        } finally {
            setSaving(false);
        }
    };
    const previewTask = async () => {
        try {
            const values = createForm.getFieldsValue();
            const response = await act.run(
                () =>
                    api(
                        "/leak-probe/preview",
                        {
                            ...values,
                            filter:
                                scope === "filters"
                                    ? convertFilters(filters)
                                    : undefined,
                        },
                        "POST",
                    ),
                "",
            );
            setPreview(response.data);
        } catch {}
    };
    const startNext = async (values: Row) => {
        setSaving(true);
        setError("");
        try {
            await mutate("advance", { ...values, branch: selectedBranch });
            setAdvanceOpen(false);
            setRoundId(null);
            setMemberPage(1);
        } catch (error) {
            setError((error as Error).message);
        } finally {
            setSaving(false);
        }
    };
    const poolAction = async (action: string, values: Row) => {
        await act.run(() =>
            api("/leak-probe/nodes", { action, ...values }, "POST"),
        );
        pool.reload();
        setNodeKey(undefined);
    };
    return (
        <Page title="内鬼排查">
            <DataCard loading={tasks.loading} error={tasks.error}>
                <div className="table-toolbar">
                    <Space wrap>
                        <Button
                            aria-label="新建排查任务"
                            icon={<PlusOutlined />}
                            onClick={() => {
                                createForm.resetFields();
                                createForm.setFieldsValue({
                                    scope: "all",
                                    observation_hours: 24,
                                });
                                setScope("all");
                                setFilters([]);
                                setPreview(null);
                                setError("");
                                setCreateOpen(true);
                            }}
                        >
                            新建任务
                        </Button>
                        <Button
                            aria-label="专用节点池"
                            icon={<SettingOutlined />}
                            onClick={() => setPoolOpen(true)}
                        >
                            专用节点池
                        </Button>
                        <Button
                            aria-label="刷新任务"
                            icon={<ReloadOutlined />}
                            onClick={refresh}
                        >
                            刷新
                        </Button>
                    </Space>
                </div>
                <Alert
                    className="leak-intro"
                    type="info"
                    showIcon
                    message="由管理员人工检测并选择结果；系统按固定分组下发节点，不自动判墙或封禁账号。先登记两个独立、可用的专用节点，再创建任务。"
                />
                <Table<Row>
                    rowKey="id"
                    size="small"
                    dataSource={tasks.data ?? []}
                    scroll={{ x: 950 }}
                    pagination={{
                        current: page,
                        pageSize: 20,
                        total: tasks.response.total ?? 0,
                        onChange: setPage,
                        showSizeChanger: false,
                    }}
                    columns={[
                        {
                            title: "任务名称",
                            dataIndex: "name",
                            render: (name, row) => (
                                <a onClick={() => openTask(Number(row.id))}>
                                    {name}
                                </a>
                            ),
                        },
                        {
                            title: "状态",
                            dataIndex: "status",
                            render: (state) => (
                                <Tag color={stateColors[state]}>
                                    {statuses[state]}
                                </Tag>
                            ),
                        },
                        { title: "固定人数", dataIndex: "member_count" },
                        {
                            title: "观察周期",
                            dataIndex: "observation_hours",
                            render: (value) => `${value} 小时`,
                        },
                        {
                            title: "创建时间",
                            dataIndex: "created_at",
                            render: formatDate,
                        },
                        {
                            title: "操作",
                            render: (_, row) => (
                                <a onClick={() => openTask(Number(row.id))}>
                                    查看任务
                                </a>
                            ),
                        },
                    ]}
                />
            </DataCard>
            <Modal
                title="新建内鬼排查任务"
                width={680}
                open={createOpen}
                onCancel={() => setCreateOpen(false)}
                onOk={submitTask}
                confirmLoading={saving}
                okText="创建并开始"
                cancelText="取消"
                okButtonProps={{
                    "aria-label": "创建并开始",
                    disabled:
                        !preview || preview.count < 2 || preview.count > 10000,
                }}
                destroyOnHidden
            >
                <ErrorNotice error={error} />
                <Form
                    form={createForm}
                    layout="vertical"
                    requiredMark={false}
                    onValuesChange={() => setPreview(null)}
                >
                    <Form.Item
                        label="任务名称"
                        name="name"
                        rules={[{ required: true, max: 120 }]}
                    >
                        <Input />
                    </Form.Item>
                    <Form.Item
                        label="参与范围"
                        name="scope"
                        rules={[{ required: true }]}
                    >
                        <Select
                            onChange={setScope}
                            options={[
                                { value: "all", label: "全部有效套餐用户" },
                                { value: "plans", label: "指定套餐" },
                                { value: "emails", label: "邮箱清单" },
                                { value: "filters", label: "组合筛选" },
                            ]}
                        />
                    </Form.Item>
                    {scope === "plans" && (
                        <Form.Item
                            label="套餐"
                            name="plan_ids"
                            rules={[{ required: true }]}
                        >
                            <Select
                                mode="multiple"
                                options={(plans.data ?? []).map(
                                    (plan: Row) => ({
                                        value: plan.id,
                                        label: plan.name,
                                    }),
                                )}
                            />
                        </Form.Item>
                    )}
                    {scope === "emails" && (
                        <Form.Item
                            label="用户邮箱清单"
                            name="emails"
                            rules={[{ required: true }]}
                        >
                            <Input.TextArea
                                rows={4}
                                placeholder="每行一个邮箱，也可使用逗号分隔"
                            />
                        </Form.Item>
                    )}
                    {scope === "filters" && (
                        <Filters
                            keys={[
                                "email",
                                "plan_id",
                                "balance",
                                "commission_balance",
                                "expired_at",
                                "id",
                            ]}
                            value={filters}
                            onChange={(next) => {
                                setFilters(next);
                                setPreview(null);
                            }}
                            references={plans.data ?? []}
                        />
                    )}
                    <GridRow gutter={16}>
                        <Col span={12}>
                            <Form.Item
                                label="A组专用节点"
                                name="a_node_id"
                                rules={[{ required: true }]}
                            >
                                <Select
                                    aria-label="A组专用节点"
                                    options={nodeOptions}
                                />
                            </Form.Item>
                        </Col>
                        <Col span={12}>
                            <Form.Item
                                label="B组专用节点"
                                name="b_node_id"
                                rules={[{ required: true }]}
                            >
                                <Select
                                    aria-label="B组专用节点"
                                    options={nodeOptions}
                                />
                            </Form.Item>
                        </Col>
                    </GridRow>
                    <Form.Item
                        label="建议观察时长"
                        name="observation_hours"
                        rules={[
                            {
                                required: true,
                                type: "integer",
                                min: 1,
                                max: 720,
                            },
                        ]}
                    >
                        <InputNumber min={1} max={720} addonAfter="小时" />
                    </Form.Item>
                    <p className="leak-help">
                        只纳入未封禁、有有效套餐和剩余流量的非管理员账号。专用节点按本轮分组授权，无需与用户套餐权限组匹配。客户端不支持所选协议时不会计为已下发。
                    </p>
                    <Button
                        aria-label="预览参与名单"
                        onClick={previewTask}
                        loading={act.busy}
                    >
                        预览名单
                    </Button>
                    {preview && (
                        <Alert
                            type={
                                preview.count >= 2 && preview.count <= 10000
                                    ? "success"
                                    : "warning"
                            }
                            showIcon
                            message={`匹配 ${preview.count} 人（允许2至10000人）`}
                            description={
                                preview.sample?.join("、") || "没有匹配用户"
                            }
                        />
                    )}
                </Form>
            </Modal>
            <Drawer
                title="专用节点池"
                width="min(1000px, 100vw)"
                open={poolOpen}
                onClose={() => setPoolOpen(false)}
                className="leak-pool-drawer"
            >
                <ErrorNotice error={pool.error} />
                <Alert
                    type="warning"
                    showIcon
                    message="登记后节点立即从普通订阅撤下，未分配任务时不授权用户。请使用独立入口，且不要与其他节点共用父节点或地址。不同域名是否实际共用IP需要人工核实。"
                />
                <Space wrap className="leak-pool-add">
                    <Select
                        aria-label="选择登记节点"
                        showSearch
                        optionFilterProp="label"
                        style={{ minWidth: 250, maxWidth: "100%" }}
                        value={nodeKey}
                        onChange={setNodeKey}
                        options={(pool.response.available ?? []).map(
                            (node: Row) => ({
                                value: `${node.node_type}:${node.node_id}`,
                                label: `${node.name} · ${node.node_type} #${node.node_id} · ${node.host}`,
                                disabled: (pool.data ?? []).some(
                                    (p: Row) =>
                                        p.node_type === node.node_type &&
                                        p.node_id === node.node_id,
                                ),
                            }),
                        )}
                    />
                    <Button
                        aria-label="登记专用节点"
                        disabled={!nodeKey}
                        onClick={() =>
                            act.modal.confirm({
                                title: "登记为排查专用节点？",
                                content:
                                    "登记后会停止对普通用户分发该节点，节点授权也将受排查任务限制。",
                                onOk: () => {
                                    const [node_type, node_id] =
                                        nodeKey!.split(":");
                                    return poolAction("register", {
                                        node_type,
                                        node_id: Number(node_id),
                                    });
                                },
                            })
                        }
                    >
                        登记节点
                    </Button>
                </Space>
                <Table<Row>
                    rowKey="id"
                    size="small"
                    dataSource={pool.data ?? []}
                    pagination={false}
                    scroll={{ x: 750 }}
                    columns={[
                        { title: "节点", render: (_, node) => nodeLabel(node) },
                        {
                            title: "状态",
                            dataIndex: "state",
                            render: (state) =>
                                ({
                                    ready: "可用",
                                    active: "使用中",
                                    retired: "可用",
                                })[state as string],
                        },
                        {
                            title: "配置",
                            render: (_, node) =>
                                node.config_matches ? (
                                    "一致"
                                ) : (
                                    <Tag color="red">已变化或节点已删除</Tag>
                                ),
                        },
                        {
                            title: "最近授权请求",
                            dataIndex: "last_pull_at",
                            render: stamp,
                        },
                        {
                            title: "操作",
                            render: (_, node) => (
                                <Button
                                    type="link"
                                    disabled={node.state === "active"}
                                    onClick={() =>
                                        act.modal.confirm({
                                            title: "移出专用节点池？",
                                            content:
                                                "移出后恢复节点原有套餐可见范围。重新登记已暴露入口不能证明其适合新一轮排查。",
                                            onOk: () =>
                                                poolAction("remove", {
                                                    id: node.id,
                                                }),
                                        })
                                    }
                                >
                                    移出
                                </Button>
                            ),
                        },
                    ]}
                />
            </Drawer>
            <Drawer
                title={task ? `内鬼排查 · ${task.name}` : "内鬼排查"}
                width="min(1120px, 100vw)"
                open={taskId !== null}
                onClose={() => setTaskId(null)}
                className="leak-task-drawer"
                destroyOnHidden
            >
                <ErrorNotice error={detail.error} />
                {task && (
                    <>
                        <Descriptions
                            bordered
                            size="small"
                            column={{ xs: 1, md: 3 }}
                            items={[
                                {
                                    key: "status",
                                    label: "任务状态",
                                    children: (
                                        <Tag color={stateColors[task.status]}>
                                            {statuses[task.status]}
                                        </Tag>
                                    ),
                                },
                                {
                                    key: "members",
                                    label: "固定人数",
                                    children: task.member_count,
                                },
                                {
                                    key: "hours",
                                    label: "建议观察",
                                    children: `${task.observation_hours} 小时`,
                                },
                            ]}
                        />
                        <Space wrap className="leak-task-actions">
                            <Button
                                aria-label="刷新任务详情"
                                icon={<ReloadOutlined />}
                                onClick={refresh}
                            >
                                刷新
                            </Button>
                            {mutable && (
                                <>
                                    <Button
                                        onClick={() =>
                                            confirm(
                                                task.status === "active"
                                                    ? "pause"
                                                    : "resume",
                                                task.status === "active"
                                                    ? "暂停任务？"
                                                    : "恢复任务？",
                                                "暂停期间专用节点停止下发和授权，已有客户端连接的移除取决于节点端同步。",
                                            )
                                        }
                                    >
                                        {task.status === "active"
                                            ? "暂停任务"
                                            : "恢复任务"}
                                    </Button>
                                    <Button
                                        danger
                                        onClick={() =>
                                            confirm(
                                                "finish",
                                                "结束任务？",
                                                "停止该任务节点的下发和授权，保留所有轮次及名单。",
                                            )
                                        }
                                    >
                                        结束任务
                                    </Button>
                                </>
                            )}
                        </Space>
                        {task.status === "identified" && (
                            <Alert
                                type="warning"
                                showIcon
                                message={`内鬼排查已收敛到第${currentRound?.sequence}轮 ${String(task.final_branch).toUpperCase()} 组的候选账号`}
                                description={(detail.response.candidates ?? [])
                                    .map((member: Row) => member.email)
                                    .join("、")}
                            />
                        )}
                        <div className="leak-round-tree">
                            <strong>轮次记录</strong>
                            <Space wrap>
                                {rounds.map((round) => (
                                    <Button
                                        key={round.id}
                                        type={
                                            round.id === selectedRound?.id
                                                ? "primary"
                                                : "default"
                                        }
                                        onClick={() => {
                                            setRoundId(Number(round.id));
                                            setMemberPage(1);
                                            setBranch(
                                                task.status === "identified" &&
                                                    round.id ===
                                                        task.current_round_id
                                                    ? task.final_branch
                                                    : undefined,
                                            );
                                        }}
                                    >{`第${round.sequence}轮${round.selected_from ? ` ← ${round.selected_from.toUpperCase()}组` : ""}`}</Button>
                                ))}
                            </Space>
                        </div>
                        {selectedRound && (
                            <>
                                <GridRow gutter={[16, 16]}>
                                    {["a", "b"].map((group) => (
                                        <Col xs={24} md={12} key={group}>
                                            <div className="leak-group-card">
                                                <strong>
                                                    {group.toUpperCase()}组 ·{" "}
                                                    {
                                                        selectedRound[
                                                            `${group}_snapshot`
                                                        ]?.name
                                                    }
                                                </strong>
                                                <p>
                                                    {
                                                        selectedRound[
                                                            `${group}_snapshot`
                                                        ]?.host
                                                    }
                                                    :
                                                    {
                                                        selectedRound[
                                                            `${group}_snapshot`
                                                        ]?.port
                                                    }
                                                </p>
                                                <Space size={32}>
                                                    <Statistic
                                                        title="固定人数"
                                                        value={
                                                            selectedRound[
                                                                `${group}_count`
                                                            ]
                                                        }
                                                    />
                                                    <Statistic
                                                        title="已返回节点人数"
                                                        value={
                                                            selectedRound[
                                                                `${group}_seen`
                                                            ]
                                                        }
                                                    />
                                                </Space>
                                                <p>
                                                    人工结果：
                                                    {
                                                        results.find(
                                                            (result) =>
                                                                result.value ===
                                                                selectedRound[
                                                                    `result_${group}`
                                                                ],
                                                        )?.label
                                                    }
                                                </p>
                                                <p>
                                                    {selectedRound[
                                                        `${group}_pull`
                                                    ]
                                                        ? "节点端请求过本轮授权名单"
                                                        : "尚未观察到节点端拉取本轮授权"}
                                                </p>
                                                <Button
                                                    onClick={() => {
                                                        setBranch(group);
                                                        setMemberPage(1);
                                                    }}
                                                >
                                                    查看{group.toUpperCase()}
                                                    组名单
                                                </Button>
                                            </div>
                                        </Col>
                                    ))}
                                </GridRow>
                                <p className="leak-help">
                                    本轮开始：{stamp(selectedRound.created_at)}{" "}
                                    · 建议观察至：
                                    {stamp(
                                        Number(selectedRound.created_at) +
                                            Number(task.observation_hours) *
                                                3600,
                                    )}
                                    。未下发账号保留在名单中，不视为已排除。节点端请求名单不等于已确认客户端连接全部撤销。
                                </p>
                                {mutable &&
                                    selectedRound.id ===
                                        task.current_round_id && (
                                        <Form
                                            form={resultForm}
                                            key={selectedRound.id}
                                            initialValues={{
                                                result_a:
                                                    selectedRound.result_a,
                                                result_b:
                                                    selectedRound.result_b,
                                                note: selectedRound.note,
                                            }}
                                            layout="vertical"
                                            onFinish={saveResults}
                                        >
                                            <GridRow gutter={16}>
                                                <Col xs={24} md={12}>
                                                    <Form.Item
                                                        name="result_a"
                                                        label="A组人工检测结果"
                                                    >
                                                        <Select
                                                            aria-label="A组人工检测结果"
                                                            options={results}
                                                        />
                                                    </Form.Item>
                                                </Col>
                                                <Col xs={24} md={12}>
                                                    <Form.Item
                                                        name="result_b"
                                                        label="B组人工检测结果"
                                                    >
                                                        <Select
                                                            aria-label="B组人工检测结果"
                                                            options={results}
                                                        />
                                                    </Form.Item>
                                                </Col>
                                            </GridRow>
                                            <Form.Item
                                                name="note"
                                                label="检测依据/备注"
                                            >
                                                <Input.TextArea
                                                    rows={3}
                                                    maxLength={2000}
                                                    placeholder="填写检测时间、位置、连接结果等依据"
                                                />
                                            </Form.Item>
                                            <Space wrap>
                                                <Button
                                                    aria-label="保存人工结果"
                                                    htmlType="submit"
                                                    loading={act.busy}
                                                >
                                                    保存人工结果
                                                </Button>
                                                <Button
                                                    aria-label="推进下一轮"
                                                    type="primary"
                                                    disabled={
                                                        task.status !== "active"
                                                    }
                                                    onClick={() => {
                                                        setSelectedBranch("a");
                                                        advanceForm.resetFields();
                                                        setError("");
                                                        setAdvanceOpen(true);
                                                    }}
                                                >
                                                    选择分支并推进
                                                </Button>
                                            </Space>
                                        </Form>
                                    )}
                                {selectedRound.note && (
                                    <p className="leak-help">
                                        已保存依据：{selectedRound.note}
                                    </p>
                                )}
                                <Space wrap className="leak-member-search">
                                    <Select
                                        aria-label="名单分组"
                                        allowClear
                                        placeholder="全部分组"
                                        value={branch}
                                        onChange={(value) => {
                                            setBranch(value);
                                            setMemberPage(1);
                                        }}
                                        options={[
                                            { value: "a", label: "A组" },
                                            { value: "b", label: "B组" },
                                        ]}
                                        style={{ width: 120 }}
                                    />
                                    <Input.Search
                                        aria-label="名单邮箱搜索"
                                        placeholder="按邮箱查询"
                                        allowClear
                                        onSearch={(value) => {
                                            setEmail(value);
                                            setMemberPage(1);
                                        }}
                                    />
                                </Space>
                                <ErrorNotice error={members.error} />
                                <Table<Row>
                                    className="leak-members"
                                    rowKey="id"
                                    size="small"
                                    loading={members.loading}
                                    dataSource={members.data ?? []}
                                    scroll={{ x: 700 }}
                                    pagination={{
                                        current: memberPage,
                                        pageSize: 20,
                                        total: members.response.total ?? 0,
                                        showSizeChanger: false,
                                        onChange: setMemberPage,
                                    }}
                                    columns={[
                                        {
                                            title: "用户邮箱（轮次快照）",
                                            dataIndex: "email",
                                            render: (email) => (
                                                <a
                                                    href="#/subscribe-audit"
                                                    onClick={() =>
                                                        sessionStorage.setItem(
                                                            "subscription-audit-filters",
                                                            JSON.stringify([
                                                                {
                                                                    key: "email",
                                                                    condition:
                                                                        "=",
                                                                    value: email,
                                                                },
                                                            ]),
                                                        )
                                                    }
                                                >
                                                    {email}
                                                </a>
                                            ),
                                        },
                                        {
                                            title: "分组",
                                            dataIndex: "branch",
                                            render: (value) =>
                                                value.toUpperCase(),
                                        },
                                        {
                                            title: "首次返回",
                                            dataIndex: "first_seen_at",
                                            render: stamp,
                                        },
                                        {
                                            title: "最近返回",
                                            dataIndex: "last_seen_at",
                                            render: stamp,
                                        },
                                        {
                                            title: "来源",
                                            dataIndex: "last_source",
                                            render: (value) =>
                                                ({
                                                    subscribe: "订阅",
                                                    app: "App配置",
                                                    user_api: "用户节点列表",
                                                })[value as string] ?? "-",
                                        },
                                    ]}
                                />
                            </>
                        )}
                        <div className="leak-history">
                            <strong>操作记录</strong>
                            <Timeline
                                items={(detail.response.events ?? []).map(
                                    (event: Row) => ({
                                        children: (
                                            <span>
                                                {stamp(event.created_at)} ·{" "}
                                                {event.actor_email} ·{" "}
                                                {events[event.action] ??
                                                    event.action}
                                                {event.note
                                                    ? ` · ${event.note}`
                                                    : ""}
                                            </span>
                                        ),
                                    }),
                                )}
                            />
                        </div>
                    </>
                )}
            </Drawer>
            <Modal
                title="选择分支并推进"
                width={560}
                open={advanceOpen}
                onCancel={() => setAdvanceOpen(false)}
                onOk={() => advanceForm.submit()}
                confirmLoading={saving}
                okText="确认推进"
                cancelText="取消"
                okButtonProps={{ "aria-label": "确认推进" }}
                destroyOnHidden
            >
                <ErrorNotice error={error} />
                <Form
                    form={advanceForm}
                    layout="vertical"
                    onFinish={startNext}
                    requiredMark={false}
                >
                    <Form.Item label="继续排查的分支">
                        <Select
                            aria-label="继续排查的分支"
                            value={selectedBranch}
                            onChange={setSelectedBranch}
                            options={[
                                { value: "a", label: "A组" },
                                { value: "b", label: "B组" },
                            ]}
                        />
                    </Form.Item>
                    <p>
                        选定组必须已经人工标记为异常，另一组为正常。未确认或两边均异常时请暂停核查。
                    </p>
                    <p>
                        可以复用本轮节点，也可以选择其他空闲节点。历史分组和结果会保留。
                    </p>
                    {Number(currentRound?.[`${selectedBranch}_count`]) > 1 ? (
                        <>
                            <Form.Item
                                name="a_node_id"
                                label="下一轮A组节点"
                                rules={[{ required: true }]}
                            >
                                <Select
                                    aria-label="下一轮A组节点"
                                    options={advanceNodeOptions}
                                />
                            </Form.Item>
                            <Form.Item
                                name="b_node_id"
                                label="下一轮B组节点"
                                rules={[{ required: true }]}
                            >
                                <Select
                                    aria-label="下一轮B组节点"
                                    options={advanceNodeOptions}
                                />
                            </Form.Item>
                        </>
                    ) : (
                        <Alert
                            type="info"
                            message="该组只有一个候选账号，确认后结束本任务并保留候选及证据，不自动封禁。"
                        />
                    )}
                    <Form.Item name="note" label="推进说明">
                        <Input.TextArea rows={2} maxLength={2000} />
                    </Form.Item>
                </Form>
            </Modal>
        </Page>
    );
}
