import { useEffect, useRef } from "react";
import {
    init,
    use,
    type EChartsCoreOption,
    type EChartsType,
} from "echarts/core";
import { LineChart, BarChart } from "echarts/charts";
import {
    GridComponent,
    TooltipComponent,
    LegendComponent,
} from "echarts/components";
import { SVGRenderer } from "echarts/renderers";
use([
    LineChart,
    BarChart,
    GridComponent,
    TooltipComponent,
    LegendComponent,
    SVGRenderer,
]);

export default function Chart({
    option,
    label,
    height = 368,
}: {
    option: EChartsCoreOption;
    label: string;
    height?: number;
}) {
    const host = useRef<HTMLDivElement>(null),
        instance = useRef<EChartsType>();
    useEffect(() => {
        const element = host.current;
        if (!element) return;
        const chart = init(element, undefined, { renderer: "svg" });
        instance.current = chart;
        const observer = new ResizeObserver(() => {
            if (!chart.isDisposed()) chart.resize();
        });
        observer.observe(element);
        element.dataset.chartReady = "true";
        return () => {
            observer.disconnect();
            chart.dispose();
            instance.current = undefined;
            delete element.dataset.chartReady;
        };
    }, []);
    useEffect(() => {
        instance.current?.setOption(option, { notMerge: true });
    }, [option]);
    return (
        <div
            ref={host}
            className="echarts-view"
            role="img"
            aria-label={label}
            style={{ height, width: "100%" }}
        />
    );
}
