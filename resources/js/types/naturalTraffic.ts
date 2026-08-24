export type NaturalTrafficKpi = {
    key: string;
    label: string;
    value: number;
    previous: number;
    change: number | null;
    format: 'number' | 'compact' | 'currency' | 'percent';
};

export type NaturalTrafficTab = { key: string; label: string };

export type NaturalTrafficSource = {
    ready: boolean;
    table_count: number;
    record_count: number;
    last_synced_at: string | null;
    storage: 'current-project-mysql';
};

export type NaturalTrafficFilters = {
    date_from: string;
    date_to: string;
    comparison: 'previous' | 'none';
    compare_from: string;
    compare_to: string;
    affiliate?: string;
};

export type NaturalTrafficDashboardBase = {
    schema: string;
    title: string;
    description: string;
    tabs: NaturalTrafficTab[];
    filters: NaturalTrafficFilters;
    source: NaturalTrafficSource;
    kpis: NaturalTrafficKpi[];
};
