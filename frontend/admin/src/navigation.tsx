import type { ReactNode, MouseEvent } from "react";
import type { Filter } from "./types";

const filterFields: Record<string, string[]> = {
    "/user": ["id", "email", "invite_user_id"],
    "/order": ["trade_no", "user_id", "email"],
    "/subscribe-audit": ["user_id", "email", "ip", "ua"],
};

export function routeQuery(route: string) {
    const [path, query = ""] = location.hash.slice(1).split("?");
    return new URLSearchParams(path === route ? query : "");
}

export function managementHref(route: string, values: Record<string, any>) {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(values)) {
        if (value != null && value !== "") params.set(key, String(value));
    }
    const query = params.toString();
    return "#" + route + (query ? "?" + query : "");
}

export function initialManagementFilters(
    route: string,
    storageKey: string,
): Filter[] {
    let stored: Filter[] = [];
    try {
        const value = sessionStorage.getItem(storageKey);
        sessionStorage.removeItem(storageKey);
        if (value) {
            const parsed = JSON.parse(value);
            if (Array.isArray(parsed)) stored = parsed;
        }
    } catch {
        /* Older saved filters may be unavailable. */
    }
    const params = routeQuery(route);
    const linked = (filterFields[route] ?? []).flatMap((key) => {
        const value = params.get(key);
        return value ? [{ key, condition: "=", value }] : [];
    });
    return linked.length ? linked : stored;
}

export function ManagementLink({
    href,
    children,
    title,
}: {
    href: string;
    children: ReactNode;
    title?: string;
}) {
    const click = (event: MouseEvent<HTMLAnchorElement>) => {
        event.stopPropagation();
        if (
            !event.ctrlKey &&
            !event.metaKey &&
            !event.shiftKey &&
            !event.altKey &&
            event.button === 0 &&
            location.hash === href
        ) {
            event.preventDefault();
            window.dispatchEvent(new Event("admin-link-refresh"));
        }
    };
    return (
        <a href={href} onClick={click} title={title}>
            {children}
        </a>
    );
}

export function UserLink({
    userId,
    email,
    children,
}: {
    userId?: number | string | null;
    email?: string | null;
    children?: ReactNode;
}) {
    const label = children ?? email ?? (userId ? `用户 #${userId}` : "—");
    if (!userId && !email?.includes("@")) return <>{label}</>;
    return (
        <ManagementLink
            href={managementHref("/user", userId ? { id: userId } : { email })}
        >
            {label}
        </ManagementLink>
    );
}

export function OrderLink({
    tradeNo,
    children,
}: {
    tradeNo?: string | null;
    children?: ReactNode;
}) {
    if (!tradeNo) return <>{children ?? "—"}</>;
    return (
        <ManagementLink href={managementHref("/order", { trade_no: tradeNo })}>
            {children ?? tradeNo}
        </ManagementLink>
    );
}

export function NodeLink({
    nodeType,
    nodeId,
    children,
}: {
    nodeType?: string;
    nodeId?: number | string;
    children: ReactNode;
}) {
    if (!nodeType || !nodeId) return <>{children}</>;
    return (
        <ManagementLink
            href={managementHref("/server/manage", {
                type: nodeType,
                id: nodeId,
            })}
        >
            {children}
        </ManagementLink>
    );
}

export function ResourceLink({
    route,
    id,
    children,
}: {
    route: string;
    id?: number | string | null;
    children: ReactNode;
}) {
    return id ? (
        <ManagementLink href={managementHref(route, { id })}>
            {children}
        </ManagementLink>
    ) : (
        <>{children}</>
    );
}
