import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import { Banner, Button, Card, Chip, Empty, Loading, Row, Screen, colors } from '../../components/ui';
import { api, ApiError, type Page } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { days, prettyDate } from '../../lib/format';
import { statusLabel, statusTone, type LeaveRequest } from '../../lib/leave';

/** My leave, and — for approvers — what is waiting for a decision. */
export default function LeaveListScreen() {
  const { user, can } = useAuth();
  const router = useRouter();
  const approver = can('leave.approve');
  const [tab, setTab] = useState<'mine' | 'approve'>('mine');
  const [rows, setRows] = useState<LeaveRequest[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setError(null);
    try {
      const page =
        tab === 'mine'
          ? await api.get<Page<LeaveRequest>>('/leave', { employee_id: user?.employee?.id ?? 0, per_page: 50 })
          : await api.get<Page<LeaveRequest>>('/leave', { status: 'pending', per_page: 50 });
      setRows(tab === 'mine' ? page.items : page.items.filter((l) => l.employee_id !== user?.employee?.id));
    } catch (e) {
      setError((e as ApiError).message);
      setRows([]);
    }
  }, [tab, user]);

  useFocusEffect(
    useCallback(() => {
      setRows(null);
      void load();
    }, [load]),
  );

  return (
    <Screen onRefresh={load}>
      {approver ? (
        <View style={{ flexDirection: 'row', gap: 8, marginBottom: 14 }}>
          {(['mine', 'approve'] as const).map((t) => (
            <Pressable
              key={t}
              testID={`tab-${t}`}
              onPress={() => setTab(t)}
              style={{
                flex: 1,
                padding: 10,
                borderRadius: 12,
                alignItems: 'center',
                backgroundColor: tab === t ? colors.primary : '#fff',
                borderWidth: 1,
                borderColor: colors.primary,
              }}
            >
              <Text style={{ color: tab === t ? '#fff' : colors.primary, fontWeight: '700' }}>
                {t === 'mine' ? 'My leave' : 'To approve'}
              </Text>
            </Pressable>
          ))}
        </View>
      ) : null}

      {tab === 'mine' && can('leave.create') ? (
        <Button testID="apply-leave" label="Apply for leave" onPress={() => router.push('/leave/new')} />
      ) : null}
      {error ? <Banner message={error} /> : null}
      {rows === null ? (
        <Loading />
      ) : rows.length === 0 ? (
        <Empty message={tab === 'mine' ? 'No leave requests yet.' : 'Nothing is waiting for you.'} />
      ) : (
        <Card>
          {rows.map((l) => (
            <Row
              key={l.id}
              testID={`leave-${l.id}`}
              title={`${tab === 'approve' ? `${l.employee?.full_name ?? 'Employee'} · ` : ''}${l.leave_type?.name ?? 'Leave'}`}
              subtitle={`${prettyDate(l.start_date)} → ${prettyDate(l.end_date)} · ${days(l.requested_days)} day(s)`}
              right={<Chip label={statusLabel[l.status] ?? l.status} tone={statusTone(l.status)} />}
              onPress={() => router.push(`/leave/${l.id}`)}
            />
          ))}
        </Card>
      )}
    </Screen>
  );
}
