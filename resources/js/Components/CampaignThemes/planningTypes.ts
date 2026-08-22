import type { CampaignReviewJudgment } from './reviewTypes';

export type CampaignPlanningStatus = 'upcoming' | 'in_progress' | 'completed';

export interface CampaignPlanningActivity {
    id: number;
    campaign_id: string | null;
    name: string;
    main_title: string | null;
    core_offer: string | null;
    starts_on: string | null;
    ends_on: string | null;
    status: CampaignPlanningStatus;
    judgment: CampaignReviewJudgment;
    gmv: number | null;
    ad_spend: number | null;
    roi: number | null;
    orders: number | null;
    summary: string | null;
}

export interface CampaignThemePlanning {
    schema: 'campaign-theme-planning-v1';
    today: string;
    counts: {
        total: number;
        upcoming: number;
        in_progress: number;
        completed: number;
    };
    activities: CampaignPlanningActivity[];
}
