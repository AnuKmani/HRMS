import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useEffect, useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import { DateField } from '../../components/DateField';
import { Banner, Button, Field, Loading, Muted, Screen, colors } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { leaveTypes, type LeaveRequest, type LeaveType } from '../../lib/leave';

/** Choose the type and dates, then send it to your approvers. */
export default function NewLeaveScreen() {
  const router = useRouter();
  const { id: editId } = useLocalSearchParams<{ id?: string }>();
  const [types, setTypes] = useState<LeaveType[] | null>(null);
  const [typeId, setTypeId] = useState<number | null>(null);
  const [start, setStart] = useState('');
  const [end, setEnd] = useState('');
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    leaveTypes()
      .then((t) => setTypes(t.filter((x) => x.status === 'active')))
      .catch((e) => {
        setTypes([]);
        setError((e as ApiError).message);
      });
    if (editId) {
      api.get<LeaveRequest>(`/leave/${editId}`).then((l) => {
        setTypeId(l.leave_type_id);
        setStart(l.start_date);
        setEnd(l.end_date);
        setReason(l.reason ?? '');
      }, (e) => setError((e as ApiError).message));
    }
  }, [editId]);

  const chosen = types?.find((t) => t.id === typeId);

  async function send() {
    setError(null);
    const local: Record<string, string> = {};
    if (!typeId) local.leave_type_id = 'Choose a leave type.';
    if (!start) local.start_date = 'Choose the first day.';
    if (!end) local.end_date = 'Choose the last day.';
    setErrors(local);
    if (Object.keys(local).length) return;

    setBusy(true);
    let created: LeaveRequest | null = null;
    try {
      const payload = { leave_type_id: typeId, start_date: start, end_date: end, reason: reason.trim() || null };
      created = editId ? await api.put<LeaveRequest>(`/leave/${editId}`, payload) : await api.post<LeaveRequest>('/leave', payload);
      await api.post(`/leave/${created.id}/submit`);
      if (editId) router.back();
      else router.replace(`/leave/${created.id}`);
    } catch (e) {
      const err = e as ApiError;
      if (created) {
        // Saved as a draft; the detail page shows why it was not sent.
        if (editId) router.back();
      else router.replace(`/leave/${created.id}`);
        return;
      }
      setError(err.message);
      setErrors(err.errors);
    } finally {
      setBusy(false);
    }
  }

  if (types === null) return <Loading />;

  return (
    <Screen>
      {editId ? <Stack.Screen options={{ title: 'Edit leave request' }} /> : null}
      {error ? <Banner message={error} /> : null}
      <Text style={{ fontWeight: '600', marginBottom: 8, fontSize: 15, color: colors.text }}>Leave type *</Text>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 6 }}>
        {types.map((t) => (
          <Pressable
            key={t.id}
            testID={`type-${t.code}`}
            onPress={() => setTypeId(t.id)}
            style={{
              paddingHorizontal: 14,
              paddingVertical: 10,
              borderRadius: 20,
              borderWidth: 1,
              borderColor: colors.primary,
              backgroundColor: typeId === t.id ? colors.primary : '#fff',
            }}
          >
            <Text style={{ color: typeId === t.id ? '#fff' : colors.primary, fontWeight: '600' }}>{t.name}</Text>
          </Pressable>
        ))}
      </View>
      {errors.leave_type_id ? <Text style={{ color: colors.danger, marginBottom: 8 }}>{errors.leave_type_id}</Text> : null}
      {chosen ? (
        <View style={{ marginBottom: 12 }}>
          <Muted>
            {[
              chosen.is_paid ? 'Paid' : 'Unpaid',
              chosen.is_unlimited ? 'No yearly limit' : chosen.entitlement_days ? `${chosen.entitlement_days} days a year` : null,
              chosen.requires_document ? `Certificate due within ${chosen.document_deadline_days} day(s) after` : null,
            ]
              .filter(Boolean)
              .join(' · ')}
          </Muted>
        </View>
      ) : null}
      <DateField required testID="start-date" label="First day" value={start} onChange={(v) => { setStart(v); if (!end || end < v) setEnd(v); }} error={errors.start_date} />
      <DateField required testID="end-date" label="Last day" value={end} onChange={setEnd} error={errors.end_date} />
      <Field label="Reason" value={reason} onChangeText={setReason} multiline error={errors.reason} placeholder="Optional" />
      <Button testID="send-leave" label="Send for approval" onPress={send} busy={busy} />
      <Muted>If you ask for more days than you have left, it still goes through — HR decides whether the extra days are given or become loss of pay.</Muted>
    </Screen>
  );
}
