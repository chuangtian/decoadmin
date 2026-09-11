import type { Directive } from 'vue';
import { readableChartFontSize } from '../utils/typography';

type ChartState = { schedule: () => void; dispose: () => void };
const charts = new WeakMap<SVGSVGElement, ChartState>();

const readableChart: Directive<SVGSVGElement> = {
    mounted(svg) {
        const original = new WeakMap<SVGTextElement, { value: string; priority: string }>();
        let frame = 0;
        const measure = () => {
            frame = 0;
            const labels = [...svg.querySelectorAll<SVGTextElement>('text')];
            for (const text of labels) {
                if (!original.has(text)) original.set(text, { value: text.style.getPropertyValue('font-size'), priority: text.style.getPropertyPriority('font-size') });
                const style = original.get(text)!;
                if (style.value) text.style.setProperty('font-size', style.value, style.priority);
                else text.style.removeProperty('font-size');
            }
            // Separate writes and reads so a chart with many ticks needs one layout pass.
            const sizes = labels.map((text) => {
                const matrix = text.getScreenCTM();
                if (!matrix || !text.getClientRects().length) return null;
                const scale = Math.hypot(matrix.c, matrix.d);
                const size = parseFloat(getComputedStyle(text).fontSize);
                return scale > 0 && size > 0 ? readableChartFontSize(size, scale) : null;
            });
            for (const [index, text] of labels.entries()) {
                if (sizes[index] !== null) text.style.fontSize = `${sizes[index]}px`;
            }
        };
        const schedule = () => {
            if (!frame) frame = requestAnimationFrame(measure);
        };
        const observer = new ResizeObserver(schedule);
        observer.observe(svg);
        charts.set(svg, {
            schedule,
            dispose() {
                observer.disconnect();
                if (frame) cancelAnimationFrame(frame);
            },
        });
        schedule();
    },
    updated(svg) { charts.get(svg)?.schedule(); },
    unmounted(svg) {
        charts.get(svg)?.dispose();
        charts.delete(svg);
    },
};

export default readableChart;
