export type Doc = {
  id: number;
  employee_id: number;
  employee?: { full_name?: string } | null;
  document_type?: { id: number; name: string } | null;
  document_number?: string | null;
  issue_date?: string | null;
  expiry_date?: string | null;
  notes?: string | null;
  has_file: boolean;
  original_name?: string | null;
  mime_type?: string | null;
  status: string;
  expiry_state: string;
  days_until_expiry?: number | null;
  rejection_reason?: string | null;
  is_editable?: boolean;
  is_pending?: boolean;
};

export const DOC_STATUS: Record<string, string> = { pending: 'Waiting to verify', valid: 'Verified', expired: 'Expired', rejected: 'Rejected', archived: 'Archived' };
export const docTone = (s: string) => (s === 'valid' ? 'success' : s === 'rejected' || s === 'expired' ? 'danger' : s === 'pending' ? 'warning' : 'neutral');

export function expiryText(d: Pick<Doc, 'expiry_state' | 'days_until_expiry'>): string | null {
  if (d.expiry_state === 'expired') return 'Expired';
  if (d.expiry_state === 'expiring_soon') return `Expires in ${d.days_until_expiry ?? '?'} day(s)`;
  return null;
}
