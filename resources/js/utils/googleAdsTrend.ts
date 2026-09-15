type GoogleAdsTrendDatum = {
    date: string;
    spend: number;
    conversion_value: number;
    roi: number;
};

export const googleTrendSalesLabel = '销售额（按转化日期）';

// Both Google charts must use the same conversion-date sales basis as ROI.
// Keep the source revenue field intact for the separate attribution columns.
export function createGoogleAdsTrendChart<T extends GoogleAdsTrendDatum>(data: readonly T[]) {
    const width = 760;
    const height = 330;
    const left = 128;
    const right = 112;
    const top = 72;
    const bottom = 52;
    const plotWidth = width - left - right;
    const plotHeight = height - top - bottom;
    const amountMax = Math.max(1, ...data.flatMap((point) => [point.spend, point.conversion_value])) * 1.12;
    const roiMax = Math.max(1, ...data.map((point) => point.roi)) * 1.15;
    const step = data.length ? plotWidth / data.length : plotWidth;
    const barWidth = Math.max(1.2, Math.min(18, step * 0.22));
    const labelEvery = Math.max(1, Math.ceil(data.length / 7));
    const points = data.map((point, index) => {
        const x = left + step * (index + 0.5);
        return {
            ...point,
            sales: point.conversion_value,
            index,
            x,
            spendY: top + plotHeight - (point.spend / amountMax) * plotHeight,
            salesY: top + plotHeight - (point.conversion_value / amountMax) * plotHeight,
            roiY: top + plotHeight - (point.roi / roiMax) * plotHeight,
            dateLabel: point.date.slice(5),
            showLabel: data.length <= 10 || index % labelEvery === 0 || index === data.length - 1,
        };
    });
    return {
        width, height, left, right, top, bottom, plotWidth, plotHeight, amountMax, roiMax, step, barWidth, points,
        linePoints: points.map((point) => `${point.x},${point.roiY}`).join(' '),
        ticks: [0, 1, 2, 3, 4].map((index) => ({
            y: top + plotHeight - (index / 4) * plotHeight,
            amount: amountMax * (index / 4),
            roi: roiMax * (index / 4),
        })),
    };
}
