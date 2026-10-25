import { test } from "node:test";
import assert from "node:assert/strict";
import { liveTarget } from "./live-target.mjs";
const env = () => ({ ADMIN_LIVE_URL: "http://127.0.0.1:8080", ADMIN_LIVE_ALLOWED_ORIGINS: "http://127.0.0.1:8080", ADMIN_LIVE_PASSWORD: "fixture-only" });
test("live tests require explicit target credentials and an origin allowlist", () => {
    assert.throws(() => liveTarget({}), /Set ADMIN_LIVE/);
    const values = env(); delete values.ADMIN_LIVE_ALLOWED_ORIGINS;
    assert.throws(() => liveTarget(values), /Set ADMIN_LIVE/);
    assert.equal(liveTarget(env()), "http://127.0.0.1:8080");
});
test("live tests reject targets outside the authorized origin and URL credential tricks", () => {
    assert.throws(() => liveTarget({ ...env(), ADMIN_LIVE_URL: "https://other.example.test" }), /not in/);
    for (const url of ["http://user:fixture@127.0.0.1:8080", "http://127.0.0.1:8080/path", "http://127.0.0.1:8080/?x=1", "http://127.0.0.1:8080/#x"])
        assert.throws(() => liveTarget({ ...env(), ADMIN_LIVE_URL: url }), /origins/);
});
