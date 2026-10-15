import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import { DateField } from '../../components/DateField';
import { FormModal, KeyValues, PickerField, SectionHeader, confirm, type Option } from '../../components/kit';
import { Banner, Button, Card, Chip, Field, Loading, Muted, Screen, colors } from '../../components/ui';
import { api } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { prettyDate, ymd } from '../../lib/format';
import { useAction, useLoad } from '../../lib/hooks';
import { EMPLOYMENT_STATUSES, EMPLOYMENT_TYPES, label } from '../../lib/people';

type Employee = {
  id: number;
  employee_code: string;
  full_name: string;
  email: string;
  phone?: string | null;
  department?: { name: string } | null;
  designation?: { name: string } | null;
  reporting_manager?: { full_name?: string } | null;
  employment_type: string;
  employment_status: string;
  joining_date?: string | null;
  exit_date?: string | null;
  date_of_birth?: string | null;
  nationality?: string | null;
  salary?: number | string | null;
  emergency_contact_name?: string | null;
  emergency_contact_phone?: string | null;
};

type Account = { user_id: number; email: string; status: string; roles: string[]; must_change_password: boolean; temporary_password?: string } | null;

export default function EmployeeProfile() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const router = useRouter();
  const { can } = useAuth();
  const { data, error: loadError, loading, reload } = useLoad(async () => {
    const employee = await api.get<Employee>(`/employees/${id}`);
    const account = can('users.view') ? await api.get<Account>(`/employees/${id}/account`).catch(() => null) : null;
    return { employee, account };
  }, [id]);
  const { busy, error, notice, run, setError } = useAction();
  const [modal, setModal] = useState<'create' | 'reset' | 'role' | 'leave' | null>(null);
  const [f, setF] = useState<Record<string, string>>({});
  const [roles, setRoles] = useState<Option[]>([]);

  if (loading) return <Loading />;
  if (!data) return <Screen>{loadError ? <Banner message={loadError} /> : null}</Screen>;
  const { employee: e, account } = data;
  const left = e.employment_status === 'resigned' || e.employment_status === 'terminated';

  async function openModal(kind: typeof modal, initial: Record<string, string> = {}) {
    setError(null);
    setF(initial);
    if (kind === 'create' || kind === 'role') {
      const list = await api.get<{ name: string }[]>('/roles').catch(() => []);
      setRoles(list.map((r) => ({ value: r.name, label: r.name })));
    }
    setModal(kind);
  }

  async function act(key: string, fn: () => Promise<unknown>, done: string) {
    if (await run(key, fn, done)) {
      setModal(null);
      await reload();
    }
  }

  return (
    <Screen onRefresh={reload}>
      <Stack.Screen options={{ title: e.full_name }} />
      {error && !modal ? <Banner message={error} /> : null}
      {notice ? <Banner message={notice} tone="success" /> : null}
      <Card>
        <View style={{ flexDirection: 'row', alignItems: 'center', marginBottom: 8 }}>
          <Text style={{ flex: 1, fontSize: 19, fontWeight: '700', color: colors.text }}>{e.full_name}</Text>
          <Chip label={label(EMPLOYMENT_STATUSES, e.employment_status)} tone={left ? 'neutral' : 'success'} />
        </View>
        <KeyValues
          rows={[
            ['Code', e.employee_code],
            ['Email', e.email],
            ['Phone', e.phone],
            ['Department', e.department?.name],
            ['Designation', e.designation?.name],
            ['Reports to', e.reporting_manager?.full_name],
            ['Type', label(EMPLOYMENT_TYPES, e.employment_type)],
            ['Joined', prettyDate(e.joining_date)],
            ...(left ? ([['Left on', prettyDate(e.exit_date)]] as [string, string][]) : []),
            ['Date of birth', prettyDate(e.date_of_birth)],
            ['Nationality', e.nationality],
            ...(e.salary !== undefined && e.salary !== null ? ([['Salary', `AED ${Number(e.salary).toLocaleString('en-US')}`]] as [string, string][]) : []),
            ['Emergency contact', [e.emergency_contact_name, e.emergency_contact_phone].filter(Boolean).join(' · ')],
          ]}
        />
        <View style={{ flexDirection: 'row', gap: 16, marginTop: 12, flexWrap: 'wrap' }}>
          {can('employees.update') ? (
            <Pressable onPress={() => router.push(`/employees/form?id=${e.id}`)}>
              <Text style={{ color: colors.primary, fontWeight: '700' }}>✏️ Edit</Text>
            </Pressable>
          ) : null}
          {can('employees.update') && !left ? (
            <Pressable onPress={() => openModal('leave', { status: 'resigned', exit_date: ymd(new Date()) })}>
              <Text style={{ color: colors.danger, fontWeight: '700' }}>Mark as left</Text>
            </Pressable>
          ) : null}
          {can('employees.update') && left ? (
            <Pressable onPress={async () => { if (await confirm('Reinstate?', `${e.full_name} becomes active again and can sign in.`, 'Reinstate')) await act('reinstate', () => api.post(`/employees/${e.id}/reinstate`), 'Reinstated.'); }}>
              <Text style={{ color: colors.primary, fontWeight: '700' }}>Reinstate</Text>
            </Pressable>
          ) : null}
        </View>
      </Card>

      {can('users.view') ? (
        <Card>
          <SectionHeader title="Login account" />
          {account ? (
            <>
              <KeyValues
                rows={[
                  ['Username', account.email],
                  ['Role', account.roles.join(', ')],
                  ['Status', account.status],
                  ...(account.temporary_password ? ([['Temporary password', account.temporary_password]] as [string, string][]) : []),
                ]}
              />
              {account.must_change_password ? <Muted>{account.temporary_password ? 'They must change this password when they first sign in.' : 'Temporary password not changed yet. Use Reset password to give a new one.'}</Muted> : null}
              {can('users.manage') ? (
                <View style={{ marginTop: 10 }}>
                  <Button testID="reset-password" label="Reset password" kind="outline" onPress={() => openModal('reset')} />
                  <Button label="Change role" kind="outline" onPress={() => openModal('role', { role: account.roles[0] ?? '' })} />
                </View>
              ) : null}
            </>
          ) : (
            <>
              <Muted>No login yet. Create one so they can use the app.</Muted>
              {can('users.manage') && !left ? (
                <View style={{ marginTop: 10 }}>
                  <Button testID="create-login" label="Create login" onPress={() => openModal('create', { email: e.email, role: 'Employee' })} />
                </View>
              ) : null}
            </>
          )}
        </Card>
      ) : null}

      <FormModal
        visible={modal === 'create'}
        title="Create login"
        busy={busy === 'create'}
        onClose={() => setModal(null)}
        onSave={() => act('create', () => api.post(`/employees/${e.id}/account`, { role: f.role, email: f.email || undefined, password: f.password || undefined }), 'Login created.')}
      >
        {error ? <Banner message={error} /> : null}
        <Field label="Username (email)" value={f.email ?? ''} onChangeText={(v) => setF((s) => ({ ...s, email: v }))} autoCapitalize="none" keyboardType="email-address" />
        <PickerField label="Role" required value={f.role} options={roles} onChange={(v) => setF((s) => ({ ...s, role: String(v) }))} />
        <Field label="First password" value={f.password ?? ''} onChangeText={(v) => setF((s) => ({ ...s, password: v }))} placeholder="e.g. 12345 — leave blank for a generated one" />
        <Muted>They must choose their own password when they first sign in.</Muted>
      </FormModal>

      <FormModal
        visible={modal === 'reset'}
        title="Reset password"
        saveLabel="Reset"
        busy={busy === 'reset'}
        onClose={() => setModal(null)}
        onSave={() => act('reset', () => api.post(`/employees/${e.id}/account/reset-password`, { password: f.password || undefined }), 'Password reset. Give them the temporary password shown.')}
      >
        {error ? <Banner message={error} /> : null}
        <Field label="Temporary password" value={f.password ?? ''} onChangeText={(v) => setF((s) => ({ ...s, password: v }))} placeholder="Leave blank for a generated one" />
        <Muted>Their other sessions are signed out, and they must choose a new password at the next sign-in.</Muted>
      </FormModal>

      <FormModal
        visible={modal === 'role'}
        title="Change role"
        busy={busy === 'role'}
        onClose={() => setModal(null)}
        onSave={() => act('role', () => api.put(`/employees/${e.id}/account`, { role: f.role }), 'Role changed.')}
      >
        {error ? <Banner message={error} /> : null}
        <PickerField label="Role" required value={f.role} options={roles} onChange={(v) => setF((s) => ({ ...s, role: String(v) }))} />
      </FormModal>

      <FormModal
        visible={modal === 'leave'}
        title={`Mark ${e.full_name} as left`}
        saveLabel="Mark as left"
        busy={busy === 'leave'}
        onClose={() => setModal(null)}
        onSave={() => act('leave', () => api.post(`/employees/${e.id}/offboard`, { status: f.status, exit_date: f.exit_date || undefined }), 'Marked as left. Their history is kept.')}
      >
        {error ? <Banner message={error} /> : null}
        <PickerField label="Reason" value={f.status} options={[{ value: 'resigned', label: 'Resigned' }, { value: 'terminated', label: 'Terminated' }]} onChange={(v) => setF((s) => ({ ...s, status: String(v) }))} />
        <DateField label="Last day" value={f.exit_date ?? ''} onChange={(v) => setF((s) => ({ ...s, exit_date: v }))} />
        <Muted>Their attendance, leave and payroll history stays. Their login is switched off.</Muted>
      </FormModal>
    </Screen>
  );
}
