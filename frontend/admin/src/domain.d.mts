import type { Row } from "./types";
export const nodeTypes: Record<string, string>;
export const periods: Record<string, string>;
export const routes: string[];
export function cents(v: any): number | null;
export function yuan(v: any): string | null;
export function encodeQuery(
    v: Row,
    p?: string,
    q?: URLSearchParams,
): URLSearchParams;
export function nodeKey(n: Row): string;
export function batchPayload(n: Row[], a: string, c?: Row): Row;
export function escapeCsv(v: any): string;
export function referenceValue(value: any): any;
export function findReference<T extends Row>(rows: T[] | undefined, id: any): T | undefined;
export function isReferenceField(field: { key: string; kind?: string; reference?: string }): boolean;
