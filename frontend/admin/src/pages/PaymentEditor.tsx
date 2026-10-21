import { useEffect, useState } from "react";
import { Form, Modal, Select, Spin } from "antd";
import { api, ApiError } from "../api";
import {
    displayValues,
    ErrorNotice,
    Fields,
    payloadValues,
    useResource,
} from "../components";
import type { Field, Row } from "../types";
import { paymentFields } from "../schema/payments";

const base: Field[] = [
    {
        key: "name",
        label: "显示名称",
        required: true,
        placeholder: "用于前端显示使用",
        span: 12,
    },
    {
        key: "icon",
        label: "图标URL(选填)",
        placeholder: "用于前端显示使用(https://x.com/icon.svg)",
        span: 12,
    },
    {
        key: "notify_domain",
        label: "自定义通知域名(选填)",
        placeholder: "网关的通知将会发送到该域名(https://x.com)",
        span: 12,
    },
    {
        key: "handling_fee_percent",
        label: "百分比手续费(选填)",
        kind: "number",
        addonAfter: "%",
        placeholder: "在订单金额基础上附加手续费",
        span: 6,
    },
    {
        key: "handling_fee_fixed",
        label: "固定手续费(选填)",
        ariaLabel: "固定手续费",
        kind: "money",
        addonBefore: null,
        addonAfter: "¥",
        inputType: "number",
        placeholder: "在订单金额基础上附加手续费",
        span: 6,
    },
];

export default function PaymentEditor({
    record,
    close,
    reload,
}: {
    record: Row;
    close: () => void;
    reload: () => void;
}) {
    const [form] = Form.useForm(),
        methods = useResource("/payment/getPaymentMethods");
    const [method, setMethod] = useState(record.payment ?? ""),
        [meta, setMeta] = useState<Row>({});
    const [loading, setLoading] = useState(true),
        [saving, setSaving] = useState(false),
        [error, setError] = useState("");
    useEffect(() => {
        form.setFieldsValue(displayValues(base, record));
    }, []);
    useEffect(() => {
        if (!method && methods.data?.length) setMethod(methods.data[0]);
    }, [methods.data]);
    const dynamic = paymentFields(meta);
    useEffect(() => {
        if (!method) return;
        let live = true;
        setLoading(true);
        setError("");
        setMeta({});
        api(
            "/payment/getPaymentForm",
            {
                payment: method,
                ...(record.payment === method && record.id
                    ? { id: record.id }
                    : {}),
            },
            "POST",
        )
            .then((r) => {
                if (!live) return;
                const fields = r.data ?? {};
                setMeta(fields);
                form.setFieldsValue(
                    Object.fromEntries(
                        Object.entries(fields)
                            .filter(
                                ([, m]: [string, any]) => m.type !== "alert",
                            )
                            .map(([key, m]: [string, any]) => [
                                "config_" + key,
                                m.value ??
                                    (record.payment === method
                                        ? record.config?.[key]
                                        : undefined) ??
                                    "",
                            ]),
                    ),
                );
            })
            .catch((e) => {
                if (live) setError(e.message);
            })
            .finally(() => {
                if (live) setLoading(false);
            });
        return () => {
            live = false;
        };
    }, [method]);
    const submit = async (values: Row) => {
        setSaving(true);
        setError("");
        try {
            const v = payloadValues([...base, ...dynamic], values);
            const config = Object.fromEntries(
                dynamic.map((field) => {
                    const key = field.key.slice(7);
                    return [key, v[field.key] ?? ""];
                }),
            );
            const payload: Row = {
                ...Object.fromEntries(base.map((f) => [f.key, v[f.key]])),
                payment: method,
                config,
                ...(record.id ? { id: record.id } : {}),
            };
            if (
                payload.handling_fee_percent == null ||
                payload.handling_fee_percent === 0
            )
                payload.handling_fee_percent = null;
            await api("/payment/save", payload, "POST");
            reload();
            close();
        } catch (e) {
            setError((e as Error).message);
            if (e instanceof ApiError)
                form.setFields(
                    Object.entries(e.fields).map(([name, errors]) => ({
                        name,
                        errors: errors as string[],
                    })),
                );
        } finally {
            setSaving(false);
        }
    };
    return (
        <Modal
            open
            title={record.id ? "编辑支付方式" : "添加支付方式"}
            width={520}
            className="payment-modal"
            onCancel={close}
            onOk={() => form.submit()}
            okText={record.id ? "保存" : "添加"}
            cancelText="取消"
            confirmLoading={saving}
            okButtonProps={{
                disabled: loading || !!methods.error,
                "aria-label": "保存",
            }}
            destroyOnHidden
        >
            <ErrorNotice error={error || methods.error} />
            <Form
                form={form}
                requiredMark={false}
                layout="vertical"
                onFinish={submit}
            >
                <Fields fields={base} />
                <Form.Item label="接口文件">
                    <Select
                        aria-label="编辑支付网关"
                        value={method || undefined}
                        options={(methods.data ?? []).map((value: string) => ({
                            value,
                            label: value,
                        }))}
                        onChange={setMethod}
                    />
                </Form.Item>
                <Spin spinning={loading}>
                    <Fields fields={dynamic} />
                </Spin>
            </Form>
        </Modal>
    );
}
