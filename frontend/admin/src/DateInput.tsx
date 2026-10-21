import { DatePicker } from "antd";
import dayjs from "dayjs";

export default function DateInput({
    value,
    onChange,
    id,
    placeholder = "请选择日期时间",
    "aria-label": label,
}: {
    value?: string | null;
    onChange?: (value: string | null) => void;
    id?: string;
    placeholder?: string;
    "aria-label"?: string;
}) {
    const date = value ? dayjs(value) : null;
    return (
        <DatePicker
            id={id}
            aria-label={label}
            style={{ width: "100%" }}
            showTime
            format="YYYY-MM-DD HH:mm:ss"
            placeholder={placeholder}
            value={date?.isValid() ? date : null}
            onChange={(date) =>
                onChange?.(date?.format("YYYY-MM-DDTHH:mm:ss") ?? null)
            }
        />
    );
}
