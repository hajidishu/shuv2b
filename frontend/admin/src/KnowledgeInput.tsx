import { useEffect, useRef } from "react";
import MarkdownEditor from "react-markdown-editor-lite";
import "react-markdown-editor-lite/lib/index.css";
import { renderKnowledge } from "./knowledge-markdown";

MarkdownEditor.useLocale("zhCN");

export default function KnowledgeInput({
    value = "",
    onChange,
    id,
}: {
    value?: string;
    onChange?: (value: string) => void;
    id?: string;
}) {
    const host = useRef<HTMLDivElement>(null);
    useEffect(() => {
        const input = host.current?.querySelector("textarea");
        if (input) {
            input.id = id ?? "knowledge-body";
            input.setAttribute("aria-label", "内容");
        }
    }, [id]);
    return (
        <div ref={host} className="knowledge-input">
            <MarkdownEditor
                id="knowledge-markdown"
                value={value}
                style={{ height: 500 }}
                renderHTML={renderKnowledge}
                onChange={({ text }) => {
                    if (text !== value) onChange?.(text);
                }}
                config={{
                    view: { menu: true, md: true, html: true },
                    canView: {
                        menu: true,
                        md: true,
                        html: true,
                        both: true,
                        fullScreen: true,
                        hideMenu: true,
                    },
                    allowPasteImage: false,
                    onChangeTrigger: "beforeRender",
                }}
            />
        </div>
    );
}
