import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useEffect, useState } from 'react';

import { DateField } from '../../components/DateField';
import { PickerField, type Option } from '../../components/kit';
import { Banner, Button, Field, Loading, Muted, Screen } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { ymd } from '../../lib/format';
import { EMPLOYMENT_STATUSES, EMPLOYMENT_TYPES, employeeOptions, lookupOptions } from '../../lib/people';

type Employee = Record<string, unknown> & { id: number };

const FIELDS = ['first_name', 'middle_name', 'last_name', 'email', 'phone', 'nationality', 'address', 'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relation', 'salary'] as const;

/** HR adds or edits an employee. The code is automatic. */
export default function EmployeeForm() {
  const { id } = useLocalSearchParams<{ id?: string }>();
  const router = useRouter();
  const { can } = useAuth();
  const editing = !!id;
  const [loading, setLoading] = useState(true);
  const [f, setF] = useState<Record<string, string>>({ employment_type: 'permanent', employment_status: 'active', joining_date: ymd(new Date()) });
  const [opts, setOpts] = useState<{ departments: Option[]; designations: Option[]; people: Option[] }>({ departments: [], designations: [], people: [] });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    (async () => {
      const [departments, designations, people] = await Promise.all([
        lookupOptions('/departments').catch(() => []),
        lookupOptions('/designations').catch(() => []),
        employeeOptions().catch(() => []),
      ]);
      setOpts({ departments, designations, people });
      if (editing) {
        try {
          const e = await api.get<Employee>(`/employees/${id}`);
          const next: Record<string, string> = {};
          for (const k of [...FIELDS, 'employee_code', 'date_of_birth', 'joining_date', 'employment_type', 'employment_status', 'department_id', 'designation_id', 'reporting_manager_id']) {
            const v = e[k];
            if (v !== null && v !== undefined) next[k] = String(v);
          }
          setF(next);
        } catch (err) {
          setError((err as ApiError).message);
        }
      }
      setLoading(false);
    })();
  }, [editing, id]);

  const set = (k: string) => (v: string) => setF((s) => ({ ...s, [k]: v }));
  const pick = (k: string) => (v: number | string | null) => setF((s) => ({ ...s, [k]: v === null ? '' : String(v) }));

  async function save() {
    setBusy(true);
    setError(null);
    setErrors({});
    const body: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(f)) body[k] = v === '' ? null : v;
    for (const k of ['department_id', 'designation_id', 'reporting_manager_id']) body[k] = f[k] ? Number(f[k]) : null;
    if (f.salary) body.salary = Number(f.salary);
    try {
      const saved = editing ? await api.put<Employee>(`/employees/${id}`, body) : await api.post<Employee>('/employees', body);
      router.replace(`/employees/${saved.id}`);
    } catch (e) {
      const err = e as ApiError;
      setError(err.message);
      setErrors(err.errors);
    } finally {
      setBusy(false);
    }
  }

  if (loading) return <Loading />;

  return (
    <Screen>
      <Stack.Screen options={{ title: editing ? 'Edit employee' : 'New employee' }} />
      {error ? <Banner message={error} /> : null}
      {editing ? <Muted>Code: {f.employee_code}</Muted> : <Muted>The employee code is given automatically.</Muted>}
      <Screen.Spacer />
      <Field label="First name" required value={f.first_name ?? ''} onChangeText={set('first_name')} error={errors.first_name} />
      <Field label="Middle name" value={f.middle_name ?? ''} onChangeText={set('middle_name')} />
      <Field label="Last name" required value={f.last_name ?? ''} onChangeText={set('last_name')} error={errors.last_name} />
      <Field label="Email" required value={f.email ?? ''} onChangeText={set('email')} keyboardType="email-address" autoCapitalize="none" error={errors.email} />
      <Field label="Phone" value={f.phone ?? ''} onChangeText={set('phone')} keyboardType="phone-pad" error={errors.phone} />
      <DateField label="Date of birth" value={f.date_of_birth ?? ''} onChange={set('date_of_birth')} error={errors.date_of_birth} />
      <Field label="Nationality" value={f.nationality ?? ''} onChangeText={set('nationality')} />
      <DateField label="Joining date" required value={f.joining_date ?? ''} onChange={set('joining_date')} error={errors.joining_date} />
      <PickerField label="Department" value={f.department_id ? Number(f.department_id) : null} options={opts.departments} onChange={pick('department_id')} allowClear />
      <PickerField label="Designation" value={f.designation_id ? Number(f.designation_id) : null} options={opts.designations} onChange={pick('designation_id')} allowClear />
      <PickerField label="Reporting manager" value={f.reporting_manager_id ? Number(f.reporting_manager_id) : null} options={opts.people.filter((o) => String(o.value) !== id)} onChange={pick('reporting_manager_id')} allowClear />
      <PickerField label="Employment type" required value={f.employment_type} options={EMPLOYMENT_TYPES.map(([value, l]) => ({ value, label: l }))} onChange={pick('employment_type')} />
      <PickerField label="Status" required value={f.employment_status} options={EMPLOYMENT_STATUSES.map(([value, l]) => ({ value, label: l }))} onChange={pick('employment_status')} />
      {can('employees.salary.view') ? <Field label="Salary (AED / month)" value={f.salary ?? ''} onChangeText={set('salary')} keyboardType="decimal-pad" error={errors.salary} /> : null}
      <Field label="Address" value={f.address ?? ''} onChangeText={set('address')} multiline />
      <Field label="Emergency contact name" value={f.emergency_contact_name ?? ''} onChangeText={set('emergency_contact_name')} />
      <Field label="Emergency contact phone" value={f.emergency_contact_phone ?? ''} onChangeText={set('emergency_contact_phone')} keyboardType="phone-pad" />
      <Field label="Emergency contact relation" value={f.emergency_contact_relation ?? ''} onChangeText={set('emergency_contact_relation')} />
      <Button testID="save-employee" label={editing ? 'Save changes' : 'Create employee'} onPress={save} busy={busy} />
    </Screen>
  );
}
