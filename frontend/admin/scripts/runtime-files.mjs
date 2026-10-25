import { isAbsolute } from "node:path";
export function runtimeFiles(manifest) {
    if (!manifest["index.html"]?.file) throw new Error("Admin runtime entry is missing");
    const files = new Set(["index.html", "manifest.json", "build-info.json"]);
    const add = value => {
        if (typeof value !== "string" || !value || isAbsolute(value) || /[\\\0%?#:]/.test(value) || value.split("/").some(part => !part || part === "." || part === ".."))
            throw new Error("Invalid admin runtime asset path");
        files.add(value);
    };
    for (const entry of Object.values(manifest)) {
        add(entry.file);
        for (const value of [...(entry.css ?? []), ...(entry.assets ?? [])]) add(value);
        for (const key of [...(entry.imports ?? []), ...(entry.dynamicImports ?? [])])
            if (!manifest[key]?.file) throw new Error("Admin runtime import is missing");
    }
    return files;
}
