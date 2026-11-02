import { useEffect, useState } from "react";
import { App, Button, Space } from "antd";
import { MenuOutlined } from "@ant-design/icons";
import type { Row } from "./types";
import type { HTMLAttributes } from "react";

export function useTableSort(
    data: unknown,
    persist: (rows: Row[]) => Promise<void>,
) {
    const [rows, setRows] = useState<Row[]>([]);
    const [editing, setEditing] = useState(false),
        [dirty, setDirty] = useState(false),
        [busy, setBusy] = useState(false);
    const [drag, setDrag] = useState<string>(),
        [over, setOver] = useState<string>();
    const { message } = App.useApp();
    useEffect(() => {
        setRows(Array.isArray(data) ? data : []);
        setDirty(false);
    }, [data]);
    const move = (from: string, to: string) => {
        if (busy) return;
        const list = [...rows],
            a = list.findIndex((row) => String(row.id) === from),
            b = list.findIndex((row) => String(row.id) === to);
        if (a < 0 || b < 0 || a === b) return;
        list.splice(b, 0, list.splice(a, 1)[0]);
        setRows(list);
        setDirty(true);
    };
    const save = async () => {
        setBusy(true);
        try {
            if (dirty) await persist(rows);
            setEditing(false);
            setDirty(false);
            message.success("排序已保存");
        } catch (error) {
            message.error((error as Error).message);
        } finally {
            setBusy(false);
        }
    };
    const cancel = () => {
        setRows(Array.isArray(data) ? data : []);
        setDirty(false);
        setEditing(false);
        setDrag(undefined);
        setOver(undefined);
    };
    const onRow = (row: Row): HTMLAttributes<HTMLTableRowElement> => ({
        className: over === String(row.id) ? "node-sort-drop-target" : "",
        onDragOver: (event) => {
            if (editing && drag && !busy) {
                event.preventDefault();
                event.dataTransfer.dropEffect = "move";
                setOver(String(row.id));
            }
        },
        onDrop: (event) => {
            if (!editing || !drag || busy) return;
            event.preventDefault();
            move(drag, String(row.id));
            setDrag(undefined);
            setOver(undefined);
        },
    });
    const column = {
        title: "排序",
        key: "drag-sort",
        width: 55,
        render: (_: unknown, row: Row) => (
            <span
                className="node-drag-handle"
                role="button"
                tabIndex={0}
                aria-label={`拖动排序 ${row.name ?? row.title ?? row.id}`}
                title="拖动排序，也可使用上下方向键"
                draggable={editing && !busy}
                onDragStart={(event) => {
                    event.dataTransfer.effectAllowed = "move";
                    event.dataTransfer.setData("text/plain", String(row.id));
                    setDrag(String(row.id));
                }}
                onDragEnd={() => {
                    setDrag(undefined);
                    setOver(undefined);
                }}
                onKeyDown={(event) => {
                    if (
                        !editing ||
                        busy ||
                        !["ArrowUp", "ArrowDown"].includes(event.key)
                    )
                        return;
                    event.preventDefault();
                    const next =
                        rows[
                            rows.findIndex((item) => item.id === row.id) +
                                (event.key === "ArrowUp" ? -1 : 1)
                        ];
                    if (next) move(String(row.id), String(next.id));
                }}
            >
                <MenuOutlined />
            </span>
        ),
    };
    return {
        rows,
        editing,
        dirty,
        busy,
        onRow,
        column,
        move,
        start: () => setEditing(true),
        save,
        cancel,
    };
}

export function SortButtons({
    sorter,
    disabled = false,
}: {
    sorter: ReturnType<typeof useTableSort>;
    disabled?: boolean;
}) {
    return (
        <Space>
            <Button
                aria-label={sorter.editing ? "保存排序" : "编辑排序"}
                disabled={disabled || sorter.busy}
                loading={sorter.busy}
                onClick={sorter.editing ? sorter.save : sorter.start}
            >
                {sorter.editing ? "保存排序" : "编辑排序"}
            </Button>
            {sorter.editing && (
                <Button disabled={sorter.busy} onClick={sorter.cancel}>
                    取消排序
                </Button>
            )}
        </Space>
    );
}
