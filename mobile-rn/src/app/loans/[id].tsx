import { useLocalSearchParams } from 'expo-router';
import { Text, View } from 'react-native';

import { ChainCard, DecisionCard, useIsApprover } from '../../components/Approvals';
import { KeyValues, SectionHeader, confirm } from '../../components/kit';
import { Banner, Button, Card, Chip, Loading, Muted, ProgressBar, Screen, colors } from '../../components/ui';
import { api } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { money, prettyDate } from '../../lib/format';
import { useAction, useLoad } from '../../lib/hooks';
import { LOAN_STATUS, loanTone, type Loan } from '../../lib/loans';

export default function LoanDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { can, user } = useAuth();
  const { data: l, error: loadError, loading, reload } = useLoad(() => api.get<Loan>(`/loans/${id}`), [id]);
  const { busy, error, notice, run } = useAction();
  const chainApprover = useIsApprover(l?.approval_chain, l?.employee_id ?? 0, l?.status ?? '', 'loans.approve');

  if (loading) return <Loading />;
  if (!l) return <Screen>{loadError ? <Banner message={loadError} /> : null}</Screen>;
  const m = (v: string | number | null | undefined) => money(v, l.currency);
  const mine = l.employee_id === user?.employee?.id;
  const approver = l.approval_chain?.length ? chainApprover : can('loans.approve') && l.status === 'pending' && !mine;
  const repaid = Number(l.principal_amount) ? (Number(l.repaid_amount) / Number(l.principal_amount)) * 100 : 0;
  const act = async (key: string, fn: () => Promise<unknown>, done: string) => {
    if (await run(key, fn, done)) await reload();
  };

  return (
    <Screen onRefresh={reload}>
      {error ? <Banner message={error} /> : null}
      {notice ? <Banner message={notice} tone="success" /> : null}
      <Card>
        <View style={{ flexDirection: 'row', alignItems: 'center', marginBottom: 8 }}>
          <Text style={{ flex: 1, fontSize: 18, fontWeight: '700', color: colors.text }}>
            {l.type_label ?? l.loan_type} · {m(l.principal_amount)}
          </Text>
          <Chip label={LOAN_STATUS[l.status] ?? l.status} tone={loanTone(l.status)} />
        </View>
        <KeyValues
          rows={[
            ['Employee', l.employee?.full_name],
            ['Reference', l.reference],
            ['Instalments', `${l.number_of_installments} × ${m(l.installment_amount)}`],
            ['Starts', prettyDate(l.start_date)],
            ['Repaid', m(l.repaid_amount)],
            ['Still owed', m(l.outstanding_balance)],
            ...(l.next_installment ? ([['Next deduction', `${m(l.next_installment.amount)} on ${prettyDate(l.next_installment.due_date)}`]] as [string, string][]) : []),
            ['Remarks', l.remarks],
          ]}
        />
        {l.status === 'active' || l.status === 'completed' ? (
          <View style={{ marginTop: 10 }}>
            <ProgressBar value={repaid} />
          </View>
        ) : null}
      </Card>
      {mine && l.status === 'draft' ? <Button label="Submit for approval" busy={busy === 'submit'} onPress={() => act('submit', () => api.post(`/loans/${l.id}/submit`), 'Submitted.')} /> : null}
      {mine && (l.status === 'draft' || l.status === 'pending') ? (
        <Button
          label="Cancel request"
          kind="danger"
          busy={busy === 'cancel'}
          onPress={async () => {
            if (await confirm('Cancel this request?', 'Nothing will be paid or deducted.', 'Cancel request')) await act('cancel', () => api.post(`/loans/${l.id}/cancel`), 'Cancelled.');
          }}
        />
      ) : null}
      {approver ? (
        <DecisionCard
          busy={busy}
          onApprove={(remarks) => act('approve', () => api.post(`/loans/${l.id}/approve`, { remarks }), 'Approved. Deductions start on the first instalment date.')}
          onReject={(remarks) => act('reject', () => api.post(`/loans/${l.id}/reject`, { remarks }), 'Rejected.')}
        />
      ) : null}
      {l.installments?.length ? (
        <Card>
          <SectionHeader title="Repayment schedule" />
          {l.installments.map((i) => (
            <View key={i.sequence} style={{ flexDirection: 'row', paddingVertical: 6 }}>
              <Text style={{ flex: 1, color: colors.text }}>
                {i.sequence}. {prettyDate(i.due_date)}
              </Text>
              <Text style={{ color: colors.text, marginRight: 10 }}>{m(i.amount)}</Text>
              <Chip label={i.status} tone={i.status === 'paid' || i.status === 'deducted' ? 'success' : 'neutral'} />
            </View>
          ))}
        </Card>
      ) : (
        <Muted>The repayment schedule appears once the loan is approved.</Muted>
      )}
      <ChainCard chain={l.approval_chain} />
    </Screen>
  );
}
