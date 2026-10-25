import {
    cp,
    mkdir,
    readFile,
    writeFile,
    stat,
    rm,
    realpath,
} from "node:fs/promises";
import { resolve, sep, dirname } from "node:path";
import { execFileSync } from "node:child_process";
import { runtimeFiles } from "./runtime-files.mjs";
const source = resolve(import.meta.dirname, "../../.."),
    target = resolve(process.argv[2] ?? "");
if (!process.argv[2] || target === source || target.startsWith(source + sep))
    throw new Error("Specify a separate deployment repository checkout.");
const git = (cwd, ...args) =>
    execFileSync(
        "git",
        ["-c", `safe.directory=${cwd.replaceAll("\\", "/")}`, ...args],
        {
            cwd,
            encoding: "utf8",
        },
    );
if (resolve(git(target, "rev-parse", "--show-toplevel").trim()) !== target)
    throw new Error("Destination must be the root of a Git checkout.");
const roots = new Set([
    "app",
    "bootstrap",
    "config",
    "database",
    "docs",
    "library",
    "public",
    "resources",
    "routes",
    "storage",
]);
const rootFiles = new Set([
    "artisan",
    "composer.json",
    "composer.lock",
    ".env.example",
    "server.php",
    "webman.php",
    "start.php",
    "cli-php.ini",
    "pm2.yaml",
    "LICENSE",
    "readme.md",
    "init.sh",
    "update.sh",
    ".gitignore",
    ".gitattributes",
]);
const files = git(
    source,
    "ls-files",
    "--cached",
    "--others",
    "--exclude-standard",
    "-z",
)
    .split("\0")
    .filter(Boolean);
const manifest = JSON.parse(
    await readFile(
        resolve(source, "public/assets/admin-v2/manifest.json"),
        "utf8",
    ),
);
const adminRuntimeFiles = runtimeFiles(manifest);
if (
    !manifest["index.html"]?.file ||
    !(
        await stat(
            resolve(
                source,
                "public/assets/admin-v2",
                manifest["index.html"].file,
            ),
        )
    ).isFile()
)
    throw new Error("Build and publish the admin frontend before exporting.");
// These paths belong entirely to generated admin releases. Replace them only
// after source preflight, so an upgrade cannot keep an old runtime alive.
const actualTarget = await realpath(target);
const managedPaths = await Promise.all(
    [
        "public/assets/admin",
        "public/assets/admin-v2",
        "resources/views/admin.blade.php",
    ].map(async (managed) => {
        const output = resolve(target, managed);
        const actual = await realpath(output).catch(() => null);
        if (
            !output.startsWith(target + sep) ||
            (actual && !actual.startsWith(actualTarget + sep))
        )
            throw new Error("Managed asset path escapes deployment checkout.");
        return output;
    }),
);
for (const output of managedPaths)
    await rm(output, { recursive: true, force: true });
const published = new Set();
for (const file of new Set(files)) {
    if (!roots.has(file.split("/")[0]) && !rootFiles.has(file)) continue;
    if (file.startsWith("storage/") && !file.endsWith(".gitignore")) continue;
    if (file.startsWith("public/assets/admin/")) continue;
    if (file.startsWith("public/assets/admin-v2/") && !adminRuntimeFiles.has(file.slice("public/assets/admin-v2/".length))) continue;
    const input = resolve(source, file),
        output = resolve(target, file);
    if (!input.startsWith(source + sep) || !output.startsWith(target + sep))
        throw new Error("Invalid deployment path");
    if (!(await stat(input).catch(() => null))?.isFile()) continue;
    await mkdir(dirname(output), { recursive: true });
    await cp(input, output);
    published.add(file);
}
// Remove obsolete versioned runtime files, while preserving instance configuration
// and untracked custom files in an existing deployment checkout.
for (const file of git(target, "ls-files", "-z").split("\0").filter(Boolean)) {
    if (published.has(file) || file === ".env" || file === "config/v2board.php") continue;
    if (!roots.has(file.split("/")[0]) && !rootFiles.has(file)) continue;
    const output = resolve(target, file);
    const actual = await realpath(output).catch(() => null);
    if (!output.startsWith(target + sep) || (actual && !actual.startsWith(actualTarget + sep)))
        throw new Error("Obsolete runtime path escapes deployment checkout.");
    await rm(output, { force: true });
}
await writeFile(
    resolve(target, "release.json"),
    JSON.stringify(
        {
            source_commit: git(source, "rev-parse", "HEAD").trim(),
            frontend_version: JSON.parse(
                await readFile(
                    resolve(source, "frontend/admin/package.json"),
                    "utf8",
                ),
            ).version,
            frontend_source_included: false,
            production_assets: "public/assets/admin-v2",
        },
        null,
        2,
    ),
);
console.log(
    "Exported runtime files and compiled admin assets; frontend source excluded.",
);
