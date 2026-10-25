// Integration tests mutate disposable fixture data. Require an explicitly
// configured origin and independent allowlist instead of embedding a machine.
export function liveTarget(env = process.env) {
    if (!env.ADMIN_LIVE_URL || !env.ADMIN_LIVE_PASSWORD || !env.ADMIN_LIVE_ALLOWED_ORIGINS)
        throw new Error("Set ADMIN_LIVE_URL, ADMIN_LIVE_ALLOWED_ORIGINS and ADMIN_LIVE_PASSWORD for an authorized disposable test instance");
    const parseOrigin = value => {
        const url = new URL(value);
        if (!["http:", "https:"].includes(url.protocol) || url.username || url.password || url.search || url.hash || url.pathname !== "/")
            throw new Error("Live test targets must be HTTP(S) origins without credentials, paths or query parameters");
        return url.origin;
    };
    const origin = parseOrigin(env.ADMIN_LIVE_URL);
    const allowed = env.ADMIN_LIVE_ALLOWED_ORIGINS.split(",").map(value => parseOrigin(value.trim()));
    if (!allowed.includes(origin)) throw new Error("The live test origin is not in ADMIN_LIVE_ALLOWED_ORIGINS");
    return origin;
}
