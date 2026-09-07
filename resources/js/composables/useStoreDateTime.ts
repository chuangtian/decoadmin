import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { SharedProps } from '../types';
import { safeTimezone, serverTimeAfter } from '../utils/storeDateTime';

const parseDate = (value: string | Date | null | undefined): Date | null => {
    if (!value) return null;
    const date = value instanceof Date ? value : new Date(value);
    return Number.isNaN(date.getTime()) ? null : date;
};

export const dateKeyInTimezone = (date: Date, timezone: string): string => {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(date).map((part) => [part.type, part.value]));

    return `${parts.year}-${parts.month}-${parts.day}`;
};

export const todayDateKeyInTimezone = (timezone: string): string =>
    dateKeyInTimezone(new Date(), safeTimezone(timezone));

export const formatDateTimeInTimezone = (
    value: string | Date | null | undefined,
    timezone: string,
): string => {
    const date = parseDate(value);
    if (!date) return '—';

    return new Intl.DateTimeFormat('zh-CN', {
        timeZone: safeTimezone(timezone),
        dateStyle: 'medium',
        timeStyle: 'short',
        hourCycle: 'h23',
    }).format(date);
};

export const formatDateOnly = (value: string | null | undefined): string => {
    if (!value) return '—';
    const matched = value.match(/^(\d{4})-(\d{2})-(\d{2})/);
    return matched ? `${matched[1]}年${Number(matched[2])}月${Number(matched[3])}日` : value;
};

export const useStoreDateTime = () => {
    const page = usePage<SharedProps>();
    const timezone = computed(() => safeTimezone(page.props.currentStore?.timezone));
    const clockAnchor = computed(() => ({ serverTime: page.props.serverTime, receivedAt: performance.now() }));
    const now = () => serverTimeAfter(clockAnchor.value.serverTime, performance.now() - clockAnchor.value.receivedAt);

    const formatDateTime = (value: string | Date | null | undefined, overrideTimezone?: string | null): string =>
        formatDateTimeInTimezone(value, safeTimezone(overrideTimezone ?? timezone.value));

    const formatOrderDateTime = (value: string | Date | null | undefined): string => {
        const date = parseDate(value);
        if (!date) return '—';

        const zone = timezone.value;
        const now = new Date();
        const currentKey = dateKeyInTimezone(now, zone);
        const orderKey = dateKeyInTimezone(date, zone);
        const yesterdayKey = dateKeyInTimezone(new Date(now.getTime() - 86_400_000), zone);
        const time = new Intl.DateTimeFormat('zh-CN', {
            timeZone: zone,
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        }).format(date);

        if (orderKey === currentKey) return `今天 ${time}`;
        if (orderKey === yesterdayKey) return `昨天 ${time}`;

        return formatDateTime(date);
    };

    return { timezone, now, formatDateTime, formatOrderDateTime };
};
