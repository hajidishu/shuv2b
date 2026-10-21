import React, { useEffect, useState, useRef } from "react";
import {
    Alert,
    App,
    Button,
    Card,
    Collapse,
    Drawer,
    Form,
    Input,
    InputNumber,
    Modal,
    Select,
    Space,
    Spin,
    Switch,
    Tag,
    Tooltip,
} from "antd";
import type { Field, Row } from "./types";
import { api, ApiError, request } from "./api";
import { cents, yuan } from "./domain.mjs";
import KnowledgeInput from "./KnowledgeInput";
import DateInput from "./DateInput";
import { QuestionCircleOutlined } from "@ant-design/icons";
export function useResource(path: string, params: Row = {}, enabled = true) {
    const [response, setResponse] = useState<Row>({ data: [] });
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState("");
    const [version, reload] = useState(0);
    const signature = JSON.stringify(params);
    useEffect(() => {
        if (!enabled) return;
        const abort = new AbortController();
        setLoading(true);
        setError("");
        request(path, JSON.parse(signature), "GET", false, abort.signal)
            .then(setResponse)
            .catch((e) => {
                if (e.name !== "AbortError") setError(e.message);
            })
            .finally(() => {
                if (!abort.signal.aborted) setLoading(false);
            });
        return () => abort.abort();
    }, [path, signature, version, enabled]);
    return {
        response,
        data: response.data,
        loading,
        error,
        reload: () => reload((v) => v + 1),
    };
}
export function useAction() {
    const { message, modal } = App.useApp();
    const [busy, setBusy] = useState(false);
    return {
        busy,
        modal,
        message,
        run: async (fn: () => Promise<any>, success = "操作成功") => {
            setBusy(true);
            try {
                const value = await fn();
                if (success) message.success(success);
                return value;
            } catch (e) {
                message.error((e as Error).message);
                throw e;
            } finally {
                setBusy(false);
            }
        },
    };
}
export function ErrorNotice({ error }: { error: string }) {
    return error ? <Alert type="error" message={error} showIcon /> : null;
}
export function Page({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="admin-page">
            <div className="page-heading">
                <h1>{title}</h1>
            </div>
            {children}
        </section>
    );
}
export function useReferences() {
    const plans = useResource("/plan/fetch"),
        groups = useResource("/server/group/fetch"),
        routes = useResource("/server/route/fetch");
    return {
        plans: plans.data ?? [],
        groups: groups.data ?? [],
        routes: routes.data ?? [],
        parents: [],
        error: plans.error || groups.error || routes.error,
        reloadGroups: groups.reload,
    };
}
export function displayValues(fields: Field[], data: Row) {
    const out: Row = {};
    for (const f of fields) {
        let v = data[f.key] ?? f.default;
        // User passwords are write-only: never reuse a hash returned by the legacy API.
        if (f.key === "password") v = undefined;
        if (f.kind === "money") v = yuan(v);
        if (f.kind === "gb" && v != null) v = Number(v) / 1073741824;
        if (f.kind === "date" && v != null) {
            const date = new Date(Number(v) * 1000);
            v = new Date(date.getTime() - date.getTimezoneOffset() * 60000)
                .toISOString()
                .slice(0, 19);
        }
        if (f.kind === "json")
            v =
                typeof v === "string"
                    ? v
                    : v == null
                      ? ""
                      : JSON.stringify(v, null, 2);
        if (f.kind === "lines") v = Array.isArray(v) ? v.join("\n") : (v ?? "");
        out[f.key] = v;
    }
    return out;
}
export function payloadValues(fields: Field[], data: Row) {
    const out: Row = {};
    for (const f of fields) {
        if (!(f.key in data)) continue;
        let v = data[f.key];
        if (v === undefined) continue;
        if (v === "") v = null;
        if (f.kind === "money") v = cents(v);
        if (f.kind === "gb" && v != null) {
            v = Math.round(Number(v) * 1073741824);
            if (!Number.isSafeInteger(v)) throw new Error("流量数值超出范围");
        }
        if (f.kind === "date" && v != null)
            v = Math.floor(new Date(v).getTime() / 1000);
        if (f.kind === "json" && v != null) {
            v = JSON.parse(v);
            if (typeof v !== "object" || v === null)
                throw new Error(f.label + "必须是 JSON 对象或数组");
        }
        if (f.kind === "switch") v = v ? 1 : 0;
        if (f.kind === "lines")
            v = String(v ?? "")
                .split(/\r?\n/)
                .map((item) => item.trim())
                .filter(Boolean);
        if (f.kind === "password" && f.key === "password" && !v) continue;
        out[f.key] = v;
    }
    return out;
}
function HtmlInput({
    value = "",
    onChange,
    id,
}: {
    value?: string;
    onChange?: (v: string) => void;
    id?: string;
}) {
    const ref = useRef<any>();
    const [preview, setPreview] = useState(false);
    const insert = (start: string, end: string) => {
        const area = ref.current?.resizableTextArea?.textArea;
        const a = area?.selectionStart ?? value.length,
            b = area?.selectionEnd ?? value.length;
        onChange?.(
            value.slice(0, a) +
                start +
                value.slice(a, b) +
                end +
                value.slice(b),
        );
    };
    const clean = () => {
        const doc = new DOMParser().parseFromString(value, "text/html");
        doc.querySelectorAll(
            "script,style,iframe,object,embed,link,meta,form",
        ).forEach((n) => n.remove());
        doc.body.querySelectorAll("*").forEach((n) =>
            Array.from(n.attributes).forEach((a) => {
                if (
                    /^on/i.test(a.name) ||
                    ["style", "srcdoc"].includes(a.name) ||
                    (/^(href|src)$/i.test(a.name) &&
                        !/^https?:\/\//i.test(a.value))
                )
                    n.removeAttribute(a.name);
            }),
        );
        const allowedTags = new Set([
            "P",
            "B",
            "STRONG",
            "I",
            "EM",
            "U",
            "H1",
            "H2",
            "H3",
            "H4",
            "H5",
            "H6",
            "UL",
            "OL",
            "LI",
            "BR",
            "DIV",
            "SPAN",
            "A",
            "IMG",
            "PRE",
            "CODE",
            "BLOCKQUOTE",
            "TABLE",
            "THEAD",
            "TBODY",
            "TR",
            "TH",
            "TD",
            "HR",
        ]);
        const allowedAttrs = new Set([
            "href",
            "src",
            "alt",
            "title",
            "colspan",
            "rowspan",
            "align",
            "target",
            "rel",
        ]);
        doc.body.querySelectorAll("*").forEach((n) => {
            if (!allowedTags.has(n.tagName)) {
                n.remove();
                return;
            }
            Array.from(n.attributes).forEach((a) => {
                if (!allowedAttrs.has(a.name.toLowerCase()))
                    n.removeAttribute(a.name);
            });
            if (n.tagName === "A") n.setAttribute("rel", "noopener noreferrer");
        });
        return doc.body.innerHTML;
    };
    return (
        <>
            <Space wrap>
                <Button
                    size="small"
                    onClick={() => insert("<strong>", "</strong>")}
                >
                    粗体
                </Button>
                <Button size="small" onClick={() => insert("<em>", "</em>")}>
                    斜体
                </Button>
                <Button size="small" onClick={() => insert("<h2>", "</h2>")}>
                    标题
                </Button>
                <Button
                    size="small"
                    onClick={() => insert("<ul><li>", "</li></ul>")}
                >
                    列表
                </Button>
                <Button
                    size="small"
                    onClick={() => insert('<a href="https://">', "</a>")}
                >
                    链接
                </Button>
                <Button
                    size="small"
                    onClick={() => insert('<img src="https://" alt="', '">')}
                >
                    图片
                </Button>
                <Button size="small" onClick={() => setPreview(!preview)}>
                    预览
                </Button>
            </Space>
            {preview ? (
                <div
                    className="html-preview"
                    dangerouslySetInnerHTML={{ __html: clean() }}
                />
            ) : (
                <Input.TextArea
                    id={id}
                    ref={ref}
                    value={value}
                    onChange={(e) => onChange?.(e.target.value)}
                    autoSize={{ minRows: 6, maxRows: 20 }}
                />
            )}
        </>
    );
}
function JsonInput({
    value = "",
    onChange,
    field,
    inline = false,
}: {
    value?: string;
    onChange?: (v: string) => void;
    field: Field;
    inline?: boolean;
}) {
    const [open, setOpen] = useState(false);
    let parsed: Row = {},
        valid = true;
    try {
        parsed =
            value && value !== "{}"
                ? JSON.parse(value)
                : (field.jsonDefaults ?? {});
        if (
            value &&
            (!parsed ||
                typeof parsed !== "object" ||
                (field.key === "padding_scheme" && !Array.isArray(parsed)))
        )
            valid = false;
    } catch {
        valid = false;
    }
    const update = (key: string, value: any) => {
        const next = { ...parsed };
        if (value === undefined || value === "") delete next[key];
        else next[key] = value;
        onChange?.(JSON.stringify(next, null, 2));
    };
    if (field.inlineObfs)
        return (
            <div className="ss-obfs-grid">
                <Input
                    aria-label="混淆路径"
                    placeholder="路径"
                    value={(parsed as Row).path ?? ""}
                    onChange={(e) => update("path", e.target.value)}
                />
                <Input
                    aria-label="混淆 Host"
                    placeholder="Host"
                    value={(parsed as Row).host ?? ""}
                    onChange={(e) => update("host", e.target.value)}
                />
            </div>
        );
    return (
        <>
            <Space className={inline ? "node-config-link" : undefined}>
                <Button
                    type="link"
                    size="small"
                    aria-label={"编辑" + field.label}
                    onClick={(e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        setOpen(true);
                    }}
                >
                    {field.key === "padding_scheme"
                        ? "编辑填充方案"
                        : "编辑配置"}
                </Button>
                {!inline && (
                    <span className={valid ? "config-summary" : "error-text"}>
                        {!valid ? "JSON 格式错误" : value ? "已配置" : "未配置"}
                    </span>
                )}
            </Space>
            <Drawer
                open={open}
                title={"编辑" + field.label}
                aria-label={"编辑" + field.label}
                width={field.drawerWidth ?? 520}
                styles={
                    field.configStyle
                        ? { wrapper: { maxWidth: 500 } }
                        : undefined
                }
                className={field.configStyle ? "node-config-editor" : undefined}
                destroyOnHidden
                onClose={() => setOpen(false)}
                footer={
                    <Space
                        style={{ display: "flex", justifyContent: "flex-end" }}
                    >
                        <Button onClick={() => setOpen(false)}>返回</Button>
                        <Button
                            type="primary"
                            disabled={!valid}
                            onClick={() => setOpen(false)}
                        >
                            完成
                        </Button>
                    </Space>
                }
            >
                {!valid && (
                    <Alert
                        type="error"
                        showIcon
                        message={
                            field.key === "padding_scheme"
                                ? "请输入有效 JSON 数组"
                                : "请输入有效 JSON 对象或数组"
                        }
                    />
                )}
                {field.nested && (
                    <div className="nested-grid">
                        {field.nested
                            .filter((n) => !n.when || n.when(parsed))
                            .map((n) => (
                                <label key={n.key}>
                                    {n.label}
                                    {n.kind === "switch" ? (
                                        <Switch
                                            disabled={!valid}
                                            checked={
                                                parsed?.[n.key] === true ||
                                                Number(parsed?.[n.key]) === 1
                                            }
                                            onChange={(v) =>
                                                update(n.key, v ? 1 : 0)
                                            }
                                        />
                                    ) : n.kind === "select" ? (
                                        <Select
                                            allowClear
                                            disabled={!valid}
                                            value={parsed?.[n.key]}
                                            options={n.options}
                                            onChange={(v) => update(n.key, v)}
                                        />
                                    ) : n.kind === "number" ? (
                                        <InputNumber
                                            disabled={!valid}
                                            value={
                                                parsed?.[n.key] == null
                                                    ? null
                                                    : Number(parsed[n.key])
                                            }
                                            onChange={(v) => update(n.key, v)}
                                        />
                                    ) : (
                                        <Input
                                            disabled={!valid}
                                            type={
                                                n.kind === "password"
                                                    ? "password"
                                                    : "text"
                                            }
                                            value={parsed?.[n.key] ?? ""}
                                            onChange={(e) =>
                                                update(n.key, e.target.value)
                                            }
                                        />
                                    )}
                                </label>
                            ))}
                    </div>
                )}
                <h4 className="json-section-title">JSON 配置</h4>
                <Input.TextArea
                    aria-label={field.label + " JSON"}
                    className="json-editor"
                    value={value}
                    onChange={(e) => onChange?.(e.target.value)}
                    autoSize={{ minRows: 8, maxRows: 20 }}
                />
            </Drawer>
        </>
    );
}

export function Fields({
    fields,
    references = {},
    settings = false,
}: {
    fields: Field[];
    references?: Row;
    settings?: boolean;
}) {
    return (
        <div className={settings ? "settings-fields" : "form-grid"}>
            {fields.map((f) => {
                let options = f.options;
                if (f.reference) {
                    options = [
                        ...(f.options ?? []),
                        ...(references[f.reference] ?? []).map((r: Row) => ({
                            value: r.id,
                            label: r.name ?? r.remarks,
                        })),
                    ];
                }
                let input: React.ReactNode;
                switch (f.kind) {
                    case "number":
                        input = (
                            <InputNumber
                                placeholder={f.placeholder}
                                addonAfter={f.addonAfter}
                                style={{ width: "100%" }}
                                min={f.min}
                            />
                        );
                        break;
                    case "money":
                        input = (
                            <Input
                                type={f.inputType}
                                addonBefore={
                                    f.addonBefore === null
                                        ? undefined
                                        : (f.addonBefore ?? "¥")
                                }
                                addonAfter={f.addonAfter}
                                placeholder={f.placeholder}
                                inputMode="decimal"
                            />
                        );
                        break;
                    case "gb":
                        input = (
                            <InputNumber
                                placeholder={f.placeholder}
                                min={0}
                                addonAfter="GB"
                                style={{ width: "100%" }}
                            />
                        );
                        break;
                    case "date":
                        input = <DateInput placeholder={f.placeholder} />;
                        break;
                    case "switch":
                        input = <Switch />;
                        break;
                    case "select":
                        input = (
                            <Select
                                placeholder={f.placeholder}
                                allowClear
                                showSearch
                                optionFilterProp="label"
                                mode={f.multiple ? "multiple" : undefined}
                                options={options}
                            />
                        );
                        break;
                    case "tags":
                        input = (
                            <Select
                                placeholder={f.placeholder}
                                mode="tags"
                                tokenSeparators={[",", "\n"]}
                                options={options}
                            />
                        );
                        break;
                    case "json":
                        input = <JsonInput field={f} />;
                        break;
                    case "html":
                        input = <HtmlInput />;
                        break;
                    case "markdown":
                        input = <KnowledgeInput />;
                        break;
                    case "lines":
                    case "textarea":
                        input = (
                            <Input.TextArea
                                placeholder={f.placeholder}
                                autoSize={{ minRows: f.rows ?? 3, maxRows: 12 }}
                            />
                        );
                        break;
                    case "password":
                        input = (
                            <Input.Password
                                placeholder={f.placeholder}
                                autoComplete="new-password"
                            />
                        );
                        break;
                    default:
                        input = (
                            <Input
                                type={f.inputType}
                                placeholder={f.placeholder}
                                addonAfter={f.addonAfter}
                            />
                        );
                }
                if (
                    (f.ariaLabel || f.config || f.labelAction || f.helpLink) &&
                    React.isValidElement(input)
                )
                    input = React.cloneElement(
                        input as React.ReactElement<any>,
                        { "aria-label": f.ariaLabel ?? f.label },
                    );
                const rules: any[] = [
                    ...(f.required
                        ? [{ required: true, message: "请填写" + f.label }]
                        : []),
                    ...(f.kind === "money"
                        ? [
                              {
                                  validator: (_: any, v: any) => {
                                      try {
                                          cents(v);
                                          return Promise.resolve();
                                      } catch (e) {
                                          return Promise.reject(e);
                                      }
                                  },
                              },
                          ]
                        : []),
                    ...(f.kind === "json"
                        ? [
                              {
                                  validator: (_: any, v: any) => {
                                      if (!v) return Promise.resolve();
                                      try {
                                          const p = JSON.parse(v);
                                          if (
                                              !p ||
                                              typeof p !== "object" ||
                                              (f.key === "padding_scheme" &&
                                                  !Array.isArray(p))
                                          )
                                              throw new Error("格式有误");
                                          return Promise.resolve();
                                      } catch {
                                          return Promise.reject(
                                              new Error(
                                                  f.key === "padding_scheme"
                                                      ? "请输入有效 JSON 数组"
                                                      : "请输入有效 JSON 对象或数组",
                                              ),
                                          );
                                      }
                                  },
                              },
                          ]
                        : []),
                ];
                return (
                    <Form.Item
                        key={f.key}
                        name={f.key}
                        {...(f.kind === "select"
                            ? { getValueFromEvent: (v: any) => v ?? null }
                            : {})}
                        style={
                            f.span
                                ? { gridColumn: `span ${f.span}` }
                                : undefined
                        }
                        label={
                            f.configStyle &&
                            f.kind === "json" ? undefined : settings ? (
                                <div className="settings-label">
                                    <strong>{f.label}</strong>
                                    {f.help && <span>{f.help}</span>}
                                </div>
                            ) : (
                                <span>
                                    {f.label}
                                    {f.helpTooltip && (
                                        <Tooltip title={f.helpTooltip}>
                                            <QuestionCircleOutlined
                                                style={{ marginLeft: 4 }}
                                            />
                                        </Tooltip>
                                    )}
                                    {f.helpLink && (
                                        <>
                                            {" "}
                                            <a
                                                href={f.helpLink.href}
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                {f.helpLink.label}
                                            </a>
                                        </>
                                    )}
                                    {f.labelAction && (
                                        <Button
                                            type="link"
                                            size="small"
                                            onClick={(e) => {
                                                e.preventDefault();
                                                e.stopPropagation();
                                                f.labelAction!.onClick();
                                            }}
                                        >
                                            {f.labelAction.label}
                                        </Button>
                                    )}
                                    {f.config && (
                                        <Form.Item noStyle name={f.config.key}>
                                            <JsonInput
                                                field={f.config}
                                                inline
                                            />
                                        </Form.Item>
                                    )}
                                </span>
                            )
                        }
                        extra={settings ? undefined : f.help}
                        rules={rules}
                        className={
                            ["json", "textarea", "html"].includes(f.kind ?? "")
                                ? "full-width"
                                : ""
                        }
                        {...(f.kind === "switch"
                            ? {
                                  valuePropName: "checked",
                                  getValueProps: (v: any) => ({
                                      checked: !!Number(v),
                                  }),
                                  getValueFromEvent: (v: boolean) =>
                                      v ? 1 : 0,
                              }
                            : {})}
                    >
                        {input}
                    </Form.Item>
                );
            })}
        </div>
    );
}
export function Editor({
    title,
    fields,
    initial = {},
    references = {},
    save,
    close,
    children,
    presentation = "drawer",
    width,
    submitLabel = "保存",
    className = "",
}: {
    title: string;
    fields: Field[] | ((values: Row) => Field[]);
    initial?: Row;
    references?: Row;
    save: (data: Row) => Promise<void>;
    close: () => void;
    children?: React.ReactNode;
    presentation?: "drawer" | "modal";
    width?: number | string;
    submitLabel?: string;
    className?: string;
}) {
    const [form] = Form.useForm();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    const watchedValues = Form.useWatch([], { form, preserve: true });
    const values = { ...initial, ...watchedValues };
    const definitions = typeof fields === "function" ? fields(values) : fields;
    const visibleFields = definitions.filter(
        (field) => !field.when || field.when(values),
    );
    useEffect(() => {
        const initialFields =
            typeof fields === "function" ? fields(initial) : fields;
        form.setFieldsValue(displayValues(initialFields, initial));
    }, []);
    const body = (
        <>
            <ErrorNotice error={error} />
            {children}
            <Form
                form={form}
                requiredMark={false}
                layout="vertical"
                onFinish={async (values) => {
                    setBusy(true);
                    setError("");
                    try {
                        await save(payloadValues(visibleFields, values));
                        close();
                    } catch (e) {
                        setError((e as Error).message);
                        if (e instanceof ApiError)
                            form.setFields(
                                Object.entries(e.fields).map(
                                    ([name, messages]) => ({
                                        name,
                                        errors: messages as string[],
                                    }),
                                ),
                            );
                    } finally {
                        setBusy(false);
                    }
                }}
            >
                <Fields fields={visibleFields} references={references} />
            </Form>
        </>
    );
    if (presentation === "modal")
        return (
            <Modal
                open
                className={"original-editor original-editor-modal " + className}
                title={title}
                width={width ?? 520}
                onCancel={close}
                onOk={() => form.submit()}
                confirmLoading={busy}
                okText={submitLabel}
                okButtonProps={{ "aria-label": "保存" }}
                cancelText="取消"
                destroyOnHidden
            >
                {body}
            </Modal>
        );
    return (
        <Drawer
            open
            className={"original-editor original-editor-drawer " + className}
            title={title}
            aria-label={title}
            width={width ?? 500}
            onClose={close}
            destroyOnHidden
            footer={
                <Space>
                    <Button onClick={close}>取消</Button>
                    <Button
                        type="primary"
                        loading={busy}
                        onClick={() => form.submit()}
                        aria-label="保存"
                    >
                        {submitLabel}
                    </Button>
                </Space>
            }
        >
            {body}
        </Drawer>
    );
}
export function DataCard({
    loading,
    error,
    children,
    title,
}: {
    loading?: boolean;
    error?: string;
    children: React.ReactNode;
    title?: React.ReactNode;
}) {
    return (
        <Card title={title} className={title ? "data-section-card" : undefined}>
            <ErrorNotice error={error ?? ""} />
            <Spin spinning={!!loading}>{children}</Spin>
        </Card>
    );
}
export function Status({ value }: { value: any }) {
    return (
        <Tag color={value ? "green" : "default"}>{value ? "启用" : "禁用"}</Tag>
    );
}
export const formatDate = (value: any) =>
    value !== null && value !== undefined
        ? new Date(value * 1000).toLocaleString("zh-CN", { hour12: false })
        : "长期有效";
export const formatMoney = (value: any) => "¥" + (yuan(value) ?? "0.00");
export function KeyValues({ data }: { data: Row }) {
    return (
        <div className="key-values">
            {Object.entries(data ?? {}).map(([key, value]) => (
                <div key={key}>
                    <strong>{key}</strong>
                    <span>
                        {typeof value === "object"
                            ? JSON.stringify(value)
                            : String(value ?? "—")}
                    </span>
                </div>
            ))}
        </div>
    );
}
