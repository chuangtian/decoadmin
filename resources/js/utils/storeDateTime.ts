export const safeTimezone = (timezone?: string | null): string => {
    if (!timezone) return 'UTC';
    try {
        new Intl.DateTimeFormat('en', { timeZone: timezone }).format();
        return timezone;
    } catch {
        return 'UTC';
    }
};

const parseDate = (value: string | Date | null | undefined): Date | null => {
    if (!value) return null;
    const date = value instanceof Date ? value : new Date(value);
    return Number.isNaN(date.getTime()) ? null : date;
};

const partsInTimezone = (date: Date, timezone: string) => Object.fromEntries(
    new Intl.DateTimeFormat('zh-CN', {
        timeZone: safeTimezone(timezone), year: 'numeric', month: '2-digit', day: '2-digit',
        weekday: 'short', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
    }).formatToParts(date).map(part => [part.type, part.value]),
);

export const formatStoreDateTimeInput = (value: string | Date | null | undefined, timezone: string): string => {
    const date = parseDate(value);
    if (!date) return '';
    const parts = partsInTimezone(date, timezone);
    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
};

export const formatStoreDateTime = (value: string | Date | null | undefined, timezone: string): string => {
    const local = formatStoreDateTimeInput(value, timezone);
    return local ? local.replace('T', ' ') : '—';
};

export const formatStoreClock = (date: Date, timezone: string): string => {
    const parts = partsInTimezone(date, timezone);
    return `${Number(parts.month)}月${Number(parts.day)}日 ${parts.weekday} ${parts.hour}:${parts.minute}:${parts.second}`;
};

// Advance the server-provided instant without depending on the computer's wall clock.
export const serverTimeAfter = (serverTime: string | null | undefined, elapsedMs: number): Date => {
    const timestamp = serverTime ? Date.parse(serverTime) : NaN;
    return new Date(Number.isFinite(timestamp) ? timestamp + Math.max(0, elapsedMs) : Date.now());
};
