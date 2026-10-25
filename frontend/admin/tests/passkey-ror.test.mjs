import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import vm from "node:vm";
import ts from "typescript";

function fixture({
    host = "associated.example.net",
    supported = true,
    error,
} = {}) {
    const calls = [];
    const source = readFileSync(
        new URL("../src/passkey.ts", import.meta.url),
        "utf8",
    );
    const code = ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
    const module = { exports: {} };
    const context = vm.createContext({
        module,
        exports: module.exports,
        require: () => ({
            api: () => {
                throw new Error("No network in fixture");
            },
        }),
        window: {
            isSecureContext: true,
            location: { hostname: host },
            PublicKeyCredential: {
                getClientCapabilities: async () => ({
                    relatedOrigins: supported,
                }),
            },
        },
        navigator: {
            credentials: {
                get: async (request) => {
                    calls.push(request);
                    if (error) throw error;
                    return {
                        id: "fixture",
                        rawId: new Uint8Array([1]).buffer,
                        type: "public-key",
                        response: {
                            clientDataJSON: new Uint8Array([2]).buffer,
                            signature: new Uint8Array([3]).buffer,
                        },
                    };
                },
            },
        },
        atob,
        btoa,
        Uint8Array,
        Error,
    });
    vm.runInContext(code, context);
    return { ...module.exports, calls };
}

test("admin related-origin assertion preserves the configured RP and signed bytes", async () => {
    const f = fixture();
    const result = await f.credential({
        rpId: "primary.example.com",
        challenge: "AQ",
    });
    assert.equal(f.calls[0].publicKey.rpId, "primary.example.com");
    assert.equal(result.response.clientDataJSON, "Ag");
    assert.equal(result.response.signature, "Aw");
});

test("admin unsupported related origins show a password fallback and do not call WebAuthn", async () => {
    const f = fixture({ supported: false });
    await assert.rejects(
        f.credential({ rpId: "primary.example.com", challenge: "AQ" }),
        /密码登录/,
    );
    assert.equal(f.calls.length, 0);
});

test("admin ROR security error is actionable and is never retried using another RP", async () => {
    const f = fixture({
        error: Object.assign(new Error("invalid related origin"), {
            name: "SecurityError",
        }),
    });
    await assert.rejects(
        f.credential({ rpId: "primary.example.com", challenge: "AQ" }),
        /关联域/,
    );
    assert.equal(f.calls.length, 1);
});

test("admin natural RP suffix remains usable without a related-origin capability", async () => {
    const f = fixture({ host: "sub.primary.example.com", supported: false });
    await f.credential({ rpId: "primary.example.com", challenge: "AQ" });
    assert.equal(f.calls.length, 1);
});
