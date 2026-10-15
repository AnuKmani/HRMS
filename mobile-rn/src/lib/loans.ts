export type Installment = { id?: number; sequence: number; due_date: string; amount: string | number; status: string };

export type Loan = {
  id: number;
  employee_id: number;
  employee?: { full_name?: string } | null;
  loan_type: string;
  type_label?: string;
  reference?: string | null;
  currency?: string;
  principal_amount: string | number;
  installment_amount: string | number;
  number_of_installments: number;
  outstanding_balance: string | number;
  repaid_amount: string | number;
  start_date: string;
  status: string;
  next_installment?: Installment | null;
  remarks?: string | null;
  installments?: Installment[];
  approval_chain?: any[];
};

export const LOAN_STATUS: Record<string, string> = {
  draft: 'Draft',
  pending: 'Waiting for approval',
  approved: 'Approved',
  active: 'Repaying',
  completed: 'Repaid',
  rejected: 'Rejected',
  cancelled: 'Cancelled',
};

export const loanTone = (s: string) => (s === 'active' || s === 'approved' || s === 'completed' ? 'success' : s === 'rejected' ? 'danger' : s === 'pending' ? 'warning' : 'neutral');
