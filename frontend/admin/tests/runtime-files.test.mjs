import { test } from "node:test";
import assert from "node:assert/strict";
import { runtimeFiles } from "../scripts/runtime-files.mjs";
const manifest = () => ({ "index.html": { file: "assets/index-current.js", imports: ["vendor"], css: ["assets/current.css"], assets: ["assets/icon.woff2"] }, vendor: { file: "assets/vendor-current.js" } });
test("publication includes current manifest dependencies and excludes stale bundles and local files", () => {
    const files = runtimeFiles(manifest());
    for (const file of ["assets/index-current.js", "assets/vendor-current.js", "assets/current.css", "assets/icon.woff2", "index.html", "manifest.json", "build-info.json"]) assert(files.has(file));
    for (const file of ["assets/index-old.js", ".env", "report.json", "debug.map"]) assert(!files.has(file));
});
test("publication rejects traversal, malformed asset paths and unresolved imports", () => {
    for (const file of ["../.env", "/outside.js", "assets/../../.env", "assets/%2e%2e/.env", "assets/%252e%252e/.env", "assets\\escape.js", "https://example.test/key"])
        assert.throws(() => runtimeFiles({ "index.html": { file } }), /asset path/);
    const value = manifest(); delete value.vendor;
    assert.throws(() => runtimeFiles(value), /import is missing/);
});
