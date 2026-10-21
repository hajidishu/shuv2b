import { encodeQuery } from "./domain.mjs";
import type { Row } from "./types";
const storageKey =
    "v2board-admin-v1:" +
    location.host +
    ":" +
    (window.settings?.secure_path ?? location.pathname);
export const session = {
    get: () => localStorage.getItem(storageKey),
    set: (token: string) => localStorage.setItem(storageKey, token),
    clear: () => localStorage.removeItem(storageKey),
};
export const adminBase = () =>
    "/api/v1/" +
    (window.settings?.secure_path ??
        location.pathname.split("/").filter(Boolean)[0] ??
        "admin");
export class ApiError extends Error {
    constructor(
        message: string,
        public status: number,
        public fields: Row = {},
    ) {
        super(message);
    }
}
export async function request(
    path: string,
    params: Row = {},
    method = "GET",
    raw = false,
    signal?: AbortSignal,
): Promise<any> {
    const url =
        (path.startsWith("/api/") ? path : adminBase() + path) +
        (method === "GET" && Object.keys(params).length
            ? "?" + encodeQuery(params)
            : "");
    const res = await fetch(url, {
        method,
        headers: {
            Accept: raw ? "*/*" : "application/json",
            ...(session.get() ? { Authorization: session.get()! } : {}),
            ...(method !== "GET" ? { "Content-Type": "application/json" } : {}),
        },
        ...(method !== "GET" ? { body: JSON.stringify(params) } : {}),
        signal,
    });
    const text = await res.text();
    let data: Row = {};
    try {
        data = JSON.parse(text);
    } catch {
        if (!raw || !res.ok)
            throw new ApiError("服务器返回异常，请检查服务状态", res.status);
    }
    if (!res.ok) {
        if (res.status === 401 || res.status === 403) {
            session.clear();
            window.dispatchEvent(new Event("admin-session-expired"));
        }
        throw new ApiError(
            data.message ?? "请求失败",
            res.status,
            data.errors ?? {},
        );
    }
    if (raw) return text;
    return data;
}
export const api = (path: string, params: Row = {}, method = "GET") =>
    request(path, params, method);
export function download(
    text: string,
    name: string,
    type = "text/csv;charset=utf-8",
) {
    const url = URL.createObjectURL(new Blob([text], { type }));
    const link = document.createElement("a");
    link.href = url;
    link.download = name;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}
export async function downloadApi(path: string, params: Row, name: string) {
    download(await request(path, params, "POST", true), name);
}
export async function copyText(text: string) {
    if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text);
        return;
    }
    const area = document.createElement("textarea");
    area.value = text;
    area.style.position = "fixed";
    area.style.opacity = "0";
    document.body.appendChild(area);
    area.select();
    try {
        if (!document.execCommand("copy"))
            throw new Error("复制失败，请手动复制");
    } finally {
        area.remove();
    }
}
