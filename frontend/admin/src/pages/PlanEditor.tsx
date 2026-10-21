import { useEffect, useState } from "react";
import {
    Button,
    Checkbox,
    Divider,
    Drawer,
    Form,
    Input,
    Modal,
    Space,
    Tooltip,
} from "antd";
import { InfoCircleOutlined } from "@ant-design/icons";
import { api, ApiError } from "../api";
import {
    displayValues,
    ErrorNotice,
    Fields,
    payloadValues,
    useResource,
} from "../components";
import { planFields } from "../schema/resources";
import type { Field, Row } from "../types";

const field = (key: string, extra: Partial<Field> = {}): Field => ({
    ...planFields.find((item) => item.key === key)!,
    span: 12,
    ...extra,
});
const intro = [
    field("name", {
        label: "套餐名称",
        ariaLabel: "订阅名称",
        placeholder: "请输入套餐名称",
    }),
    field("content", {
        label: "套餐描述",
        ariaLabel: "订阅介绍",
        kind: "textarea",
        rows: 4,
        placeholder: "请输入套餐描述，支持HTML",
    }),
];
const recurringPrices = [
    "month_price",
    "quarter_price",
    "half_year_price",
    "year_price",
    "two_year_price",
    "three_year_price",
].map((key, index) =>
    field(key, {
        label: ["月付", "季付", "半年", "年付", "两年付", "三年付"][index],
        ariaLabel: planFields.find((f) => f.key === key)!.label,
        addonBefore: null,
        help: undefined,
        span: 2,
    }),
);
const otherPrices = [
    field("onetime_price", {
        label: "一次性",
        ariaLabel: "一次性价格",
        addonBefore: null,
        addonAfter: "¥",
        help: undefined,
        span: 6,
    }),
    field("reset_price", {
        label: "重置包",
        ariaLabel: "重置流量价格",
        addonBefore: null,
        addonAfter: "¥",
        help: undefined,
        span: 6,
    }),
];

