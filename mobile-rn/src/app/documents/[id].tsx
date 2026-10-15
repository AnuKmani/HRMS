import { useLocalSearchParams, useRouter } from 'expo-router';
import { useState } from 'react';
import { Text, View } from 'react-native';

import { FormModal, KeyValues, confirm } from '../../components/kit';
import { Banner, Button, Card, Chip, Field, Loading, Screen, colors } from '../../components/ui';
import { api } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { DOC_STATUS, docTone, expiryText, type Doc } from '../../lib/documents';
import { openRemoteFile } from '../../lib/files';
import { prettyDate } from '../../lib/format';
import { useAction, useLoad } from '../../lib/hooks';

export default function DocumentDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const router = useRouter();
  const { can, user } = useAuth();
  const { data: d, error: loadError, loading, reload } = useLoad(() => api.get<Doc>(`/employee-documents/${id}`), [id]);
  const { busy, error, notice, run } = useAction();
  const [rejecting, setRejecting] = useState(false);
  const [reason, setReason] = useState('');

  if (loading) return <Loading />;
  if (!d) return <Screen>{loadError ? <Banner message={loadError} /> : null}</Screen>;
  const exp = expiryText(d);
  const act = async (key: string, fn: () => Promise<unknown>, done: string) => {
    if (await run(key, fn, done)) {
      setRejecting(false);
      await reload();
    }
  };

  return (
    <Screen onRefresh={reload}>
      {error && !rejecting ? <Banner message={error} /> : null}
      {notice ? <Banner message={notice} tone="success" /> : null}
      {exp ? <Banner message={exp} tone="warning" /> : null}
      <Card>
        <View style={{ flexDirection: 'row', alignItems: 'center', marginBottom: 8 }}>
          <Text style={{ flex: 1, fontSize: 18, fontWeight: '700', color: colors.text }}>{d.document_type?.name}</Text>
          <Chip label={DOC_STATUS[d.status] ?? d.status} tone={docTone(d.status)} />
        </View>
        <KeyValues
          rows={[
            ['Employee', d.employee?.full_name],
            ['Number', d.document_number],
            ['Issued', prettyDate(d.issue_date)],
            ['Expires', prettyDate(d.expiry_date)],
            ['Notes', d.notes],
            ...(d.rejection_reason ? ([['Rejected because', d.rejection_reason]] as [string, string][]) : []),
          ]}
        />
      </Card>
      {d.has_file ? (
        <Button
          label={`Open ${d.original_name ?? 'file'}`}
          kind="outline"
          busy={busy === 'open'}
          onPress={() => run('open', () => openRemoteFile(`/employee-documents/${d.id}/file`, d.original_name ?? `document-${d.id}`, d.mime_type ?? 'application/octet-stream'))}
        />
      ) : null}
      {can('documents.verify') && d.status === 'pending' && d.employee_id !== user?.employee?.id ? (
        <>
          <Button label="Verify" busy={busy === 'verify'} onPress={() => act('verify', () => api.post(`/employee-documents/${d.id}/verify`), 'Verified.')} />
          <Button label="Reject" kind="danger" onPress={() => setRejecting(true)} />
        </>
      ) : null}
      {can('documents.delete') ? (
        <Button
          label="Delete"
          kind="danger"
          busy={busy === 'delete'}
          onPress={async () => {
            if (await confirm('Delete this document?', 'The file is removed for good.', 'Delete')) {
              if (await run('delete', () => api.del(`/employee-documents/${d.id}`), 'Deleted.')) router.back();
            }
          }}
        />
      ) : null}
      <FormModal
        visible={rejecting}
        title="Reject document"
        saveLabel="Reject"
        busy={busy === 'reject'}
        onClose={() => setRejecting(false)}
        onSave={() => act('reject', () => api.post(`/employee-documents/${d.id}/reject`, { reason }), 'Rejected. The employee can upload a new one.')}
      >
        {error ? <Banner message={error} /> : null}
        <Field label="Why?" required value={reason} onChangeText={setReason} multiline placeholder="e.g. Photo is blurred, expiry date not visible" />
      </FormModal>
    </Screen>
  );
}
