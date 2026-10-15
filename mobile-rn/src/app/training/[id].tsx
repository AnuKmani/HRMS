import * as DocumentPicker from 'expo-document-picker';
import { useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { Text, View } from 'react-native';

import { DateField } from '../../components/DateField';
import { FormModal, KeyValues, confirm } from '../../components/kit';
import { Banner, Button, Card, Chip, Field, Loading, Screen, colors } from '../../components/ui';
import { api, fileField } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { openRemoteFile } from '../../lib/files';
import { prettyDate, ymd } from '../../lib/format';
import { useAction, useLoad } from '../../lib/hooks';
import { TRAINING_STATUS, trainingTone, type Enrolment } from '../../lib/training';

export default function TrainingDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { can } = useAuth();
  const { data: e, error: loadError, loading, reload } = useLoad(() => api.get<Enrolment>(`/employee-training/${id}`), [id]);
  const { busy, error, notice, run, setError } = useAction();
  const [f, setF] = useState<Record<string, string> | null>(null);
  const [file, setFile] = useState<{ uri: string; name: string; type: string } | null>(null);

  if (loading) return <Loading />;
  if (!e) return <Screen>{loadError ? <Banner message={loadError} /> : null}</Screen>;

  async function pick() {
    const r = await DocumentPicker.getDocumentAsync({ type: ['image/*', 'application/pdf'], copyToCacheDirectory: true });
    if (!r.canceled) setFile({ uri: r.assets[0].uri, name: r.assets[0].name, type: r.assets[0].mimeType ?? 'application/octet-stream' });
  }

  async function complete() {
    if (!f || !e) return;
    const body = new FormData();
    for (const [k, v] of Object.entries(f)) if (v) body.append(k, v);
    if (file) body.append('file', fileField(file.uri, file.name, file.type));
    if (await run('complete', () => api.post(`/employee-training/${e.id}/complete`, body), 'Marked as completed.')) {
      setF(null);
      await reload();
    }
  }

  return (
    <Screen onRefresh={reload}>
      {error && !f ? <Banner message={error} /> : null}
      {notice ? <Banner message={notice} tone="success" /> : null}
      <Card>
        <View style={{ flexDirection: 'row', alignItems: 'center', marginBottom: 8 }}>
          <Text style={{ flex: 1, fontSize: 18, fontWeight: '700', color: colors.text }}>{e.training_program?.name}</Text>
          <Chip label={TRAINING_STATUS[e.status] ?? e.status} tone={trainingTone(e.status)} />
        </View>
        <KeyValues
          rows={[
            ['Employee', e.employee?.full_name],
            ['Enrolled', prettyDate(e.enrollment_date)],
            ['Training date', prettyDate(e.training_date)],
            ['Completed', prettyDate(e.completion_date)],
            ['Trainer', e.trainer],
            ['Result', e.result],
            ['Certificate no.', e.certificate_number],
            ['Certificate issued', prettyDate(e.certificate_issue_date)],
            ['Certificate expires', prettyDate(e.certificate_expiry_date)],
            ['Remarks', e.remarks],
          ]}
        />
      </Card>
      {e.has_certificate ? (
        <Button
          label="Open certificate"
          kind="outline"
          busy={busy === 'open'}
          onPress={() => run('open', () => openRemoteFile(`/employee-training/${e.id}/file`, e.certificate_original_name ?? `certificate-${e.id}`, e.certificate_mime_type ?? 'application/pdf'))}
        />
      ) : null}
      {can('training.complete') && e.is_completable ? (
        <Button
          label="Mark completed"
          onPress={() => {
            setError(null);
            setFile(null);
            setF({ completion_date: ymd(new Date()), result: '', certificate_number: '', certificate_issue_date: '', certificate_expiry_date: '', remarks: '' });
          }}
        />
      ) : null}
      {can('training.update') && !e.is_terminal ? (
        <Button
          label="Cancel enrolment"
          kind="danger"
          busy={busy === 'cancel'}
          onPress={async () => {
            if (await confirm('Cancel this enrolment?', 'It stays in the history as cancelled.', 'Cancel enrolment')) {
              if (await run('cancel', () => api.post(`/employee-training/${e.id}/cancel`), 'Cancelled.')) await reload();
            }
          }}
        />
      ) : null}
      <FormModal visible={!!f} title="Mark completed" busy={busy === 'complete'} onClose={() => setF(null)} onSave={complete}>
        {error ? <Banner message={error} /> : null}
        {f ? (
          <>
            <DateField label="Completed on" value={f.completion_date} onChange={(v) => setF({ ...f, completion_date: v })} />
            <Field label="Result" value={f.result} onChangeText={(v) => setF({ ...f, result: v })} placeholder="e.g. Passed, 85%" />
            {e.training_program?.certificate_required ? (
              <>
                <Field label="Certificate number" value={f.certificate_number} onChangeText={(v) => setF({ ...f, certificate_number: v })} />
                <DateField label="Certificate issued" value={f.certificate_issue_date} onChange={(v) => setF({ ...f, certificate_issue_date: v })} />
                <DateField label="Certificate expires" value={f.certificate_expiry_date} onChange={(v) => setF({ ...f, certificate_expiry_date: v })} />
                <Button label={file ? `File: ${file.name}` : 'Attach certificate (photo or PDF)'} kind="outline" onPress={pick} />
              </>
            ) : null}
            <Field label="Remarks" value={f.remarks} onChangeText={(v) => setF({ ...f, remarks: v })} multiline />
          </>
        ) : null}
      </FormModal>
    </Screen>
  );
}
