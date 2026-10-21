export type Row = Record<string, any>;
export type Field = {
    key: string;
    label: string;
    kind?:
        | "text"
        | "password"
        | "number"
        | "money"
        | "gb"
        | "date"
        | "textarea"
        | "html"
        | "markdown"
        | "lines"
        | "select"
        | "tags"
        | "json"
        | "switch";
    required?: boolean;
    options?: Array<{ label: string; value: any }>;
    reference?: "plans" | "groups" | "routes" | "parents";
    multiple?: boolean;
    default?: any;
    help?: string;
    helpTooltip?: string;
    inputType?: "text" | "number";
    when?: (values: Row) => boolean;
    placeholder?: string;
    ariaLabel?: string;
    span?: number;
    rows?: number;
    addonBefore?: string | null;
    addonAfter?: string;
    config?: Field;
    drawerWidth?: string | number;
    configStyle?: boolean;
    inlineObfs?: boolean;
    jsonDefaults?: Row;
    labelAction?: { label: string; onClick: () => void };
    helpLink?: { label: string; href: string };
    min?: number;
    nested?: Array<{
        key: string;
        label: string;
        kind?: Field["kind"];
        options?: Field["options"];
        when?: (values: Row) => boolean;
    }>;
};
export type Filter = { key: string; condition: string; value: any };
declare global {
    interface Window {
        settings?: {
            title?: string;
            secure_path?: string;
            logo?: string;
            theme?: { sidebar?: string; header?: string; color?: string };
            asset_base?: string;
            version?: string;
            background_url?: string;
        };
    }
}
