import "./assets/icons/icons.css";

export default function OriginalIcon({
    name,
    className = "",
}: {
    name: string;
    className?: string;
}) {
    return (
        <i
            aria-hidden="true"
            className={`original-icon ${name.startsWith("si-") ? "si" : "fa"} ${name} ${className}`}
        />
    );
}
