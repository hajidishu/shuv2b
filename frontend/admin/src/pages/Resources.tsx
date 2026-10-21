import { Dropdown } from "antd";
import { DownOutlined } from "@ant-design/icons";
import { useState } from "react";
import {
    DeleteOutlined,
    EditOutlined,
    MenuOutlined,
    PlusOutlined,
} from "@ant-design/icons";
import { Button, Space, Switch, Table, Tag } from "antd";
import { api, copyText, downloadApi } from "../api";
import {
    DataCard,
    Editor,
    formatDate,
    formatMoney,
    Page,
    useAction,
    useReferences,
    useResource,
} from "../components";
import { labels, resourceSchemas } from "../schema/resources";
import type { Row } from "../types";
import PlanEditor from "./PlanEditor";
import CardEditor from "./CardEditor";

const routeActions: Record<string, string> = {
    block: "禁止访问(域名目标)",
    block_ip: "禁止访问(IP目标)",
    block_port: "禁止访问(端口目标)",
    protocol: "禁止访问(协议)",
    dns: "指定DNS服务器进行解析",
    route: "指定出站服务器(域名目标)",
    route_ip: "指定出站服务器(IP目标)",
    default_out: "自定义默认出站",
};

export default function Resources({ path }: { path: string }) {
    const schema = resourceSchemas[path];
    const [page, setPage] = useState(1);
    const [size, setSize] = useState(20);
    const [editing, setEditing] = useState<Row>();
    const refs = useReferences();
    const act = useAction();
    const resource = useResource(schema.base + "/fetch", {
        current: page,
        pageSize: size,
    });
    const rows: Row[] = Array.isArray(resource.data) ? resource.data : [];
    const editorFields = (values: Row) =>
        schema.fields
            .filter((field) => !field.when || field.when(values))
            .map((field) => {
                let extra: Partial<typeof field> = {};
                if (field.key === "value") {
                    const type = Number(values.type ?? 1);
                    const money = type === 1;
                    const unit =
                        path === "/coupon"
                            ? money
                                ? "¥"
                                : "%"
                            : money
                              ? "¥"
                              : type === 2 || type === 5
                                ? "天"
                                : type === 3
                                  ? "GB"
                                  : undefined;
                    extra = {
                        kind: money ? "money" : "number",
                        ariaLabel: "数值",
                        addonBefore: money ? null : field.addonBefore,
                        addonAfter: unit,
                        placeholder:
                            path === "/giftcard" && type === 5
                                ? "一次性套餐填写 0"
                                : field.placeholder,
                        label:
                            path === "/coupon"
                                ? money
                                    ? "优惠金额"
                                    : "优惠比例"
                                : path === "/giftcard"
                                  ? money
                                      ? "金额"
                                      : type === 2
                                        ? "时长"
                                        : type === 3
                                          ? "流量"
                                          : type === 5
                                            ? "套餐时长"
                                            : field.label
                                  : field.label,
                    };
                }
                if (field.key === "action_value") {
                    const dns = values.action === "dns";
                    extra = {
                        ...extra,
                        kind: dns ? "text" : "textarea",
                        label: dns ? "DNS服务器" : "Xray出站配置",
                        help: dns
                            ? "填写用于解析的 DNS 服务器地址。"
                            : "填写 Xray outbound 配置 JSON。",
                        placeholder: dns
                            ? "请输入用于解析的 DNS 服务器地址"
                            : JSON.stringify(
                                  {
                                      tag: "ss_out",
                                      sendThrough: "0.0.0.0",
                                      protocol: "shadowsocks",
                                      settings: {
                                          email: "love@xray.com",
                                          address: "8.8.8.8",
                                          port: 5555,
                                          method: "chacha20-ietf-poly1305",
                                          password:
                                              "abcdefghijklmnopqrstuvwxyz",
                                          level: 0,
                                      },
                                  },
                                  null,
                                  2,
                              ),
                        helpLink: dns
                            ? undefined
                            : {
                                  label: "Xray 出站配置参考",
                                  href: "https://xtls.github.io/config/outbound.html",
                              },
                    };
                }
                if (field.key === "match") {
                    const action = String(values.action ?? "block");
                    extra = {
                        ...extra,
                        kind: "lines",
                        placeholder:
                            action === "protocol"
                                ? "http\ntls\nquic\nbittorrent"
                                : action === "block_port"
                                  ? "53\n443\n1000-2000"
                                  : ["route_ip", "block_ip"].includes(action)
                                    ? "127.0.0.1(单一匹配)\n10.0.0.0/8(范围匹配)\ngeoip:cn(预定义列表匹配)"
                                    : "example.com(关键字匹配)\ndomain:example.com(子域名匹配)\ngeosite:netflix(预定义域名列表)",
                        helpLink: {
                            label: "填写参考",
                            href: "https://xtls.github.io/config/routing.html#ruleobject",
                        },
                    };
                }
                return {
                    ...field,
                    ...extra,
                    span:
                        field.kind === "date" || field.key.endsWith("_price")
                            ? 6
                            : 12,
                };
            });
    const open = async (row: Row) => {
        if (schema.detail) {
            const res = await api(schema.base + "/fetch", { id: row.id });
            setEditing(res.data);
        } else setEditing(row);
    };
    const save = async (values: Row) => {
        if (
            path === "/server/route" &&
            ["route", "route_ip", "default_out"].includes(String(values.action))
        ) {
            try {
                const parsed = JSON.parse(String(values.action_value ?? ""));
                if (
                    typeof parsed !== "object" ||
                    parsed === null ||
                    Array.isArray(parsed)
                )
                    throw new Error();
            } catch {
                throw new Error("Xray 出站配置必须是有效的 JSON 对象");
            }
        }
        const payload: Row = {
            ...values,
            ...(editing?.id ? { id: editing.id } : {}),
        };
        if (payload.limit_plan_ids)
            payload.limit_plan_ids = payload.limit_plan_ids.map(Number);
        if (path === "/plan" && payload.force_update) {
            payload.device_limit ??= null;
            payload.speed_limit ??= null;
        }
        if (path === "/giftcard" && Number(payload.type) === 4)
            payload.value = 0;
        if (values.generate_count) {
            await downloadApi(
                schema.save,
                payload,
                schema.base.slice(1) + "-generated.csv",
            );
        } else await api(schema.save, payload, "POST");
        act.message.success("保存成功");
        resource.reload();
    };
    const toggle = async (row: Row, key: string) => {
        await api(
            schema.base + "/" + (schema.base === "/plan" ? "update" : "show"),
            { id: row.id, [key]: row[key] ? 0 : 1 },
            "POST",
        );
        resource.reload();
    };
    const sort = async (row: Row, delta: number) => {
        const index = rows.findIndex((r) => r.id === row.id);
        const sorted = [...rows],
            j = index + delta;
        if (j < 0 || j >= sorted.length) return;
        [sorted[index], sorted[j]] = [sorted[j], sorted[index]];
        await api(
            schema.base + "/sort",
            { [schema.sort!]: sorted.map((x) => x.id) },
            "POST",
        );
        resource.reload();
    };
    return (
        <Page title={schema.title}>
            <DataCard
                error={resource.error || refs.error}
                loading={resource.loading}
            >
                <div className="table-toolbar">
                    <Space>
                        <Button
                            type="default"
                            icon={<PlusOutlined />}
                            aria-label={schema.add}
                            onClick={() => setEditing({})}
                        >
                            {schema.add}
                        </Button>
                    </Space>
                </div>
                <Table<Row>
                    rowKey="id"
                    scroll={{ x: schema.base === "/plan" ? 1400 : 1000 }}
                    dataSource={rows}
                    pagination={{
                        current: page,
                        pageSize: size,
                        total: resource.response.total ?? rows.length,
                        onChange: (p, s) => {
                            setPage(p);
                            setSize(s);
                        },
                        showSizeChanger: true,
                    }}
                    columns={[
                        ...schema.columns.map((key) => ({
                            title:
                                schema.base === "/plan" && key === "show"
                                    ? "销售状态"
                                    : (labels[key] ?? key),
                            dataIndex: key,
                            width:
                                key === "name" || key === "title"
                                    ? 250
                                    : key.endsWith("_price")
                                      ? 80
                                      : schema.base === "/plan"
                                        ? ({
                                              show: 70,
                                              renew: 70,
                                              count: 60,
                                              transfer_enable: 80,
                                              device_limit: 80,
                                          }[key] ?? 80)
                                        : undefined,
                            render: (v: any, r: Row) => {
                                if (["show", "renew"].includes(key))
                                    return (
                                        <Switch
                                            size="small"
                                            checked={!!v}
                                            onChange={() =>
                                                act
                                                    .run(() => toggle(r, key))
                                                    .catch(() => {})
                                            }
                                        />
                                    );
                                if (key.endsWith("_price"))
                                    return (
                                        <span style={{ whiteSpace: "nowrap" }}>
                                            {v == null
                                                ? "—"
                                                : (Number(v) / 100).toFixed(2)}
                                        </span>
                                    );
                                if (key.endsWith("_at")) return formatDate(v);
                                if (
                                    schema.base === "/plan" &&
                                    key === "transfer_enable"
                                )
                                    return (
                                        <span style={{ whiteSpace: "nowrap" }}>
                                            {v ?? 0} GB
                                        </span>
                                    );
                                if (key === "type")
                                    return (
                                        schema.base === "/giftcard"
                                            ? [
                                                  "",
                                                  "金额",
                                                  "时长",
                                                  "流量",
                                                  "重置",
                                                  "套餐",
                                              ]
                                            : ["", "金额折扣", "百分比折扣"]
                                    )[v];
                                if (key === "group_name")
                                    return (
                                        refs.groups.find(
                                            (group: Row) =>
                                                Number(group.id) ===
                                                Number(r.group_id),
                                        )?.name ?? "—"
                                    );
                                if (key === "match_count") {
                                    const count = Array.isArray(r.match)
                                        ? r.match.length
                                        : String(r.match ?? "")
                                              .split(",")
                                              .filter(Boolean).length;
                                    return count
                                        ? `匹配 ${count} 条规则`
                                        : "无规则时默认";
                                }
                                if (key === "action")
                                    return routeActions[String(v)] ?? v ?? "—";
                                if (key === "code" && v) {
                                    return (
                                        <a
                                            onClick={() =>
                                                act
                                                    .run(
                                                        () =>
                                                            copyText(String(v)),
                                                        "复制成功",
                                                    )
                                                    .catch(() => {})
                                            }
                                        >
                                            {v}
                                        </a>
                                    );
                                }
                                if (key === "value") {
                                    if (schema.base === "/coupon")
                                        return v == null
                                            ? "—"
                                            : Number(r.type) === 1
                                              ? `¥${(Number(v) / 100).toFixed(2)}`
                                              : `${v}%`;
                                    if (schema.base === "/giftcard") {
                                        const type = Number(r.type);
                                        if (type === 1)
                                            return `¥${(Number(v) / 100).toFixed(2)}`;
                                        if (type === 2 || type === 5)
                                            return `${v} 天`;
                                        if (type === 3) return `${v} GB`;
                                        return "—";
                                    }
                                }
                                if (key === "plan_id") {
                                    return (
                                        refs.plans.find(
                                            (plan: Row) =>
                                                Number(plan.id) === Number(v),
                                        )?.name ?? "—"
                                    );
                                }
                                if (Array.isArray(v))
                                    return v.map((x) => (
                                        <Tag key={String(x)}>{String(x)}</Tag>
                                    ));
                                return v ?? "—";
                            },
                        })),
                        {
                            title: "操作",
                            fixed: "right",
                            width: 90,
                            render: (_, r) => {
                                const remove = () =>
                                    act.modal.confirm({
                                        title:
                                            "删除 " +
                                            (r.name ?? r.title ?? r.remarks) +
                                            "？",
                                        okButtonProps: { danger: true },
                                        onOk: async () => {
                                            await api(
                                                schema.base + "/drop",
                                                { id: r.id },
                                                "POST",
                                            );
                                            resource.reload();
                                        },
                                    });
                                const edit = () =>
                                    act.run(() => open(r), "").catch(() => {});
                                const legacyInline = [
                                    "/server/group",
                                    "/server/route",
                                    "/notice",
                                    "/coupon",
                                    "/giftcard",
                                ].includes(path);
                                const sortingItems = schema.sort
                                    ? [
                                          { key: "up", label: "上移" },
                                          { key: "down", label: "下移" },
                                      ]
                                    : [];
                                const sortingMenu = sortingItems.length > 0;
                                if (legacyInline)
                                    return (
                                        <Space size={6}>
                                            <a onClick={edit}>编辑</a>
                                            <span aria-hidden="true">|</span>
                                            <a onClick={remove}>删除</a>
                                            {sortingMenu && (
                                                <Dropdown
                                                    menu={{
                                                        items: sortingItems,
                                                        onClick: ({ key }) =>
                                                            act
                                                                .run(() =>
                                                                    sort(
                                                                        r,
                                                                        key ===
                                                                            "up"
                                                                            ? -1
                                                                            : 1,
                                                                    ),
                                                                )
                                                                .catch(
                                                                    () => {},
                                                                ),
                                                    }}
                                                >
                                                    <a aria-label="更多操作">
                                                        <MenuOutlined />
                                                    </a>
                                                </Dropdown>
                                            )}
                                        </Space>
                                    );
                                return (
                                    <Dropdown
                                        menu={{
                                            items: [
                                                {
                                                    key: "edit",
                                                    icon: <EditOutlined />,
                                                    label: "编辑",
                                                },
                                                {
                                                    key: "delete",
                                                    icon: <DeleteOutlined />,
                                                    label: "删除",
                                                    danger: true,
                                                },
                                                ...sortingItems,
                                            ],
                                            onClick: ({ key }) => {
                                                if (key === "edit") edit();
                                                else if (key === "delete")
                                                    remove();
                                                else
                                                    act.run(() =>
                                                        sort(
                                                            r,
                                                            key === "up"
                                                                ? -1
                                                                : 1,
                                                        ),
                                                    ).catch(() => {});
                                            },
                                        }}
                                    >
                                        <a className="row-menu-link">
                                            操作 <DownOutlined />
                                        </a>
                                    </Dropdown>
                                );
                            },
                        },
                    ]}
                />
            </DataCard>
            {editing && path === "/plan" ? (
                <PlanEditor
                    key={editing.id ?? "new"}
                    initial={editing}
                    references={refs}
                    save={save}
                    close={() => setEditing(undefined)}
                />
            ) : path === "/coupon" || path === "/giftcard" ? (
                editing && (
                    <CardEditor
                        key={editing.id ?? "new"}
                        gift={path === "/giftcard"}
                        record={editing}
                        plans={refs.plans}
                        save={save}
                        close={() => setEditing(undefined)}
                    />
                )
            ) : (
                editing && (
                    <Editor
                        key={editing.id ?? "new"}
                        presentation={
                            ["/plan", "/knowledge"].includes(path)
                                ? "drawer"
                                : "modal"
                        }
                        width={
                            path === "/knowledge"
                                ? "80%"
                                : path === "/plan"
                                  ? 500
                                  : 520
                        }
                        title={
                            path === "/knowledge"
                                ? editing.id
                                    ? "编辑知识"
                                    : "新增知识"
                                : path === "/notice"
                                  ? editing.id
                                      ? "编辑公告"
                                      : "新建公告"
                                  : path === "/server/route"
                                    ? editing.id
                                        ? "编辑路由"
                                        : "创建路由"
                                    : path === "/server/group"
                                      ? editing.id
                                          ? "编辑权限组"
                                          : "新建权限组"
                                      : editing.id
                                        ? "编辑" +
                                          schema.title.replace(/管理$/, "")
                                        : schema.add
                        }
                        className={
                            path === "/knowledge"
                                ? "knowledge-editor"
                                : undefined
                        }
                        submitLabel="提交"
                        fields={editorFields}
                        initial={editing}
                        references={refs}
                        save={save}
                        close={() => setEditing(undefined)}
                    />
                )
            )}
        </Page>
    );
}
