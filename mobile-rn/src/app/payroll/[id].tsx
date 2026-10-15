import { Stack, useLocalSearchParams } from 'expo-router';
import { Text, View } from 'react-native';

import { KeyValues, SectionHeader, confirm } from '../../components/kit';
import { Banner, Button, Card, Chip, Loading, Muted, Screen, colors } from '../../components/ui';
import { api } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { openRemoteFile } from '../../lib/files';
import { money } from '../../lib/format';
import { useAction, useLoad } from '../../lib/hooks';
import { PAYROLL_STATUS, payrollTone, type Payroll } from '../../lib/payroll';

export default function PayrollDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { can } = useAuth();
  const { data: p, error: loadError, loading, reload } = useLoad(() => api.get<Payroll>(`/payroll/${id}`), [id]);
  const { busy, error, notice, run } = useAction();

  if (loading) return <Loading />;
  if (!p) return <Screen>{loadError ? <Banner message={loadError} /> : null}</Screen>;
  const m = (v: string | number | null | undefined) => money(v, p.currency);
  const act = async (key: string, done: string) => {
    if (await run(key, () => api.post(`/payroll/${p.id}/${key}`), done)) await reload();
  };

  return (
    <Screen onRefresh={reload}>
      <Stack.Screen options={{ title: p.period_label }} />
      {error ? <Banner message={error} /> : null}
      {notice ? <Banner message={notice} tone="success" /> : null}
      {p.blocked_reason ? <Banner message={p.blocked_reason} tone="warning" /> : null}
      <Card>
        <View style={{ flexDirection: 'row', alignItems: 'center', marginBottom: 8 }}>
          <Text style={{ flex: 1, fontSize: 18, fontWeight: '700', color: colors.text }}>{p.employee?.full_name}</Text>
          <Chip label={PAYROLL_STATUS[p.status] ?? p.status} tone={payrollTone(p.status)} />
        </View>
        <Text style={{ fontSize: 24, fontWeight: '800', color: colors.primary, marginBottom: 8 }}>{m(p.net_salary)}</Text>
        <Muted>Net pay for {p.period_label}</Muted>
      </Card>
      <Card>
        <SectionHeader title="Earnings" />
        <KeyValues
          rows={[
            ['Basic', m(p.basic_salary)],
            ['Allowances', m(p.total_allowances)],
            ['Overtime', m(p.overtime_amount)],
            ['Bonus', m(p.bonus_amount)],
            ['Gross', m(p.gross_salary)],
          ]}
        />
        <SectionHeader title="Deductions" />
        <KeyValues
          rows={[
            [`Unpaid leave (${p.lop_days} d)`, m(p.lop_amount)],
            ['Loan', m(p.loan_deduction)],
            ['Advance', m(p.advance_deduction)],
            ['Other', m(p.other_deductions)],
            ['Total', m(p.total_deductions)],
          ]}
        />
      </Card>
      {p.items?.length ? (
        <Card>
          <SectionHeader title="Line by line" />
          <KeyValues rows={p.items.map((i) => [i.description, `${i.type === 'deduction' ? '−' : ''}${m(i.amount)}`] as [string, string])} />
        </Card>
      ) : null}
      <Button label="Open payslip (PDF)" kind="outline" busy={busy === 'pdf'} onPress={() => run('pdf', () => openRemoteFile(`/salary-slips/${p.id}/pdf`, `payslip-${p.payroll_year}-${p.payroll_month}.pdf`))} />
      {can('payroll.process') && p.can_recalculate !== false && p.status !== 'locked' ? (
        <Button label="Recalculate" kind="outline" busy={busy === 'recalculate'} onPress={() => act('recalculate', 'Recalculated.')} />
      ) : null}
      {can('payroll.manage') && p.status === 'calculated' ? <Button label="Mark reviewed" busy={busy === 'review'} onPress={() => act('review', 'Marked as reviewed.')} /> : null}
      {can('payroll.manage') && p.status === 'reviewed' ? <Button label="Finalise" busy={busy === 'finalize'} onPress={() => act('finalize', 'Finalised.')} /> : null}
      {can('payroll.lock') && p.status === 'processed' ? (
        <Button
          label="Lock (no more changes)"
          kind="danger"
          busy={busy === 'lock'}
          onPress={async () => {
            if (await confirm('Lock this payroll?', 'A locked payroll cannot be recalculated or changed.', 'Lock')) await act('lock', 'Locked.');
          }}
        />
      ) : null}
    </Screen>
  );
}
