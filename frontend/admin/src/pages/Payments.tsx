import PaymentEditor from "./PaymentEditor";
import { useState } from "react";
import {
    DownOutlined,
    PlusOutlined,
    QuestionCircleOutlined,
    MenuOutlined,
} from "@ant-design/icons";
import { Button, Divider, Dropdown, Space, Switch, Table, Tooltip } from "antd";
import { api } from "../api";
import { DataCard, Page, useAction, useResource } from "../components";
import type { Row } from "../types";
export default function Payments() {
    const resource = useResource("/payment/fetch");

    const act = useAction();
    const [editing, setEditing] = useState<Row>();
    const [dragging, setDragging] = useState<number>();
    const remove = (row: Row) =>
        act.modal.confirm({
            title: "删除支付方式 " + row.name + "？",
            okButtonProps: { danger: true },
            onOk: () =>
                api("/payment/drop", { id: row.id }, "POST").then(
                    resource.reload,
                ),
        });

    const open = (row: Row) => setEditing(row);
    const sort = async (i: number, delta: number) => {
        const rows = [...resource.data],
            j = i + delta;
        if (j < 0 || j >= rows.length) return;
        [rows[i], rows[j]] = [rows[j], rows[i]];
        await api("/payment/sort", { ids: rows.map((r: Row) => r.id) }, "POST");
        resource.reload();
    };
    return (
        <Page title="支付配置">
            <DataCard error={resource.error} loading={resource.loading}>
                <div className="table-toolbar">
                    <Space>
                        <Button
                            type="default"
                            icon={<PlusOutlined />}
                            aria-label="添加支付方式"
                            onClick={() => open({})}
                        >
                            添加支付方式
                        </Button>
                    </Space>
                </div>
                <Table<Row>
                    rowKey="id"
                    scroll={{ x: 800 }}
                    dataSource={resource.data ?? []}
                    pagination={false}
                    onRow={(row) => ({
                        onDragOver: (event) => event.preventDefault(),
                        onDrop: (event) => {
                            event.preventDefault();
                            if (dragging == null || dragging === row.id) return;
                            const rows = [...resource.data],
                                from = rows.findIndex(
                                    (item) => item.id === dragging,
                                ),
                                to = rows.findIndex(
                                    (item) => item.id === row.id,
                                );
                            if (from < 0 || to < 0) return;
                            rows.splice(to, 0, rows.splice(from, 1)[0]);
                            act.run(() =>
                                api(
                                    "/payment/sort",
                                    { ids: rows.map((item) => item.id) },
                                    "POST",
                                ).then(resource.reload),
                            ).catch(() => {});
                            setDragging(undefined);
                        },
                    })}
                    columns={[
                        {
                            title: "ID",
                            dataIndex: "id",
                            width: 90,
                            render: (value, row) => (
                                <span
                                    draggable
                                    onDragStart={() => setDragging(row.id)}
                                    onDragEnd={() => setDragging(undefined)}
                                    title="拖动排序"
                                >
                                    <MenuOutlined
                                        style={{
                                            cursor: "move",
                                            marginRight: 8,
                                        }}
                                    />
                                    {value}
                                </span>
                            ),
                        },
                        {
                            title: "启用",
                            dataIndex: "enable",
                            width: 80,
                            render: (v, r) => (
                                <Switch
                                    size="small"
                                    checked={!!v}
                                    onChange={() =>
                                        act
                                            .run(() =>
                                                api(
                                                    "/payment/show",
                                                    { id: r.id },
                                                    "POST",
                                                ).then(resource.reload),
                                            )
                                            .catch(() => {})
                                    }
                                />
                            ),
                        },
                        { title: "显示名称", dataIndex: "name" },
                        { title: "支付接口", dataIndex: "payment" },
                        {
                            title: (
                                <span>
                                    通知地址{" "}
                                    <Tooltip title="支付网关将会把数据通知到本地址，请通过防火墙放行本地址。">
                                        <QuestionCircleOutlined />
                                    </Tooltip>
                                </span>
                            ),
                            dataIndex: "notify_url",
                            render: (v) => (
                                <span className="break-word">{v}</span>
                            ),
                        },
                        {
                            title: "操作",
                            width: 170,
                            align: "right",
                            render: (_, r, i) => (
                                <span className="inline-row-actions">
                                    <a onClick={() => open(r)}>编辑</a>
                                    <Divider type="vertical" />
                                    <a onClick={() => remove(r)}>删除</a>
                                    <Divider type="vertical" />
                                    <Dropdown
                                        menu={{
                                            items: [
                                                {
                                                    key: "up",
                                                    label: "上移",
                                                    disabled: i === 0,
                                                },
                                                {
                                                    key: "down",
                                                    label: "下移",
                                                    disabled:
                                                        i ===
                                                        resource.data.length -
                                                            1,
                                                },
                                            ],
                                            onClick: ({ key }) => {
                                                if (key === "edit") open(r);
                                                else if (
                                                    key === "up" ||
                                                    key === "down"
                                                )
                                                    act.run(() =>
                                                        sort(
                                                            i,
                                                            key === "up"
                                                                ? -1
                                                                : 1,
                                                        ),
                                                    ).catch(() => {});
                                                else
                                                    act.modal.confirm({
                                                        title:
                                                            "删除支付方式 " +
                                                            r.name +
                                                            "？",
                                                        okButtonProps: {
                                                            danger: true,
                                                        },
                                                        onOk: () =>
                                                            api(
                                                                "/payment/drop",
                                                                { id: r.id },
                                                                "POST",
                                                            ).then(
                                                                resource.reload,
                                                            ),
                                                    });
                                            },
                                        }}
                                    >
                                        <a className="row-menu-link">
                                            排序 <DownOutlined />
                                        </a>
                                    </Dropdown>
                                </span>
                            ),
                        },
                    ]}
                />
            </DataCard>
            {editing && (
                <PaymentEditor
                    key={editing.id ?? "new"}
                    record={editing}
                    close={() => setEditing(undefined)}
                    reload={resource.reload}
                />
            )}
        </Page>
    );
}
