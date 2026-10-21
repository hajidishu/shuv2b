import type { Field, Row } from "../types";

export function paymentFields(meta: Row): Field[] {
    return Object.entries(meta)
        .filter(([, m]) => m.type !== "alert")
        .map(([key, m]) => ({
            key: "config_" + key,
            label: m.label ?? key,
            placeholder: m.description,
            span: 12,
            kind:
                m.type === "select"
                    ? "select"
                    : m.type === "textarea"
                      ? "textarea"
                      : m.type === "number"
                        ? "number"
                        : m.type === "password" ||
                            /key|secret|password|token/i.test(key)
                          ? "password"
                          : "text",
            options: Array.isArray(m.options)
                ? m.options
                : m.options
                  ? Object.entries(m.options).map(([value, label]) => ({
                        value,
                        label: String(label),
                    }))
                  : undefined,
        }));
}
