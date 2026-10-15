import { api } from './api';

export type BalanceRow = {
  id: number;
  leave_type: string;
  code: string;
  entitlement: number;
  adjustment: number;
  carry_forward: number;
  used: number;
  pending: number;
  remaining: number;
  unlimited: boolean;
};

export type OverviewRow = {
  employee_id: number;
  name: string;
  employee_code?: string | null;
  balances: BalanceRow[];
  requests: Record<string, number>;
};

export const overview = (year: number, search?: string) =>
  api.get<{ items: OverviewRow[] }>('/leave-overview', { year, search }).then((d) => d.items);

export const left = (b: BalanceRow) => (b.unlimited ? 'Unlimited' : `${Number(b.remaining)} left`);

export const REQUEST_LABELS: [string, string][] = [
  ['pending', 'pending'],
  ['approved', 'approved'],
  ['rejected', 'rejected'],
  ['cancelled', 'cancelled'],
  ['lop', 'loss of pay'],
];