export default function PlanEditor({
    initial,
    references,
    save,
    close,
}: {
    initial: Row;
    references: Row;
    save: (values: Row) => Promise<void>;
    close: () => void;
}) {
    const [form] = Form.useForm();
    const config = useResource("/config/fetch");
    const currency = config.data?.site?.currency_symbol ?? "¥";
    const values = Form.useWatch([], { form, preserve: true }) ?? {};
    const [busy, setBusy] = useState(false),
        [error, setError] = useState("");
    const [addingGroup, setAddingGroup] = useState(false),
        [groupName, setGroupName] = useState(""),
        [groupBusy, setGroupBusy] = useState(false),
        [groupError, setGroupError] = useState("");
    const [addedGroups, setAddedGroups] = useState<Row[]>([]);
    const groups: Row[] = [
        ...(references.groups ?? []),
        ...addedGroups.filter(
            (group) =>
                !(references.groups ?? []).some(
                    (existing: Row) => existing.id === group.id,
                ),
        ),
    ];
    const details = [
        field("transfer_enable", {
            label: "套餐流量",
            ariaLabel: "每期流量 GB",
            addonAfter: "GB",
            placeholder: "请输入套餐流量",
        }),
        field("device_limit", {
            label: "设备数限制",
            ariaLabel: "设备限制",
            placeholder: "留空则不限制",
        }),
        field("group_id", {
            placeholder: "请选择权限组",
            labelAction: {
                label: "添加权限组",
                onClick: () => {
                    setGroupError("");
                    setAddingGroup(true);
                },
            },
        }),
        field("reset_traffic_method", { placeholder: "请选择流量重置方式" }),
        field("capacity_limit", {
            label: "最大容纳用户量",
            ariaLabel: "人数限制",
            placeholder: "留空则不限制",
        }),
        field("speed_limit", {
            label: "限速",
            ariaLabel: "速度限制 Mbps",
            addonAfter: "Mbps",
            placeholder: "留空则不限制",
        }),
    ];
    const editableFields = [
        ...intro,
        ...recurringPrices,
        ...otherPrices,
        ...details,
        ...(initial.id ? [field("force_update")] : []),
    ];
    useEffect(
        () => form.setFieldsValue(displayValues(editableFields, initial)),
        [],
    );
    const submit = async (data: Row) => {
        setBusy(true);
        setError("");
        try {
            const payload = payloadValues(editableFields, data);
            for (const key of [
                "device_limit",
                "speed_limit",
                "capacity_limit",
                "reset_traffic_method",
                ...recurringPrices.map((field) => field.key),
                ...otherPrices.map((field) => field.key),
            ]) {
                if (payload[key] === undefined) payload[key] = null;
            }
            await save(payload);
            close();
        } catch (error) {
            setError((error as Error).message);
            if (error instanceof ApiError)
                form.setFields(
                    Object.entries(error.fields).map(([name, errors]) => ({
                        name,
                        errors: errors as string[],
                    })),
                );
        } finally {
            setBusy(false);
        }
    };
    return (
        <>
            <Drawer
                open
                title={initial.id ? "编辑订阅" : "新建订阅"}
                width="80%"
                styles={{ wrapper: { maxWidth: 500 } }}
                className="original-editor plan-editor"
                onClose={close}
                destroyOnHidden
                footer={
                    <div className="plan-editor-footer">
                        {initial.id ? (
                            <Tooltip title="将变更的流量、限速、设备数和权限组同步到该套餐下的用户">
                                <Checkbox
                                    aria-label="强制更新到用户"
                                    checked={!!values.force_update}
                                    onChange={(event) =>
                                        form.setFieldValue(
                                            "force_update",
                                            event.target.checked ? 1 : 0,
                                        )
                                    }
                                >
                                    强制更新到用户
                                </Checkbox>
                            </Tooltip>
                        ) : (
                            <span />
                        )}
                        <Space size={8}>
                            <Button onClick={close}>取消</Button>
                            <Button
                                type="primary"
                                aria-label="保存"
                                loading={busy}
                                onClick={() => form.submit()}
                            >
                                提交
                            </Button>
                        </Space>
                    </div>
                }
            >
                <ErrorNotice error={error} />
                <Form
                    form={form}
                    layout="vertical"
                    requiredMark={false}
                    onFinish={submit}
                >
                    <Fields fields={intro} />
                    <Divider>
                        售价设置{" "}
                        <Tooltip title="将金额留空则不会进行出售，0 表示免费">
                            <InfoCircleOutlined />
                        </Tooltip>
                    </Divider>
                    <div className="plan-prices">
                        <Fields fields={recurringPrices} />
                        <Fields
                            fields={otherPrices.map((field) => ({
                                ...field,
                                addonAfter: currency,
                            }))}
                        />
                    </div>
                    <Divider />
                    <Fields
                        fields={details}
                        references={{ ...references, groups }}
                    />
                    {initial.id && (
                        <Form.Item hidden name="force_update">
                            <Input />
                        </Form.Item>
                    )}
                </Form>
            </Drawer>
            <Modal
                open={addingGroup}
                title="添加权限组"
                okText="添加"
                cancelText="取消"
                confirmLoading={groupBusy}
                onCancel={() => setAddingGroup(false)}
                onOk={async () => {
                    if (!groupName.trim()) {
                        setGroupError("请输入权限组名称");
                        return;
                    }
                    setGroupBusy(true);
                    setGroupError("");
                    try {
                        await api(
                            "/server/group/save",
                            { name: groupName.trim() },
                            "POST",
                        );
                        const list: Row[] = (await api("/server/group/fetch"))
                            .data;
                        setAddedGroups(list);
                        const created = [...list]
                            .sort((a, b) => b.id - a.id)
                            .find((item) => item.name === groupName.trim());
                        if (created) form.setFieldValue("group_id", created.id);
                        references.reloadGroups?.();
                        setAddingGroup(false);
                        setGroupName("");
                    } catch (error) {
                        setGroupError((error as Error).message);
                    } finally {
                        setGroupBusy(false);
                    }
                }}
            >
                <ErrorNotice error={groupError} />
                <Input
                    aria-label="权限组名称"
                    placeholder="请输入权限组名称"
                    value={groupName}
                    onChange={(event) => setGroupName(event.target.value)}
                />
            </Modal>
        </>
    );
}
