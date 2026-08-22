export type CampaignReviewJudgment = 'reusable' | 'scalable' | 'underperforming' | 'insufficient_data';

export interface CampaignReviewActivityOption {
    id: number;
    name: string;
    starts_on: string | null;
    ends_on: string | null;
}

export interface CampaignReviewMetricValue {
    value: number | null;
    comparison_value: number | null;
    change_percent: number | null;
}

interface ReportState {
    available: boolean;
    pending: boolean;
    stale: boolean;
    message: string | null;
}

export interface CampaignThemeReview {
    schema: 'campaign-theme-review-v1';
    activities: CampaignReviewActivityOption[];
    selected_activity_id: number | null;
    comparison_activity_id: number | null;
    activity: null | CampaignReviewActivityOption & {
        campaign_id: string | null;
        judgment: CampaignReviewJudgment;
        main_title: string | null;
        subtitle: string | null;
        core_offer: string | null;
        planning_document_url: string | null;
        planning_document_snapshot: null | { available: boolean; title: string | null; source_url: string | null };
        campaign_images: string[];
        email_images: string[];
        metrics: Record<string, CampaignReviewMetricValue>;
    };
    comparison_activity: CampaignReviewActivityOption | null;
    daily_sales: ReportState & {
        ad_spend_available: boolean;
        ad_spend_message: string | null;
        ad_spend_reconciled: boolean;
        ad_spend_coverage_percent: number | null;
        reported_ad_spend_total: number | null;
        campaign_ad_spend_total: number | null;
        date_from: string | null;
        date_to: string | null;
        points: Array<{
            date: string;
            total_sales: number;
            ad_spend: number | null;
            roi: number | null;
            orders: number;
        }>;
    };
    traffic_cost_trend: ReportState & {
        ad_spend_available: boolean;
        ad_spend_message: string | null;
        ad_spend_reconciled: boolean;
        ad_spend_coverage_percent: number | null;
        date_from: string | null;
        date_to: string | null;
        date_order: 'descending';
        points: Array<{
            date: string;
            sessions: number;
            cart_additions: number;
            reached_checkout: number;
            ad_spend: number | null;
            cart_addition_cost: number | null;
            checkout_cost: number | null;
        }>;
    };
    funnel: ReportState & {
        comparison_available: boolean;
        stages: Array<{ key: string; label: string; sessions: number; rate_percent: number; comparison_rate_percent: number | null }>;
    };
    model_sales: ReportState & {
        comparison_available: boolean;
        total_units: number;
        models: Array<{ key: string; name: string; units: number; total_sales: number; share_percent: number; comparison_units: number; change_percent: number | null }>;
    };
}
