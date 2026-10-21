import { cp, mkdir, readFile, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { createHash } from "node:crypto";
const root = resolve(import.meta.dirname, ".."),
    dist = resolve(root, "dist"),
    target = resolve(root, "../../public/assets/admin-v2");
const manifest = JSON.parse(
    await readFile(resolve(dist, "manifest.json"), "utf8"),
);
const entry = manifest["index.html"];
if (!entry?.file) throw new Error("Build the frontend before publishing.");
const code = await readFile(resolve(dist, entry.file));
await mkdir(target, { recursive: true });
await cp(dist, target, { recursive: true });
await writeFile(
    resolve(target, "build-info.json"),
    JSON.stringify(
        {
            version: JSON.parse(
                await readFile(resolve(root, "package.json"), "utf8"),
            ).version,
            kind: "production",
            entry: entry.file,
            entrySha256: createHash("sha256").update(code).digest("hex"),
            apiVersion: "v1",
            legacyRuntime: false,
        },
        null,
        2,
    ),
);
console.log(
    JSON.stringify({ target, entry: entry.file, legacyRuntime: false }),
);
