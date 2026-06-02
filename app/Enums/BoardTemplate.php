<?php

namespace App\Enums;

/**
 * Preset column layouts that can be applied at board-creation time. The
 * client picks one from a dropdown; the backend materialises it into real
 * TaskColumn rows.
 *
 * Adding a new template? Keep this enum and `columns()` in lock-step with
 * the OpenAPI spec so the desktop client can offer the same list.
 */
enum BoardTemplate: string
{
    case Simple = 'simple';
    case SoftwareDevelopment = 'software_development';
    case BugTracking = 'bug_tracking';
    case ContentCreation = 'content_creation';
    case MarketingCampaign = 'marketing_campaign';
    case SalesPipeline = 'sales_pipeline';
    case Recruitment = 'recruitment';
    case ProductRoadmap = 'product_roadmap';
    case FinanceTracking = 'finance_tracking';
    case StudyPlanner = 'study_planner';

    /**
     * Column names for this template, in the order they should appear on
     * the board. Positions are materialised by the caller (multiples of
     * 10_000 so drag-reordering has room to breathe).
     *
     * @return array<int, string>
     */
    public function columns(): array
    {
        return match ($this) {
            self::Simple => ['To do', 'In progress', 'Done'],
            self::SoftwareDevelopment => ['Ideas', 'To do', 'In development', 'Review', 'Testing', 'Done'],
            self::BugTracking => ['Reported', 'Confirmed', 'Fixing', 'QA testing', 'Resolved'],
            self::ContentCreation => ['Ideas', 'Drafting', 'Editing', 'Scheduled', 'Published'],
            self::MarketingCampaign => ['Planned', 'In progress', 'Waiting approval', 'Launched', 'Analysed'],
            self::SalesPipeline => ['New lead', 'Contacted', 'Proposal sent', 'Negotiation', 'Won / Lost'],
            self::Recruitment => ['Applicants', 'Screening', 'Interview', 'Final review', 'Hired'],
            self::ProductRoadmap => ['Ideas', 'Prioritised', 'Designing', 'Building', 'Released'],
            self::FinanceTracking => ['Incoming', 'Approved', 'Paid', 'Logged', 'Archived'],
            self::StudyPlanner => ['To study', 'Studying', 'Revision', 'Completed'],
        };
    }
}
