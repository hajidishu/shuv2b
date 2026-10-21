import { useState } from "react";
import { Alert, Form, Input, Modal } from "antd";
import { api } from "../api";
import type { Row } from "../types";

export default function UserMailModal({
    scope,
    recipientLabel,
    recipientCount,
    close,
    onSent,
}: {
    scope: Row;
    recipientLabel: string;
    recipientCount: number;
    close: () => void;
    onSent: () => void;
}) {
    const [form] = Form.useForm();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");

    const submit = async (values: Row) => {
        setBusy(true);
        setError("");
        try {
            await api("/user/sendMail", { ...scope, ...values }, "POST");
            onSent();
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
            className="user-mail-modal"
            title="发送邮件"
            onCancel={close}
            onOk={() => form.submit()}
            confirmLoading={busy}
            cancelText="取消"
            destroyOnHidden
        >
            {error && <Alert type="error" message={error} showIcon />}
            <Alert
                type="info"
                message={`发送给 ${recipientCount} 个${recipientLabel}`}
                style={{ marginBottom: 16 }}
            />
            <Form
                form={form}
                layout="vertical"
                requiredMark={false}
                onFinish={submit}
            >
                <Form.Item label="收件人">
                    <Input
                        aria-label="收件人"
                        disabled
                        value={recipientLabel}
                    />
                </Form.Item>
                <Form.Item
                    label="主题"
                    name="subject"
                    rules={[{ required: true, message: "请输入邮件主题" }]}
                >
                    <Input aria-label="主题" placeholder="请输入邮件主题" />
                </Form.Item>
                <Form.Item
                    label="发送内容"
                    name="content"
                    rules={[{ required: true, message: "请输入邮件内容" }]}
                >
                    <Input.TextArea
                        aria-label="内容"
                        rows={12}
                        placeholder="请输入邮件内容"
                    />
                </Form.Item>
            </Form>
        </Modal>
    );
}
