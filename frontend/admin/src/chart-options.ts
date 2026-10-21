import type { EChartsCoreOption } from "echarts/core";
import type { Row } from "./types";

export const originalChartColors = [
    "#5470c6",
    "#91cc75",
    "#fac858",
    "#ee6666",
    "#73c0de",
];
const axis = {
    axisLine: { lineStyle: { color: "#6E7079" } },
    axisLabel: { color: "#6E7079", fontSize: 12 },
    splitLine: { lineStyle: { color: "#E0E6F1" } },
};

export function incomeOptions(points: Row[]): EChartsCoreOption {
    const dates = Array.from(new Set(points.map((p) => String(p.date)))),
        names = Array.from(new Set(points.map((p) => String(p.type))));
    return {
        animation: false,
        color: originalChartColors,
        textStyle: { fontFamily: "Arial, sans-serif", fontSize: 12 },
        tooltip: { trigger: "axis", renderMode: "richText" },
        legend: {
            data: names,
            left: 0,
            top: 0,
            itemGap: 10,
            textStyle: { fontSize: 12, color: "#333" },
        },
        grid: {
            top: 60,
            left: "1%",
            right: "1%",
            bottom: "3%",
            containLabel: true,
        },
        xAxis: {
            ...axis,
            type: "category",
            boundaryGap: false,
            data: dates,
            axisTick: { alignWithLabel: true },
            splitLine: { show: false },
        },
        yAxis: {
            ...axis,
            type: "value",
            splitNumber: 5,
            axisLine: { show: false },
            axisTick: { show: false },
        },
        series: names.map((name) => ({
            name,
            type: "line",
            smooth: true,
            symbol: "emptyCircle",
            symbolSize: 4,
            lineStyle: { width: 2 },
            data: dates.map((date) => {
                const point = points.find(
                    (p) => String(p.date) === date && p.type === name,
                );
                return point ? Number(point.value) : null;
            }),
        })),
    };
}

export function rankingOptions(rows: Row[]): EChartsCoreOption {
    const ordered = [...rows].reverse();
    return {
        animation: false,
        color: [originalChartColors[0]],
        textStyle: { fontFamily: "Arial, sans-serif", fontSize: 12 },
        tooltip: {
            trigger: "axis",
            renderMode: "richText",
            valueFormatter: (v: any) => `${v} GB`,
        },
        grid: {
            top: "1%",
            left: "1%",
            right: "1%",
            bottom: "3%",
            containLabel: true,
        },
        xAxis: {
            ...axis,
            type: "value",
            axisLine: { show: false },
            axisTick: { show: false },
        },
        yAxis: {
            ...axis,
            type: "category",
            data: ordered.map((row) =>
                String(row.server_name ?? row.email ?? row.user_id ?? ""),
            ),
            axisLabel: { ...axis.axisLabel, width: 220, overflow: "truncate" },
            splitLine: { show: false },
        },
        series: [
            {
                type: "bar",
                data: ordered.map((row) => Number(row.total ?? 0)),
                barMaxWidth: 18,
            },
        ],
    };
}
