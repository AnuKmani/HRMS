import * as DocumentPicker from 'expo-document-picker';
import { useFocusEffect, useLocalSearchParams, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { Alert, Text, View } from 'react-native';

import { Banner, Button, Card, Chip, Field, Loading, Muted, Screen, Title, colors } from '../../components/ui';
import { api, ApiError, fileField } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { days, prettyDate } from '../../lib/format';
import { statusLabel, statusTone, stepApprover, type LeaveRequest } from '../../lib/leave';

type Detail = LeaveRequest & {
  certificate?: { required: boolean; has_file: boolean; due_at?: string | null; overdue?: boolean } | null;
};

export default function LeaveDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { user, can } = useAuth();
  const router = useRouter();
  const [leave, setLeave] = useState<Detail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [remarks, setRemarks] = useState('');
  const [busy, setBusy] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setLeave(await api.get<Detail>(`/leave/${id}`));
    } catch (e) {
      setError((e as ApiError).message);
    }
  }, [id]);

  useFocusEffect(
    useCallback(() => {
      void load();
    }, [load]),
  );

  async function act(action: string, body: Record<string, unknown> = {}, done?: string) {
    setError(null);
    setNotice(null);
    setBusy(action + (body.excess ?? ''));
    try {
      setLeave(await api.post<Detail>(`/leave/${id}/${action}`, body));
      if (done) setNotice(done);
      setRemarks('');
    } catch (e) {
      setError((e as ApiError).firstMessage);
    } finally {
      setBusy(null);
    }
  }

  async function uploadCertificate() {
    const picked = await DocumentPicker.getDocumentAsync({ type: ['application/pdf', 'image/*'], copyToCacheDirectory: true });
    if (picked.canceled || !picked.assets?.length) return;
    const file = picked.assets[0];
    const form = new FormData();
    form.append('certificate', fileField(file.uri, file.name, file.mimeType ?? 'application/octet-stream'));
    setBusy('certificate');
    setError(null);
    try {
      setLeave(await api.post<Detail>(`/leave/${id}/certificate`, form));
      setNotice('Certificate uploaded.');
    } catch (e) {
      setError((e as ApiError).firstMessage);
    } finally {
      setBusy(null);
    }
  }

  if (!leave) return error ? <Screen><Banner message={error} /></Screen> : <Loading />;

  const mine = leave.employee_id === user?.employee?.id;
  const chain = leave.approval_chain ?? [];
  const current = chain.find((s) => s.is_current || s.status === 'pending');
  const isLast = current ? current.sequence === Math.max(...chain.map((s) => s.sequence)) : false;
  const iApprove =
    !mine &&
    leave.status === 'pending' &&
    can('leave.approve') &&
    !!current &&
    (current.approver_type === 'role'
      ? !!user?.roles.includes(current.approver_role ?? '')
      : current.approver_type === 'permission'
        ? true
        : current.approver_employee_id === user?.employee?.id);
  const over = Number(leave.over_balance_days ?? 0);

  return (
    <Screen onRefresh={load}>
      {error ? <Banner message={error} /> : null}
      {notice ? <Banner message={notice} tone="success" /> : null}

      <Card>
        <View style={{ flexDirection: 'row', alignItems: 'center' }}>
          <Text style={{ flex: 1, fontSize: 20, fontWeight: '700', color: colors.text }}>{leave.leave_type?.name ?? 'Leave'}</Text>
          <Chip label={statusLabel[leave.status] ?? leave.status} tone={statusTone(leave.status)} />
        </View>
        {!mine && leave.employee?.full_name ? <Muted>{leave.employee.full_name}</Muted> : null}
        <Text style={{ marginTop: 10, fontSize: 16, color: colors.text }}>
          {prettyDate(leave.start_date)} → {prettyDate(leave.end_date)}
        </Text>
        <Muted>{days(leave.requested_days)} day(s)</Muted>
        {leave.reason ? <Text style={{ marginTop: 8, color: colors.text }}>{leave.reason}</Text> : null}
      </Card>

      {over > 0 ? (
        <Banner
          tone="warning"
          message={`${days(leave.requested_days)} day(s) asked · ${days(leave.available_days_at_submit ?? 0)} available · ${days(over)} over the balance.\n${
            leave.excess_decision === 'grant'
              ? `HR gave the extra ${days(over)} day(s).`
              : leave.excess_decision === 'lop'
                ? `${days(over)} day(s) are loss of pay (salary cut).`
                : 'HR decides on approval: give the extra days, or loss of pay.'
          }`}
        />
      ) : null}

      {leave.lop ? <Banner tone="error" message={`Loss of pay: ${days(leave.lop.days)} day(s). ${leave.lop.reason ?? ''}`} /> : null}

      {leave.certificate?.required ? (
        <Card>
          <Title>Medical certificate</Title>
          {leave.certificate.has_file ? (
            <Chip label="Uploaded" tone="success" />
          ) : (
            <>
              <Chip label={leave.certificate.overdue ? 'Overdue' : `Due ${prettyDate(leave.certificate.due_at)}`} tone={leave.certificate.overdue ? 'danger' : 'warning'} />
              <Muted>Without it, this leave becomes loss of pay.</Muted>
              {mine && ['pending', 'approved'].includes(leave.status) ? (
                <View style={{ marginTop: 10 }}>
                  <Button testID="upload-certificate" label="Upload certificate" kind="outline" onPress={uploadCertificate} busy={busy === 'certificate'} />
                </View>
              ) : null}
            </>
          )}
        </Card>
      ) : null}

      {chain.length ? (
        <Card>
          <Title>Approvals</Title>
          {chain.map((s) => (
            <View key={s.id} style={{ flexDirection: 'row', paddingVertical: 6, alignItems: 'center' }}>
              <View style={{ flex: 1 }}>
                <Text style={{ fontWeight: '600', color: colors.text }}>{s.name}</Text>
                <Muted>{stepApprover(s)}{s.remarks ? ` · “${s.remarks}”` : ''}</Muted>
              </View>
              <Chip
                label={s.status === 'pending' ? 'Waiting' : s.status[0].toUpperCase() + s.status.slice(1)}
                tone={s.status === 'approved' ? 'success' : s.status === 'rejected' ? 'danger' : s.status === 'pending' ? 'warning' : 'neutral'}
              />
            </View>
          ))}
        </Card>
      ) : null}

      {mine && leave.status === 'draft' ? <Button label="Edit draft" kind="outline" onPress={() => router.push(`/leave/new?id=${leave.id}`)} /> : null}
      {mine && leave.status === 'draft' ? (
        <Button testID="submit-leave" label="Send for approval" onPress={() => act('submit', {}, 'Sent for approval.')} busy={busy === 'submit'} />
      ) : null}

      {iApprove ? (
        <Card>
          <Title>Your decision</Title>
          <Field label="Remarks" value={remarks} onChangeText={setRemarks} multiline placeholder="Needed to reject" />
          {over > 0 && isLast ? (
            <>
              <Button testID="approve-grant" label={`Approve · give ${days(over)} extra day(s)`} onPress={() => act('approve', { remarks, excess: 'grant' }, 'Approved. Extra days given.')} busy={busy === 'approvegrant'} />
              <Button testID="approve-lop" label={`Approve · ${days(over)} day(s) loss of pay`} kind="outline" onPress={() => act('approve', { remarks, excess: 'lop' }, 'Approved with loss of pay.')} busy={busy === 'approvelop'} />
            </>
          ) : (
            <Button testID="approve-leave" label="Approve" onPress={() => act('approve', { remarks }, 'Approved.')} busy={busy === 'approve'} />
          )}
          <Button
            testID="reject-leave"
            label="Reject"
            kind="danger"
            onPress={() => {
              if (!remarks.trim()) {
                setError('Write a remark so the employee knows why.');
                return;
              }
              void act('reject', { remarks }, 'Rejected.');
            }}
            busy={busy === 'reject'}
          />
        </Card>
      ) : null}

      {mine && ['draft', 'pending'].includes(leave.status) ? (
        <Button
          testID="cancel-leave"
          label="Cancel request"
          kind="danger"
          onPress={() =>
            Alert.alert('Cancel this request?', 'The days go back to your balance.', [
              { text: 'Keep it', style: 'cancel' },
              { text: 'Cancel request', style: 'destructive', onPress: () => void act('cancel', {}, 'Cancelled.') },
            ])
          }
          busy={busy === 'cancel'}
        />
      ) : null}
      <Button label="Back to leave" kind="outline" onPress={() => router.back()} />
    </Screen>
  );
}
