import MarkdownIt from "markdown-it";
import DOMPurify from "dompurify";

const parser = new MarkdownIt({ html: true, linkify: true, typographer: true });

// Only the preview is sanitized. Existing Markdown/HTML is saved verbatim.
export function renderKnowledge(source: string): string {
    const fragment = DOMPurify.sanitize(parser.render(source), {
        USE_PROFILES: { html: true },
        FORBID_TAGS: [
            "style",
            "link",
            "meta",
            "iframe",
            "object",
            "embed",
            "form",
        ],
        FORBID_ATTR: ["style"],
        RETURN_DOM_FRAGMENT: true,
    });
    fragment.querySelectorAll("a").forEach((link) => {
        link.setAttribute("target", "_blank");
        link.setAttribute("rel", "noopener noreferrer");
    });
    const container = document.createElement("div");
    container.append(fragment);
    return container.innerHTML;
}
