import { api } from "./api";

export const passkeyAvailable = () =>
    window.isSecureContext && !!window.PublicKeyCredential;
const encode = (value: ArrayBuffer) =>
    btoa(String.fromCharCode(...new Uint8Array(value)))
        .replace(/\+/g, "-")
        .replace(/\//g, "_")
        .replace(/=+$/, "");
const decode = (value: string) =>
    Uint8Array.from(atob(value.replace(/-/g, "+").replace(/_/g, "/")), (c) =>
        c.charCodeAt(0),
    );
export const passkeyNonce = () =>
    encode(crypto.getRandomValues(new Uint8Array(32)).buffer);
const endpoint = (path: string) => "/api/v1/user/passkey/" + path;
export async function credential(options: any, create = false) {
    if (!passkeyAvailable())
        throw new Error("Passkey 需要 HTTPS 和支持 WebAuthn 的浏览器");
    const publicKey = { ...options, challenge: decode(options.challenge) };
    const rpId = create ? options.rp?.id : options.rpId;
    const host = window.location.hostname.toLowerCase();
    const needsRor =
        typeof rpId === "string" && host !== rpId && !host.endsWith("." + rpId);
    if (needsRor) {
        const ctor = window.PublicKeyCredential as any;
        let capabilities: any;
        try {
            capabilities = await ctor.getClientCapabilities?.();
        } catch {
            /* Older clients may not expose capability detection. */
        }
        if (capabilities?.relatedOrigins === false)
            throw new Error(
                "当前浏览器不支持关联域 Passkey，请使用密码登录或更换浏览器",
            );
    }
    if (create)
        publicKey.user = { ...options.user, id: decode(options.user.id) };
    for (const key of ["allowCredentials", "excludeCredentials"]) {
        if (options[key])
            publicKey[key] = options[key].map((entry: any) => ({
                ...entry,
                id: decode(entry.id),
            }));
    }
    let result: PublicKeyCredential | null;
    try {
        result = (await (create
            ? navigator.credentials.create({ publicKey })
            : navigator.credentials.get({
                  publicKey,
              }))) as PublicKeyCredential | null;
    } catch (error: any) {
        if (needsRor && error?.name === "SecurityError")
            throw new Error(
                "关联域 Passkey 验证不可用，请检查浏览器支持及 RP 域名的 /.well-known/webauthn 声明，或使用密码登录",
            );
        throw error;
    }
    if (!result) throw new Error("Passkey 操作已取消");
    const response: any = result.response;
    const serialized: any = { clientDataJSON: encode(response.clientDataJSON) };
    for (const key of [
        "attestationObject",
        "authenticatorData",
        "signature",
        "userHandle",
    ]) {
        if (response[key]) serialized[key] = encode(response[key]);
    }
    return {
        id: result.id,
        rawId: encode(result.rawId),
        type: result.type,
        response: serialized,
        ...(response.getTransports
            ? {
                  transports: response
                      .getTransports()
                      .filter((t: string) =>
                          ["usb", "nfc", "ble", "internal", "hybrid"].includes(
                              t,
                          ),
                      ),
              }
            : {}),
    };
}
export async function passkeyLogin() {
    const client_nonce = passkeyNonce();
    const options = (
        await api(
            "/api/v1/passport/passkey/login/options",
            { client_nonce, audience: "admin" },
            "POST",
        )
    ).data;
    return (
        await api(
            "/api/v1/passport/passkey/login/verify",
            {
                client_nonce,
                transaction_id: options.transaction_id,
                credential: await credential(options.publicKey),
            },
            "POST",
        )
    ).data;
}
export async function passkeyGrant(
    action: string,
    target_id?: number,
    password?: string,
) {
    if (password !== undefined)
        return (
            await api(
                endpoint("reauth/password"),
                { action, target_id, password },
                "POST",
            )
        ).data.reauth_token;
    const client_nonce = passkeyNonce();
    const options = (
        await api(
            endpoint("reauth/options"),
            { action, target_id, client_nonce },
            "POST",
        )
    ).data;
    return (
        await api(
            endpoint("reauth/verify"),
            {
                client_nonce,
                transaction_id: options.transaction_id,
                credential: await credential(options.publicKey),
            },
            "POST",
        )
    ).data.reauth_token;
}
export async function passkeyRegister(name: string, reauth_token: string) {
    const client_nonce = passkeyNonce();
    const options = (
        await api(
            endpoint("register/options"),
            { client_nonce, reauth_token },
            "POST",
        )
    ).data;
    return api(
        endpoint("register/verify"),
        {
            client_nonce,
            name,
            transaction_id: options.transaction_id,
            credential: await credential(options.publicKey, true),
        },
        "POST",
    );
}
