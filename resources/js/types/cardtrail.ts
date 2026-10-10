export type LineStatus = 'todo' | 'done' | 'queried' | 'approved';
export type ReconciliationStatus = 'in_progress' | 'submitted' | 'approved';
export type PeriodStatus = 'draft' | 'published' | 'closed';

export interface ReconciliationSummary {
    id: number;
    status: ReconciliationStatus;
    submitted_at: string | null;
    lines: number;
    done: number;
    queried: number;
    overdue: boolean;
    period: { id: number; label: string; due_date: string; status: PeriodStatus };
}

export interface ReceiptItem {
    id: number;
    name: string;
    mime: string;
    size: number;
    is_image: boolean;
    is_pdf: boolean;
    url: string;
    created_at: string | null;
}
