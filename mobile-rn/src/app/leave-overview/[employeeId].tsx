import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useState } from 'react';

import { FormModal } from '../../components/kit';
import { Banner, Card, Chip, Field, Loading, Muted, Row, Screen } from '../../components/ui';
import { api, type Page } from '../../lib/api';
import { days, prettyDate } from '../../lib/format';
import { useAction, useLoad } from '../../lib/hooks';
import { left, overview, type BalanceRow } from '../../lib/hrleave';
import { statusLabel, statusTone, type LeaveRequest } from '../../lib/leave';

/** One employee: leave left per type (HR can change it) and every request. */
export default function EmployeeLeave() {
  const { employeeId, year: y } = useLocalSearchParams<{ employeeId: string; year?: string }>();
  const year = Number(y) || new Date().getFullYear();
  const router = useRouter();
  const { data, error: loadError, loading, reload } = useLoad(async () => {
    const [rows, requests] = await Promise.all([
      overview(year),
      api.get<Page<LeaveRequest>>('/leave', { employee_id: employeeId, year, per_page: 100 }).then((p) => p.items),
    ]);
    return { row: rows.find((r) => String(r.employee_id) === employeeId) ?? null, requests: requests.filter((l) => l.status !== 'draft') };
  }, [employeeId, year]);
  const { busy, error, notice, run, setError } = useAction();
  const [editing, setEditing] = useState<BalanceRow | null>(null);
  const [f, setF] = useState({ days: '', extra: '' });

  if (loading) return <Loading />;
  const row = data?.row;

  return (
    <Screen onRefresh={reload}>
      <Stack.Screen options={{ title: row?.name ?? 'Employee leave' }} />
      {loadError ? <Banner message={loadError} /> : null}
      {error && !editing ? <Banner message={error} /> : null}
      {notice ? <Banner message={notice} tone="success" /> : null}
      <Card>
        <Muted>Leave left in {year} — tap a type to change this person’s days.</Muted>
        {row?.balances.map((b) => (
          <Row
            key={b.id}
            title={b.leave_type}
            subtitle={
              b.unlimited
                ? `${days(b.used)} used · ${days(b.pending)} pending · no limit`
                : `${b.entitlement + b.carry_forward + b.adjustment} given · ${days(b.used)} used · ${days(b.pending)} pending`
            }
            right={<Chip label={left(b)} tone="info" />}
            onPress={
              b.unlimited
                ? undefined
                : () => {
                    setError(null);
                    setF({ days: String(b.entitlement), extra: String(b.adjustment) });
                    setEditing(b);
                  }
            }
          />
        ))}
      </Card>
      <Card>
        <Muted>Leave requests</Muted>
        {!data?.requests.length ? <Muted>No leave requests this year.</Muted> : null}
        {data?.requests.map((l) => (
          <Row
            key={l.id}
            title={l.leave_type?.name ?? 'Leave'}
            subtitle={`${prettyDate(l.start_date)} → ${prettyDate(l.end_date)} · ${days(l.requested_days)} day(s)`}
            right={<Chip label={statusLabel[l.status] ?? l.status} tone={statusTone(l.status)} />}
            onPress={() => router.push(`/leave/${l.id}`)}
          />
        ))}
      </Card>
      <FormModal
        visible={!!editing}
        title={`${editing?.leave_type ?? ''} — ${row?.name ?? ''}`}
        busy={busy === 'save'}
        onClose={() => setEditing(null)}
        onSave={async () => {
          if (!editing) return;
          if (await run('save', () => api.put(`/leave-balances/${editing.id}`, { entitlement: Number(f.days) || 0, adjustment: Number(f.extra) || 0 }), 'Leave days saved.')) {
            setEditing(null);
            await reload();
          }
        }}
      >
        {error ? <Banner message={error} /> : null}
        <Field label="Days for this year" value={f.days} onChangeText={(v) => setF((s) => ({ ...s, days: v }))} keyboardType="number-pad" />
        <Field label="Extra days (+) or deduction (−)" value={f.extra} onChangeText={(v) => setF((s) => ({ ...s, extra: v }))} keyboardType="numbers-and-punctuation" />
        <Muted>Only this employee changes. Everyone else keeps the leave type’s days.</Muted>
      </FormModal>
    </Screen>
  );
}
