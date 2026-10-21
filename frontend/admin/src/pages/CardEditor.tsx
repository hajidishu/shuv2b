import { useState } from "react";
import {
    Alert,
    DatePicker,
    Form,
    Input,
    InputNumber,
    Modal,
    Select,
} from "antd";
import dayjs, { type Dayjs } from "dayjs";
import { cents, yuan } from "../domain.mjs";
import type { Row } from "../types";

const couponTypes = [
    { value: 1, label: "按金额优惠" },
    { value: 2, label: "按比例优惠" },
];
const giftTypes = [
    { value: 1, label: "增加账户余额" },
    { value: 2, label: "增加订阅时长" },
    { value: 3, label: "增加套餐流量" },
    { value: 4, label: "重置套餐流量" },
    { value: 5, label: "兑换订阅套餐" },
];

export default function CardEditor({
    gift,
    record,
    plans,
    save,
    close,
}: {
    gift: boolean;
    record: Row;
    plans: Row[];
    save: (data: Row) => Promise<void>;
    close: () => void;
}) {
    const [form] = Form.useForm();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    const type = Form.useWatch("type", form) ?? record.type ?? 1;
    const generateCount = Form.useWatch("generate_count", form);
    const code = Form.useWatch("code", form);
    const start = record.started_at
        ? dayjs(Number(record.started_at) * 1000)
        : null;
    const end = record.ended_at ? dayjs(Number(record.ended_at) * 1000) : null;
    const initial = {
        ...record,
        type: record.type ?? 1,
        value:
            record.value == null
                ? undefined
                : gift
                  ? Number(record.type) === 1
                      ? yuan(record.value)
                      : record.value
                  : Number(record.type) === 1
                    ? yuan(record.value)
                    : record.value,
        dates: start || end ? [start, end] : undefined,
    };
    const submit = async (values: Row) => {
        setBusy(true);
        setError("");
        try {
            let value = values.value;
            if (
                Number(values.type) === 1 &&
                value !== undefined &&
                value !== null &&
                value !== ""
            )
                value = cents(value);
            else if (value !== undefined && value !== null && value !== "")
                value = Number(value);
            const dates = values.dates as
                | [Dayjs | null, Dayjs | null]
                | undefined;
            const payload: Row = {
                name: values.name,
                type: Number(values.type),
                value: gift && Number(values.type) === 4 ? 0 : value,
                code: values.code || null,
                generate_count: values.generate_count || undefined,
                started_at: dates?.[0] ? dates[0].unix() : null,
                ended_at: dates?.[1] ? dates[1].unix() : null,
                limit_use: values.limit_use ?? null,
                ...(gift
                    ? Number(values.type) === 5
                        ? { plan_id: values.plan_id }
                        : {}
                    : {
                          limit_use_with_user:
                              values.limit_use_with_user ?? null,
                          limit_plan_ids: (values.limit_plan_ids ?? []).map(
                              Number,
                          ),
                          limit_period: values.limit_period ?? [],
                      }),
                ...(record.id ? { id: record.id } : {}),
            };
            await save(payload);
            close();
        } catch (e) {
            setError((e as Error).message);
        } finally {
            setBusy(false);
        }
    };
    const showCode = !generateCount;
    const showBatch = !record.id && !code;
    const plansOptions = plans.map((plan) => ({
        value: plan.id,
        label: plan.name,
    }));

    return (
        <Modal
            open
            className="original-editor original-editor-modal card-editor"
            okButtonProps={{ "aria-label": "保存" }}
            title={
                record.id
                    ? gift
                        ? "编辑礼品卡"
                        : "编辑优惠券"
                    : gift
                      ? "新建礼品卡"
                      : "新建优惠券"
            }
            width={520}
            onCancel={close}
            onOk={() => form.submit()}
            okText="提交"
            cancelText="取消"
            confirmLoading={busy}
            destroyOnHidden
        >
            {error && <Alert type="error" showIcon message={error} />}
            <Form
                form={form}
                initialValues={initial}
                onFinish={submit}
                layout="vertical"
                requiredMark={false}
            >
                <Form.Item
                    label="名称"
                    name="name"
                    rules={[{ required: true, message: "请填写名称" }]}
                >
                    <Input
                        aria-label={gift ? "礼品卡名称" : "优惠券名称"}
                        placeholder={
                            gift ? "请输入礼品卡名称" : "请输入优惠券名称"
                        }
                    />
                </Form.Item>
                <Form.Item name="type" hidden>
                    <Input />
                </Form.Item>
                {showCode && (
                    <Form.Item
                        label={gift ? "自定义礼品卡卡密" : "自定义优惠券码"}
                        name="code"
                    >
                        <Input
                            placeholder={
                                gift
                                    ? "自定义礼品卡卡密(留空随机生成)"
                                    : "自定义优惠券码(留空随机生成)"
                            }
                        />
                    </Form.Item>
                )}
                <Form.Item label={gift ? "礼品卡类型" : "优惠信息"}>
                    <Form.Item
                        name="value"
                        noStyle
                        rules={[
                            {
                                required: !gift || Number(type) !== 4,
                                message: "请输入值",
                            },
                        ]}
                    >
                        <InputNumber
                            stringMode
                            aria-label="数值"
                            min={0}
                            style={{ width: "100%" }}
                            addonBefore={
                                <Select
                                    aria-label="类型"
                                    value={Number(type)}
                                    options={gift ? giftTypes : couponTypes}
                                    style={{ minWidth: 154 }}
                                    onChange={(nextType) =>
                                        form.setFieldsValue({
                                            type: nextType,
                                            value:
                                                gift && nextType === 4
                                                    ? 0
                                                    : undefined,
                                        })
                                    }
                                />
                            }
                            addonAfter={
                                gift
                                    ? Number(type) === 1
                                        ? "¥"
                                        : Number(type) === 2 ||
                                            Number(type) === 5
                                          ? "天"
                                          : Number(type) === 3
                                            ? "GB"
                                            : undefined
                                    : Number(type) === 1
                                      ? "¥"
                                      : "%"
                            }
                            disabled={gift && Number(type) === 4}
                            placeholder={
                                gift && Number(type) === 5
                                    ? "一次性套餐输入0"
                                    : "请输入值"
                            }
                        />
                    </Form.Item>
                </Form.Item>
                {gift && Number(type) === 5 && (
                    <Form.Item
                        label="指定订阅"
                        name="plan_id"
                        rules={[{ required: true, message: "请选择订阅" }]}
                    >
                        <Select
                            aria-label="订阅计划"
                            options={plansOptions}
                            placeholder="指定订阅"
                        />
                    </Form.Item>
                )}
                <Form.Item
                    label={gift ? "礼品卡有效期" : "优惠券有效期"}
                    name="dates"
                    rules={[{ required: true, message: "请选择有效期" }]}
                >
                    <DatePicker.RangePicker
                        id={{ start: "started_at", end: "ended_at" }}
                        aria-label="开始时间, 结束时间"
                        style={{ width: "100%" }}
                        showTime={{ format: "HH:mm" }}
                        format="YYYY-MM-DD HH:mm"
                        placeholder={["Start Time", "End Time"]}
                    />
                </Form.Item>
                <Form.Item
                    label={gift ? "最大使用次数" : "最大使用次数"}
                    name="limit_use"
                >
                    <Input placeholder="限制最大使用次数，用完则无法使用(为空则不限制)" />
                </Form.Item>
                {!gift && (
                    <Form.Item
                        label="每个用户可使用次数"
                        name="limit_use_with_user"
                    >
                        <Input placeholder="限制每个用户可使用次数(为空则不限制)" />
                    </Form.Item>
                )}
                {!gift && (
                    <Form.Item label="限定套餐" name="limit_plan_ids">
                        <Select
                            mode="multiple"
                            aria-label="限定套餐"
                            options={plansOptions}
                            placeholder="限制指定订阅可以使用优惠(为空则不限制)"
                        />
                    </Form.Item>
                )}
                {!gift && (
                    <Form.Item label="限定周期" name="limit_period">
                        <Select
                            mode="multiple"
                            aria-label="限定周期"
                            options={[
                                { value: "month_price", label: "月付" },
                                { value: "quarter_price", label: "季付" },
                                { value: "half_year_price", label: "半年付" },
                                { value: "year_price", label: "年付" },
                                { value: "two_year_price", label: "两年付" },
                                { value: "three_year_price", label: "三年付" },
                                { value: "onetime_price", label: "一次性" },
                            ]}
                            placeholder="限制指定周期可以使用优惠(为空则不限制)"
                        />
                    </Form.Item>
                )}
                {showBatch && (
                    <Form.Item label="生成数量" name="generate_count">
                        <Input placeholder="输入数量批量生成" />
                    </Form.Item>
                )}
            </Form>
        </Modal>
    );
}
