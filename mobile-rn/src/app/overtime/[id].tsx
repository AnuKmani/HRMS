import { useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { Text, View } from 'react-native';

import { ChainCard, DecisionCard, STATUS_LABEL, statusTone, useIsApprover } from '../../components/Approvals';
import { KeyValues, confirm } from '../../components/kit';
import { Banner, Button, Card, Chip, Field, Loading, Screen, colors } from '../../components/ui';
import { api } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { prettyDate } from '../../lib/format';
import { useAction, useLoad } from '../../lib/hooks';
import { hm, type Overtime } from './index';

export default function OvertimeDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { user } = useAuth();
  const { data: o, error: loadError, loading, reload } = useLoad(() => api.get<Overtime>(`/overtime/${id}`), [id]);
  const { busy, error, notice, run } = useAction();
  const [grant, setGrant] = useState('');
  const approver = useIsApprover(o?.approval_chain, o?.employee_id ?? 0, o?.status ?? '', 'overtime.approve');

  if (loading) return <Loading />;
  if (!o) return <Screen>{loadError ? <Banner message={loadError} /> : null}</Screen>;
  const mine = o.employee_id === user?.employee?.id;
  const act = async (key: string, fn: () => Promise<unknown>, done: string) => {
    if (await run(key, fn, done)) await reload();
  };

  return (
    <Screen onRefresh={reload}>
      {error ? <Banner message={error} /> : null}
      {notice ? <Banner message={notice} tone="success" /> : null}
      <Card>
        <View style={{ flexDirection: 'row', alignItems: 'center', marginBottom: 8 }}>
          <Text style={{ flex: 1, fontSize: 18, fontWeight: '700', color: colors.text }}>Overtime · {hm(o.requested_minutes)}</Text>
          <Chip label={STATUS_LABEL[o.status] ?? o.status} tone={statusTone(o.status)} />
        </View>
        <KeyValues
          rows={[
            ['Employee', o.employee?.full_name],
            ['Date', prettyDate(o.overtime_date)],
            ['Asked for', hm(o.requested_minutes)],
            ...(o.approved_minutes !== null && o.approved_minutes !== undefined ? ([['Granted', hm(o.approved_minutes)]] as [string, string][]) : []),
            ['Project', o.project?.name],
            ['Site', o.site?.name],
            ['Reason', o.reason],
            ['Remarks', o.remarks],
          ]}
        />
      </Card>
      {mine && o.status === 'draft' ? <Button label="Submit for approval" onPress={() => act('submit', () => api.post(`/overtime/${o.id}/submit`), 'Submitted.')} busy={busy === 'submit'} /> : null}
      {mine && (o.status === 'draft' || o.status === 'pending') ? (
        <Button
          label="Cancel claim"
          kind="danger"
          busy={busy === 'cancel'}
          onPress={async () => {
            if (await confirm('Cancel this claim?', 'It will not be paid.', 'Cancel claim')) await act('cancel', () => api.post(`/overtime/${o.id}/cancel`), 'Cancelled.');
          }}
        />
      ) : null}
      {approver ? (
        <DecisionCard
          busy={busy}
          extra={<Field label="Minutes to grant (blank = all asked)" value={grant} onChangeText={setGrant} keyboardType="number-pad" placeholder={String(o.requested_minutes)} />}
          onApprove={(remarks) => act('approve', () => api.post(`/overtime/${o.id}/approve`, { remarks, approved_minutes: grant ? Number(grant) : undefined }), 'Approved.')}
          onReject={(remarks) => act('reject', () => api.post(`/overtime/${o.id}/reject`, { remarks }), 'Rejected.')}
        />
      ) : null}
      <ChainCard chain={o.approval_chain} />
    </Screen>
  );
}
