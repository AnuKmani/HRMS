import type { Option } from '../components/kit';
import { api, type Page } from './api';

export type EmployeeRow = {
  id: number;
  employee_code: string;
  full_name: string;
  designation?: { name?: string } | string | null;
  department?: { name?: string } | string | null;
  employment_status?: string;
};

/** Employees the signed-in user may see, as picker options. */
export async function employeeOptions(search?: string): Promise<Option[]> {
  const page = await api.get<Page<EmployeeRow>>('/employees', { per_page: 200, search, sort: 'first_name' });
  return page.items.map((e) => ({ value: e.id, label: e.full_name || e.employee_code, hint: e.employee_code }));
}

export async function siteOptions(): Promise<Option[]> {
  const page = await api.get<Page<{ id: number; name: string; code?: string; project?: { name?: string } | null }>>('/sites', { per_page: 200 });
  return page.items.map((s) => ({ value: s.id, label: s.name, hint: s.project?.name ?? s.code ?? null }));
}

export async function projectOptions(): Promise<Option[]> {
  const page = await api.get<Page<{ id: number; name: string; code: string }>>('/projects', { per_page: 200, sort: 'name' });
  return page.items.map((p) => ({ value: p.id, label: p.name, hint: p.code }));
}

export async function lookupOptions(path: '/departments' | '/designations'): Promise<Option[]> {
  const page = await api.get<Page<{ id: number; name: string }>>(path, { per_page: 200 });
  return page.items.map((d) => ({ value: d.id, label: d.name }));
}

export const EMPLOYMENT_TYPES: [string, string][] = [
  ['permanent', 'Permanent'],
  ['contract', 'Contract'],
  ['probation', 'Probation'],
  ['internship', 'Internship'],
  ['part_time', 'Part time'],
];

export const EMPLOYMENT_STATUSES: [string, string][] = [
  ['active', 'Active'],
  ['on_leave', 'On leave'],
  ['inactive', 'Inactive'],
  ['resigned', 'Resigned'],
  ['terminated', 'Terminated'],
];

export const label = (pairs: [string, string][], v?: string | null) => pairs.find(([k]) => k === v)?.[1] ?? v ?? '—';
