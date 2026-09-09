export const MINIMUM_FONT_SIZE = 12;

/** SVG viewBox scaling must not shrink readable labels below 12 screen CSS pixels. */
export function readableChartFontSize(fontSize: number, scale: number): number {
    const safeSize = Number.isFinite(fontSize) && fontSize > 0 ? fontSize : MINIMUM_FONT_SIZE;
    const safeScale = Number.isFinite(scale) && scale > 0 ? scale : 1;
    return Math.max(safeSize, MINIMUM_FONT_SIZE / safeScale);
}

/** Keep zoom expressions at the top level, as required by map style expressions. */
export function readableMapTextSize(value: unknown): unknown {
    if (typeof value === 'number') return Math.max(MINIMUM_FONT_SIZE, value);
    if (Array.isArray(value)) {
        const expression = [...value];
        if (['interpolate', 'interpolate-hcl', 'interpolate-lab'].includes(expression[0])) {
            for (let i = 4; i < expression.length; i += 2) expression[i] = readableMapTextSize(expression[i]);
            return expression;
        }
        if (expression[0] === 'step') {
            expression[2] = readableMapTextSize(expression[2]);
            for (let i = 4; i < expression.length; i += 2) expression[i] = readableMapTextSize(expression[i]);
            return expression;
        }
        if (expression[0] === 'max' && expression.some((item, index) => index > 0 && typeof item === 'number' && item >= MINIMUM_FONT_SIZE)) return expression;
        return ['max', MINIMUM_FONT_SIZE, expression];
    }
    if (value && typeof value === 'object' && 'stops' in value && Array.isArray(value.stops)) {
        return { ...value, stops: value.stops.map(([zoom, size]: [unknown, unknown]) => [zoom, readableMapTextSize(size)]) };
    }
    return 16;
}
