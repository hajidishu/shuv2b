import { useEffect, useState } from "react";
import dayjs, { type Dayjs } from "dayjs";
import {
    Alert,
    Button,
    Col,
    DatePicker,
    Drawer,
    Form,
    Input,
    InputNumber,
    Row,
    Select,
    Space,
    Switch,
    Tooltip,
} from "antd";
import { QuestionCircleOutlined } from "@ant-design/icons";
import { ApiError } from "../api";
import { cents, yuan } from "../domain.mjs";
import type { Row as UserRow } from "../types";
import { userEditFields, commissionTypeOptions } from "../schema/user-editor";

const GIB = 1024 ** 3;

function initialValues(user: UserRow) {
    return {
        ...user,
        password: undefined,
        plan_id: user.plan_id ?? 0,
        balance: yuan(user.balance),
        commission_balance: yuan(user.commission_balance),
        u: user.u == null ? null : Number(user.u) / GIB,
        d: user.d == null ? null : Number(user.d) / GIB,
        transfer_enable:
            user.transfer_enable == null
                ? null
                : Number(user.transfer_enable) / GIB,
        expired_at:
            user.expired_at == null
                ? null
                : dayjs(Number(user.expired_at) * 1000),
        banned: user.banned ? 1 : 0,
        commission_type: Number(user.commission_type ?? 0),
        is_admin: !!Number(user.is_admin),
        is_staff: !!Number(user.is_staff),
    };
}

function numberField(key: string) {
    return userEditFields.find((field) => field.key === key)!;
}

