import { api, type Page } from './api';

export type Stage = {
  id: number;
  name: string;
  weight: number;
  percent_complete: number;
  contract_value?: number | null;
  billed_amount?: number | null;
};

export type Subcontractor = {
  id: number;
  name: string;
  trade?: string | null;
  contact_name?: string | null;
  contact_phone?: string | null;
  contract_value?: number | null;
  billed_amount?: number | null;
  project_stage_id?: number | null;
  start_date?: string | null;
  end_date?: string | null;
  has_document?: boolean;
  document_name?: string | null;
  status: string;
  removed_on?: string | null;
  removed_reason?: string | null;
};

export type Milestone = {
  id: number;
  name: string;
  trigger_percent: number;
  amount_percent?: number | null;
  amount?: number | null;
  status: string;
};

export type ProjectEvent = { id: number; type: string; label: string; event_date: string; notes?: string | null };

export type Project = {
  id: number;
  name: string;
  code: string;
  client?: string | null;
  main_contractor?: string | null;
  location?: string | null;
  description?: string | null;
  project_manager_id?: number | null;
  project_manager?: { id: number; full_name: string } | null;
  start_date?: string | null;
  end_date?: string | null;
  status: string;
  progress_percent: number;
  expected_percent?: number | null;
  behind_schedule?: boolean;
  created_by_name?: string | null;
  can_edit?: boolean;
  can_coordinate?: boolean;
  can_reassign?: boolean;
  can_see_money?: boolean;
  sites_count?: number;
  stages?: Stage[];
  subcontractors?: Subcontractor[];
  team?: { employee_id: number; name: string; role: string }[];
  payment_milestones?: Milestone[];
  documents?: { id: number; title: string; category: string; original_name?: string | null }[];
  events?: ProjectEvent[];
  upcoming_events?: ProjectEvent[];
};

export const PROJECT_STATUSES: [string, string][] = [
  ['planned', 'Planned'],
  ['active', 'Ongoing'],
  ['handing_over', 'Handing over'],
  ['completed', 'Completed'],
  ['on_hold', 'On hold'],
  ['cancelled', 'Cancelled'],
];

export const statusName = (s: string) => PROJECT_STATUSES.find(([v]) => v === s)?.[1] ?? s;

export const EVENT_TYPES: [string, string][] = [
  ['dcd_inspection', 'DCD inspection'],
  ['civil_defence', 'Civil Defence inspection'],
  ['handover', 'Handover'],
  ['other', 'Other'],
];

export const DOC_CATEGORIES: [string, string][] = [
  ['drawing', 'Drawing'],
  ['contract', 'Contract'],
  ['permit', 'Permit'],
  ['other', 'Other'],
];

export const TEAM_ROLES: [string, string][] = [
  ['site_engineer', 'Site engineer'],
  ['site_supervisor', 'Site supervisor'],
  ['coordinator', 'Coordinator'],
];

/** `12.5` → `12.5%`. */
export function pct(v: number | null | undefined): string {
  if (v === null || v === undefined || Number.isNaN(Number(v))) return '—';
  const n = Number(v);
  return `${Number.isInteger(n) ? n : n.toFixed(1)}%`;
}

/** `1250000` → `AED 1,250,000`. */
export function money(v: number | null | undefined): string {
  if (v === null || v === undefined) return '—';
  return `AED ${Math.round(Number(v)).toLocaleString('en-US')}`;
}

export const getProject = (id: number | string) => api.get<Project>(`/projects/${id}`);

export function listProjects(query: Record<string, string | number | undefined>) {
  return api.get<Page<Project>>('/projects', { per_page: 50, ...query });
}

export type Person = { id: number; name: string; role?: string | null };

export const teamCandidates = () =>
  api.get<{ items: Person[] }>('/project-team-candidates').then((d) => d.items);

export const managerCandidates = () =>
  api.get<{ items: Person[] }>('/project-manager-candidates').then((d) => d.items);
