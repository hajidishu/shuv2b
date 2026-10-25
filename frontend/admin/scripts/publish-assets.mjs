import { cp, mkdir, readFile, writeFile, rm, realpath, stat } from "node:fs/promises";
import { resolve, dirname, sep } from "node:path";
import { createHash } from "node:crypto";
import { runtimeFiles } from "./runtime-files.mjs";
const root = resolve(import.meta.dirname, ".."),
    dist = resolve(root, "dist"),
    target = resolve(root, "../../public/assets/admin-v2");
const manifest = JSON.parse(
    await readFile(resolve(dist, "manifest.json"), "utf8"),
);
const entry = manifest["index.html"];
if (!entry?.file) throw new Error("Build the frontend before publishing.");
const files = runtimeFiles(manifest);
const actualDist = await realpath(dist);
for (const file of files) {
    if (file === "build-info.json") continue;
    const input = await realpath(resolve(dist, file));
    if (!input.startsWith(actualDist + sep) || !(await stat(input)).isFile()) throw new Error("Build output escapes the admin dist directory");
}
const code = await readFile(resolve(dist, entry.file));
const repository = await realpath(resolve(root, "../.."));
const actualParent = await realpath(dirname(target));
if (!actualParent.startsWith(repository + sep)) throw new Error("Admin asset directory escapes the repository");
const actualTarget = await realpath(target).catch(error => { if (error.code === "ENOENT") return null; throw error; });
if (actualTarget && actualTarget !== resolve(actualParent, "admin-v2")) throw new Error("Refusing to replace a linked admin asset directory");
// This directory is owned generated output. All replacement files have been
// validated before removing it; stale hashed chunks must not enter releases.
await rm(target, { recursive: true, force: true });
await mkdir(target, { recursive: true });
for (const file of files) {
    if (file === "build-info.json") continue;
    const output = resolve(target, file);
    await mkdir(dirname(output), { recursive: true });
    await cp(resolve(dist, file), output);
}
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
