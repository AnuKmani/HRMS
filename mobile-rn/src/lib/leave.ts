import { api, type Page } from './api';

export type LeaveBalance = {
  id: number;
  leave_type_id: number;
  leave_type?: { name: string; code: string } | null;
  entitlement: number;
  carry_forward: number;
  adjustment: number;
  used: number;
  pending: number;
  remaining: number;
  unlimited?: boolean;
};

export type LeaveType = {
  id: number;
  name: string;
  code: string;
  entitlement_days: number;
  is_unlimited?: boolean;
  is_paid: boolean;
  requires_document: boolean;
  document_deadline_days: number;
  maximum_days_per_request: number | null;
  status: string;
};

export type ApprovalStep = {
  id: number;
  sequence: number;
  name: string;
  approver_type: string;
  approver_role?: string | null;
  status: string;
  remarks?: string | null;
  is_current?: boolean;
  approver_employee_id?: number | null;
  resolved_approver?: { full_name?: string } | null;
  actor?: { name?: string } | null;
};

export type LeaveRequest = {
  id: number;
  employee_id: number;
  employee?: { full_name?: string } | null;
  leave_type_id: number;
  leave_type?: { name: string; code: string } | null;
  start_date: string;
  end_date: string;
  requested_days: number;
  reason?: string | null;
  status: string;
  remarks?: string | null;
  over_balance_days?: number;
  available_days_at_submit?: number | null;
  excess_decision?: string | null;
  lop?: { days: number; reason?: string | null } | null;
  approval_chain?: ApprovalStep[];
};

export const statusLabel: Record<string, string> = {
  draft: 'Draft',
  pending: 'Waiting for approval',
  approved: 'Approved',
  rejected: 'Rejected',
  cancelled: 'Cancelled',
  lop: 'Loss of pay',
};

export const statusTone = (status: string) =>
  status === 'approved' ? 'success' : status === 'rejected' || status === 'lop' ? 'danger' : status === 'pending' ? 'warning' : 'neutral';

export function myBalances(employeeId: number) {
  return api
    .get<Page<LeaveBalance>>('/leave-balances', { employee_id: employeeId, year: new Date().getFullYear(), per_page: 50 })
    .then((p) => p.items);
}

export function leaveTypes() {
  return api.get<Page<LeaveType>>('/leave-types', { per_page: 100, status: 'active' }).then((p) => p.items);
}

/** Whoever the current step is waiting on, in words. */
export function stepApprover(step: ApprovalStep): string {
  return (
    step.resolved_approver?.full_name ||
    step.approver_role ||
    (step.approver_type === 'project_manager' ? 'Your manager' : 'Your reporting manager')
  );
}