export default function UserEditor({
    user,
    plans,
    save,
    close,
}: {
    user: UserRow;
    plans: UserRow[];
    save: (values: UserRow) => Promise<void>;
    close: () => void;
}) {
    const [form] = Form.useForm();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    useEffect(() => {
        form.setFieldsValue(initialValues(user));
    }, [form, user]);

    const submit = async (values: UserRow) => {
        setBusy(true);
        setError("");
        try {
            const payload: UserRow = { ...values, id: user.id };
            if (!values.password) delete payload.password;
            payload.balance = cents(values.balance);
            payload.commission_balance = cents(values.commission_balance);
            for (const key of ["u", "d", "transfer_enable"]) {
                const value = values[key];
                if (value == null || value === "") payload[key] = null;
                else {
                    const bytes = Math.round(Number(value) * GIB);
                    if (!Number.isSafeInteger(bytes))
                        throw new Error("流量数值超出范围");
                    payload[key] = bytes;
                }
            }
            const expiredAt = values.expired_at as Dayjs | null;
            payload.expired_at = expiredAt
                ? Math.floor(expiredAt.valueOf() / 1000)
                : null;
            payload.banned = Number(values.banned) ? 1 : 0;
            payload.commission_type = Number(values.commission_type ?? 0);
            payload.is_admin = values.is_admin ? 1 : 0;
            payload.is_staff = values.is_staff ? 1 : 0;
            payload.plan_id =
                Number(values.plan_id) > 0 ? Number(values.plan_id) : null;
            for (const key of [
                "device_limit",
                "speed_limit",
                "commission_rate",
                "discount",
            ]) {
                const value = values[key];
                payload[key] =
                    value == null || value === "" ? null : Number(value);
                if (payload[key] != null && !Number.isSafeInteger(payload[key]))
                    throw new Error("请输入整数数值");
            }
            await save(payload);
            close();
        } catch (e) {
            setError((e as Error).message);
            if (e instanceof ApiError)
                form.setFields(
                    Object.entries(e.fields).map(([name, messages]) => ({
                        name,
                        errors: messages as string[],
                    })),
                );
        } finally {
            setBusy(false);
        }
    };

    const item = (key: string, control: React.ReactNode) => {
        const field = numberField(key);
        return (
            <Form.Item
                key={key}
                name={key}
                valuePropName={
                    key === "is_admin" || key === "is_staff"
                        ? "checked"
                        : "value"
                }
                label={
                    key === "discount" ? (
                        <>
                            专享折扣比例{" "}
                            <Tooltip title="设置后该用户购买任何订阅将始终享受该折扣">
                                <QuestionCircleOutlined />
                            </Tooltip>
                        </>
                    ) : (
                        field.label
                    )
                }
                rules={
                    field.required
                        ? [{ required: true, message: `请填写${field.label}` }]
                        : undefined
                }
            >
                {control}
            </Form.Item>
        );
    };

    const text = (key: string, password = false) => {
        const field = numberField(key);
        const props = {
            placeholder: field.placeholder,
            "aria-label": field.ariaLabel ?? field.label,
        };
        return password ? (
            <Input.Password {...props} autoComplete="new-password" />
        ) : (
            <Input {...props} />
        );
    };
    const numeric = (key: string, addonAfter?: string) => {
        const field = numberField(key);
        const min =
            field.kind === "money"
                ? undefined
                : (field.min ?? (field.kind === "gb" ? 0 : undefined));
        return (
            <InputNumber
                aria-label={field.ariaLabel ?? field.label}
                placeholder={field.placeholder}
                addonAfter={addonAfter}
                min={min}
                stringMode={field.kind === "money"}
                style={{ width: "100%" }}
            />
        );
    };

    return (
        <Drawer
            open
            className="user-editor"
            title="用户管理"
            aria-label="用户管理"
            width="80%"
            styles={{ wrapper: { maxWidth: 500 } }}
            onClose={close}
            destroyOnHidden
            footer={
                <Space style={{ display: "flex", justifyContent: "flex-end" }}>
                    <Button onClick={close}>取消</Button>
                    <Button
                        type="primary"
                        loading={busy}
                        onClick={() => form.submit()}
                        aria-label="保存"
                    >
                        提交
                    </Button>
                </Space>
            }
        >
            {error && <Alert type="error" message={error} showIcon />}
            <Form
                form={form}
                layout="vertical"
                requiredMark={false}
                onFinish={submit}
            >
                {item("email", text("email"))}
                {item("invite_user_email", text("invite_user_email"))}
                {item("password", text("password", true))}
                <Row gutter={16}>
                    <Col xs={24} md={12}>
                        {item(
                            "balance",
                            <Input
                                aria-label="余额"
                                placeholder="余额"
                                type="number"
                                step="any"
                                addonAfter="¥"
                                inputMode="decimal"
                            />,
                        )}
                    </Col>
                    <Col xs={24} md={12}>
                        {item(
                            "commission_balance",
                            <Input
                                aria-label="佣金余额"
                                placeholder="推广佣金"
                                type="number"
                                step="any"
                                addonAfter="¥"
                                inputMode="decimal"
                            />,
                        )}
                    </Col>
                </Row>
                <Row gutter={16}>
                    <Col xs={24} md={12}>
                        {item("u", numeric("u", "GB"))}
                    </Col>
                    <Col xs={24} md={12}>
                        {item("d", numeric("d", "GB"))}
                    </Col>
                </Row>
                {item("transfer_enable", numeric("transfer_enable", "GB"))}
                {item("device_limit", text("device_limit"))}
                {item(
                    "expired_at",
                    <DatePicker
                        aria-label="到期时间"
                        placeholder="长期有效"
                        showTime
                        style={{ width: "100%" }}
                    />,
                )}
                {item(
                    "plan_id",
                    <Select
                        aria-label="订阅计划"
                        placeholder="请选择用户订阅计划"
                        allowClear
                        options={[
                            { value: 0, label: "无" },
                            ...plans.map((plan) => ({
                                value: plan.id,
                                label: plan.name,
                            })),
                        ]}
                    />,
                )}
                {item(
                    "banned",
                    <Select
                        aria-label="账户状态"
                        options={[
                            { value: 1, label: "封禁" },
                            { value: 0, label: "正常" },
                        ]}
                    />,
                )}
                {item(
                    "commission_type",
                    <Select
                        aria-label="推荐返利类型"
                        options={commissionTypeOptions}
                    />,
                )}
                {item(
                    "commission_rate",
                    <Input
                        aria-label="推荐返利比例"
                        placeholder={numberField("commission_rate").placeholder}
                        addonAfter="%"
                    />,
                )}
                {item(
                    "discount",
                    <Input
                        aria-label="专享折扣比例"
                        placeholder="请输入专享折扣比例"
                        addonAfter="%"
                    />,
                )}
                {item(
                    "speed_limit",
                    <Input
                        aria-label="限速"
                        placeholder="留空则不限制"
                        addonAfter="Mbps"
                    />,
                )}
                {item("is_admin", <Switch aria-label="是否管理员" />)}
                {item("is_staff", <Switch aria-label="是否员工" />)}
                {item(
                    "remarks",
                    <Input.TextArea
                        aria-label="备注"
                        rows={4}
                        placeholder="请在这里记录.."
                    />,
                )}
            </Form>
        </Drawer>
    );
}
