export type Program = {
  id: number;
  training_type_id: number;
  training_type?: { id: number; name: string } | null;
  code: string;
  name: string;
  description?: string | null;
  provider?: string | null;
  duration_days?: number | null;
  certificate_required: boolean;
  certificate_validity_days?: number | null;
  status: string;
};

export type Enrolment = {
  id: number;
  employee_id: number;
  employee?: { full_name?: string } | null;
  training_program?: Program | null;
  enrollment_date: string;
  training_date?: string | null;
  completion_date?: string | null;
  trainer?: string | null;
  status: string;
  result?: string | null;
  remarks?: string | null;
  certificate_number?: string | null;
  certificate_issue_date?: string | null;
  certificate_expiry_date?: string | null;
  has_certificate: boolean;
  certificate_original_name?: string | null;
  certificate_mime_type?: string | null;
  certificate_expiry_state?: string | null;
  days_until_expiry?: number | null;
  is_terminal?: boolean;
  is_completable?: boolean;
};

export const TRAINING_STATUS: Record<string, string> = {
  enrolled: 'Enrolled',
  scheduled: 'Scheduled',
  in_progress: 'In progress',
  completed: 'Completed',
  failed: 'Failed',
  cancelled: 'Cancelled',
  expired: 'Certificate expired',
};

export const trainingTone = (s: string) => (s === 'completed' ? 'success' : s === 'failed' || s === 'expired' ? 'danger' : s === 'cancelled' ? 'neutral' : 'info');
