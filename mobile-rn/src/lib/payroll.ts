export type PayrollItem = { id: number; type: string; code?: string | null; description: string; amount: string | number };

export type Payroll = {
  id: number;
  employee_id: number;
  employee?: { full_name?: string; employee_code?: string } | null;
  payroll_year: number;
  payroll_month: number;
  period_label: string;
  basic_salary: string | number;
  total_allowances: string | number;
  overtime_amount: string | number;
  overtime_minutes?: number | null;
  bonus_amount: string | number;
  gross_salary: string | number;
  lop_days: string | number;
  lop_amount: string | number;
  loan_deduction: string | number;
  advance_deduction: string | number;
  other_deductions: string | number;
  total_deductions: string | number;
  net_salary: string | number;
  status: string;
  slip_request_status?: string | null;
  can_download: boolean;
  blocked_reason?: string | null;
  can_recalculate?: boolean;
  can_lock?: boolean;
  currency?: string;
  items?: PayrollItem[];
};

export const PAYROLL_STATUS: Record<string, string> = {
  draft: 'Draft',
  calculated: 'Calculated',
  reviewed: 'Reviewed',
  processed: 'Finalised',
  locked: 'Locked',
};

export const payrollTone = (s: string) => (s === 'locked' || s === 'processed' ? 'success' : s === 'reviewed' ? 'info' : 'warning');
