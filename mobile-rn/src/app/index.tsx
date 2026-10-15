import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { Card, Chip, colors } from '../components/ui';
import { api } from '../lib/api';
import { useAuth } from '../lib/auth';
import { timeOf } from '../lib/format';
import { myBalances, type LeaveBalance } from '../lib/leave';

type Today = {
  checked_in: boolean;
  checked_out: boolean;
  attendance: { check_in_at?: string | null; check_out_at?: string | null } | null;
  site: { name: string } | null;
};

type Tile = { label: string; hint: string; path: string; icon: string };

export default function HomeScreen() {
  const { user, can, logout } = useAuth();
  const router = useRouter();
  const [today, setToday] = useState<Today | null>(null);
  const [balances, setBalances] = useState<LeaveBalance[]>([]);
  const [unread, setUnread] = useState(0);
  const [refreshing, setRefreshing] = useState(false);

  const employeeId = user?.employee?.id;

  const load = useCallback(async () => {
    const jobs: Promise<unknown>[] = [
      api.get<{ unread: number }>('/notifications/unread-count').then((d) => setUnread(d.unread)).catch(() => {}),
    ];
    if (employeeId) {
      jobs.push(api.get<Today>('/attendance/today').then(setToday).catch(() => setToday(null)));
      if (can('leave.balance.view')) {
        jobs.push(myBalances(employeeId).then(setBalances).catch(() => setBalances([])));
      }
    }
    await Promise.all(jobs);
  }, [employeeId, can]);

  useFocusEffect(
    useCallback(() => {
      void load();
    }, [load]),
  );

  async function refresh() {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  }

  if (!user) return null;

  const tiles: Tile[] = [
    ...(employeeId ? [{ label: 'Attendance', hint: 'Check in and out', path: '/attendance', icon: '⏱' }] : []),
    ...(can('leave.view') ? [{ label: 'Leave', hint: 'Apply, approve, track', path: '/leave', icon: '🏖' }] : []),
    ...(can('leave.balance.view') ? [{ label: 'Leave balances', hint: 'What is left this year', path: '/leave-balances', icon: '📊' }] : []),
    { label: 'Notifications', hint: 'Approvals and answers', path: '/notifications', icon: '🔔' },
  ];

  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: colors.bg }} edges={['bottom', 'left', 'right']}>
      <ScrollView refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} />}>
        <SafeAreaView edges={['top']} style={styles.header}>
          <View style={{ flexDirection: 'row', alignItems: 'center' }}>
            <View style={{ flex: 1 }}>
              <Text style={styles.hello}>Hello, {user.name}</Text>
              <Text style={styles.sub}>
                {[user.employee?.designation, user.roles[0]].filter(Boolean).join(' · ')}
              </Text>
            </View>
            <Pressable testID="open-notifications" onPress={() => router.push('/notifications')} style={styles.bell}>
              <Text style={{ fontSize: 22 }}>🔔</Text>
              {unread > 0 ? (
                <View style={styles.badge}>
                  <Text style={styles.badgeText}>{unread > 99 ? '99+' : unread}</Text>
                </View>
              ) : null}
            </Pressable>
            <Pressable testID="sign-out" onPress={logout} style={{ marginLeft: 12 }}>
              <Text style={{ color: '#fff', fontWeight: '600' }}>Sign out</Text>
            </Pressable>
          </View>
        </SafeAreaView>

        <View style={{ padding: 16 }}>
          {employeeId ? (
            <Card>
              <Pressable onPress={() => router.push('/attendance')}>
                <Text style={styles.cardTitle}>Today</Text>
                {today?.checked_out ? (
                  <Chip label={`Done · ${timeOf(today.attendance?.check_in_at)} – ${timeOf(today.attendance?.check_out_at)}`} tone="success" />
                ) : today?.checked_in ? (
                  <Chip label={`Checked in at ${timeOf(today.attendance?.check_in_at)}`} tone="info" />
                ) : (
                  <Chip label="Not checked in yet" tone="warning" />
                )}
                {today?.site ? <Text style={styles.muted}>Site: {today.site.name}</Text> : null}
                <Text style={styles.link}>
                  {today?.checked_in && !today?.checked_out ? 'Check out →' : today?.checked_out ? 'View →' : 'Check in →'}
                </Text>
              </Pressable>
            </Card>
          ) : null}

          {balances.length > 0 ? (
            <Card>
              <View style={{ flexDirection: 'row', alignItems: 'center', marginBottom: 6 }}>
                <Text style={[styles.cardTitle, { flex: 1, marginBottom: 0 }]}>My leave left ({new Date().getFullYear()})</Text>
                <Pressable testID="my-leave-apply" onPress={() => router.push('/leave/new')}>
                  <Text style={styles.link}>Apply</Text>
                </Pressable>
              </View>
              {balances.map((b) => (
                <View key={b.id} style={styles.balanceRow}>
                  <Text style={{ flex: 1, color: colors.text }}>{b.leave_type?.name ?? 'Leave'}</Text>
                  <Text style={{ fontWeight: '700', color: colors.text }}>
                    {b.unlimited
                      ? 'Unlimited'
                      : `${Number(b.remaining)} of ${Number(b.entitlement) + Number(b.carry_forward) + Number(b.adjustment)} left`}
                  </Text>
                </View>
              ))}
            </Card>
          ) : null}

          <View style={styles.grid}>
            {tiles.map((t) => (
              <Pressable key={t.path} testID={`tile-${t.path}`} onPress={() => router.push(t.path as never)} style={styles.tile}>
                <Text style={{ fontSize: 26 }}>{t.icon}</Text>
                <Text style={styles.tileLabel}>{t.label}</Text>
                <Text style={styles.muted}>{t.hint}</Text>
              </Pressable>
            ))}
          </View>

          <Text style={[styles.muted, { textAlign: 'center', marginTop: 8 }]}>
            More modules are being moved to this app. Until then, use the current HRMS app for them.
          </Text>
        </View>
      </ScrollView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  header: { backgroundColor: colors.primary, paddingHorizontal: 16, paddingBottom: 20, paddingTop: 12, borderBottomLeftRadius: 24, borderBottomRightRadius: 24 },
  hello: { color: '#fff', fontSize: 22, fontWeight: '800' },
  sub: { color: '#C7D2FE', marginTop: 2 },
  bell: { padding: 6 },
  badge: { position: 'absolute', right: 0, top: 0, backgroundColor: '#FACC15', borderRadius: 9, minWidth: 18, paddingHorizontal: 4, alignItems: 'center' },
  badgeText: { color: colors.primaryDark, fontSize: 11, fontWeight: '800' },
  cardTitle: { fontSize: 17, fontWeight: '700', color: colors.text, marginBottom: 8 },
  muted: { color: colors.muted, marginTop: 6 },
  link: { color: colors.primary, fontWeight: '700', marginTop: 8 },
  balanceRow: { flexDirection: 'row', paddingVertical: 6 },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: 12 },
  tile: { width: '47%', backgroundColor: '#fff', borderRadius: 14, padding: 14, borderWidth: 1, borderColor: colors.border },
  tileLabel: { fontSize: 16, fontWeight: '700', color: colors.text, marginTop: 6 },
});
