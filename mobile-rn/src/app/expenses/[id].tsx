import * as DocumentPicker from 'expo-document-picker';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { Pressable, Text, View } from 'react-native';

import { ChainCard, DecisionCard, STATUS_LABEL, statusTone, useIsApprover } from '../../components/Approvals';
import { KeyValues, SectionHeader, confirm } from '../../components/kit';
import { Banner, Button, Card, Chip, Loading, Muted, Row, Screen, colors } from '../../components/ui';
import { api } from '../../lib/api';
import { uploadField } from '../../lib/photos';
import { useAuth } from '../../lib/auth';
import { openRemoteFile } from '../../lib/files';
import { prettyDate } from '../../lib/format';
import { useAction, useLoad } from '../../lib/hooks';
import { amount, type Expense } from './index';

export default function ExpenseDetail() {
  const { id, problem } = useLocalSearchParams<{ id: string; problem?: string }>();
  const { user } = useAuth();
  const router = useRouter();
  const { data: e, error: loadError, loading, reload } = useLoad(() => api.get<Expense>(`/expenses/${id}`), [id]);
  const { busy, error, notice, run } = useAction();
  const approver = useIsApprover(e?.approval_chain, e?.employee_id ?? 0, e?.status ?? '', 'expenses.approve');

  if (loading) return <Loading />;
  if (!e) return <Screen>{loadError ? <Banner message={loadError} /> : null}</Screen>;
  const mine = e.employee_id === user?.employee?.id;
  const act = async (key: string, fn: () => Promise<unknown>, done: string) => {
    if (await run(key, fn, done)) await reload();
  };

  async function addReceipts() {
    const r = await DocumentPicker.getDocumentAsync({ type: ['image/*', 'application/pdf'], multiple: true, copyToCacheDirectory: true });
    if (r.canceled || !e) return;
    const body = new FormData();
    for (const a of r.assets.slice(0, 6)) body.append('receipts[]', await uploadField(a.uri, a.name, a.mimeType ?? 'application/octet-stream'));
    await act('upload', () => api.post(`/expenses/${e.id}/receipts`, body), 'Receipts added.');
  }

  return (
    <Screen onRefresh={reload}>
      {problem && !notice && !error ? <Banner message={`Saved as a draft, but: ${problem}`} tone="warning" /> : null}
      {error ? <Banner message={error} /> : null}
      {notice ? <Banner message={notice} tone="success" /> : null}
      <Card>
        <View style={{ flexDirection: 'row', alignItems: 'center', marginBottom: 8 }}>
          <Text style={{ flex: 1, fontSize: 18, fontWeight: '700', color: colors.text }}>{amount(e)}</Text>
          <Chip label={STATUS_LABEL[e.status] ?? e.status} tone={statusTone(e.status)} />
        </View>
        <KeyValues
          rows={[
            ['Employee', e.employee?.full_name],
            ['Date', prettyDate(e.expense_date)],
            ['Category', e.category?.name],
            ['For', e.description],
            ['Project', e.project?.name],
            ['Site', e.site?.name],
          ]}
        />
      </Card>

      <Card>
        <SectionHeader title={`Receipts (${e.receipts?.length ?? 0})`} action={mine && e.is_draft ? 'Add' : undefined} onAction={addReceipts} />
        {!e.receipts?.length ? <Muted>{e.requires_receipt ? 'This category needs a receipt before submitting.' : 'No receipts attached.'}</Muted> : null}
        {e.receipts?.map((r) => (
          <Row
            key={r.id}
            title={r.original_name}
            onPress={() => run('open', () => openRemoteFile(`/expenses/${e.id}/receipts/${r.id}`, r.original_name, r.mime_type))}
            right={
              mine && e.is_draft ? (
                <Pressable onPress={() => act('del', () => api.del(`/expenses/${e.id}/receipts/${r.id}`), 'Receipt removed.')}>
                  <Text style={{ color: colors.danger }}>Remove</Text>
                </Pressable>
              ) : undefined
            }
          />
        ))}
      </Card>

      {mine && e.is_draft ? <Button label="Edit draft" kind="outline" onPress={() => router.push(`/expenses/new?id=${e.id}`)} /> : null}
      {mine && e.is_draft ? <Button label="Submit for approval" onPress={() => act('submit', () => api.post(`/expenses/${e.id}/submit`), 'Submitted.')} busy={busy === 'submit'} /> : null}
      {mine && e.is_open ? (
        <Button
          label="Cancel claim"
          kind="danger"
          busy={busy === 'cancel'}
          onPress={async () => {
            if (await confirm('Cancel this claim?', 'It will not be paid.', 'Cancel claim')) await act('cancel', () => api.post(`/expenses/${e.id}/cancel`), 'Cancelled.');
          }}
        />
      ) : null}
      {approver ? (
        <DecisionCard
          busy={busy}
          onApprove={(remarks) => act('approve', () => api.post(`/expenses/${e.id}/approve`, { remarks }), 'Approved.')}
          onReject={(remarks) => act('reject', () => api.post(`/expenses/${e.id}/reject`, { remarks }), 'Rejected.')}
        />
      ) : null}
      <ChainCard chain={e.approval_chain} />
    </Screen>
  );
}
