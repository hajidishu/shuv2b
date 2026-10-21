import { useState } from "react";
import { Alert, Form, Input, Modal, Select, DatePicker } from "antd";
import dayjs from "dayjs";
import type { Row } from "../types";
import { api, downloadApi } from "../api";

export default function UserGenerateModal({
    plans,
    close,
    onCreated,
}: {
    plans: Row[];
    close: () => void;
    onCreated: () => void;
}) {
    const [form] = Form.useForm();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    const prefix = Form.useWatch("email_prefix", form);
    const count = Form.useWatch("generate_count", form);

    const submit = async (values: Row) => {
        setBusy(true);
        setError("");
        try {
            const payload = { ...values };
            payload.plan_id =
                Number(values.plan_id) > 0 ? Number(values.plan_id) : null;
            if (!payload.email_prefix) delete payload.email_prefix;
            if (!payload.generate_count) delete payload.generate_count;
            if (values.expired_at)
                payload.expired_at = Math.floor(
                    dayjs(values.expired_at).valueOf() / 1000,
                );
            else payload.expired_at = null;
            if (values.generate_count)
                await downloadApi(
                    "/user/generate",
                    payload,
                    "generated-users.csv",
                );
            else await api("/user/generate", payload, "POST");
            onCreated();
            close();
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    };

    return (
        <Modal
            open
            className="user-generate-modal"
            title="创建用户"
            onCancel={close}
            onOk={() => form.submit()}
            confirmLoading={busy}
            okText="生成"
            cancelText="取消"
            destroyOnHidden
        >
            {error && <Alert type="error" message={error} showIcon />}
            <Form
                form={form}
                initialValues={{ plan_id: 0 }}
                layout="vertical"
                requiredMark={false}
                onFinish={submit}
            >
                <Form.Item label="邮箱">
                    <Input.Group compact>
                        {!count && (
                            <Form.Item
                                name="email_prefix"
                                noStyle
                                rules={[
                                    {
                                        required: !count,
                                        message: "请输入账号前缀",
                                    },
                                ]}
                            >
                                <Input
                                    aria-label="邮箱前缀"
                                    placeholder="账号（批量生成请留空）"
                                    style={{ width: "45%" }}
                                />
                            </Form.Item>
                        )}
                        <Input
                            aria-label="邮箱分隔符"
                            placeholder="@"
                            value="@"
                            disabled
                            style={{ width: "10%", textAlign: "center" }}
                        />
                        <Form.Item
                            name="email_suffix"
                            noStyle
                            rules={[
                                { required: true, message: "请输入邮箱域" },
                            ]}
                        >
                            <Input
                                aria-label="邮箱后缀"
                                placeholder="域"
                                style={{ width: count ? "90%" : "45%" }}
                            />
                        </Form.Item>
                    </Input.Group>
                </Form.Item>
                <Form.Item label="密码" name="password">
                    <Input
                        aria-label="密码"
                        placeholder="留空则密码与邮箱相同"
                    />
                </Form.Item>
                <Form.Item label="到期时间" name="expired_at">
                    <DatePicker
                        aria-label="到期时间"
                        showTime
                        placeholder="请选择用户到期日期，为空则不限制到期时间"
                        style={{ width: "100%" }}
                    />
                </Form.Item>
                <Form.Item label="订阅计划" name="plan_id">
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
                    />
                </Form.Item>
                {!prefix && (
                    <Form.Item
                        label="生成数量"
                        name="generate_count"
                        rules={[{ required: true, message: "请输入生成数量" }]}
                    >
                        <Input
                            aria-label="生成数量"
                            placeholder="如果为批量生成请输入生成数量"
                        />
                    </Form.Item>
                )}
                {prefix && count && (
                    <Alert
                        type="error"
                        message="单个生成和批量生成只能选择一种"
                    />
                )}
            </Form>
        </Modal>
    );
}
