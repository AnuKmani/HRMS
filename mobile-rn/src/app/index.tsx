import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { Card, Chip, colors } from '../components/ui';
import { api } from '../lib/api';
import { useAuth } from '../lib/auth';
import { money, timeOf } from '../lib/format';
import { myBalances, type LeaveBalance } from '../lib/leave';
import { MODULES, SECTION_ORDER } from '../lib/modules';
import { pending, syncPending, type QueuedEvent } from '../lib/offline';

type Today = {
  checked_in: boolean;
  checked_out: boolean;
  attendance: { check_in_at?: string | null; check_out_at?: string | null } | null;
  site: { name: string } | null;
};
type Summary = {
  blocks: {
    leave?: { pending: number; approved_upcoming: number };
    payroll?: { period: string; net_salary: number; currency: string; status: string };
  };
};


export default function HomeScreen() {
  const { user, can, logout } = useAuth();
  const router = useRouter();
  const [today, setToday] = useState<Today | null>(null);
  const [balances, setBalances] = useState<LeaveBalance[]>([]);
  const [unread, setUnread] = useState(0);
  const [queued, setQueued] = useState<QueuedEvent[]>([]);
  const [summary, setSummary] = useState<Summary['blocks']>({});
  const [refreshing, setRefreshing] = useState(false);

  const employeeId = user?.employee?.id;

  const load = useCallback(async () => {
    const jobs: Promise<unknown>[] = [
      api.get<{ unread: number }>('/notifications/unread-count').then((d) => setUnread(d.unread)).catch(() => {}),
    ];
    if (can('dashboard.view')) {
      jobs.push(api.get<Summary>('/dashboards/employee').then((d) => setSummary(d.blocks ?? {})).catch(() => setSummary({})));
    }
    if (employeeId) {
      // Check-ins kept on the phone go first, so today's card shows them as sent.
      if (pending().length) await syncPending().catch(() => undefined);
      setQueued(pending().filter((e) => !e.error));
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

  const tiles = MODULES.filter((m) => m.visible(can, !!employeeId));
  const sections = SECTION_ORDER.map((name) => [name, tiles.filter((t) => t.section === name)] as const).filter(([, t]) => t.length);

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
            <Pressable testID="open-account" onPress={() => router.push('/account')} style={{ marginLeft: 12 }}>
              <Text style={{ fontSize: 22 }}>👤</Text>
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
                {!today?.checked_in && queued.some((e) => e.kind === 'check-in') ? (
                  <Chip label={`Checked in at ${timeOf(queued.find((e) => e.kind === 'check-in')?.at)} · saved on phone, sending…`} tone="warning" />
                ) : today?.checked_out ? (
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

          {summary.leave || summary.payroll ? (
            <Card>
              {summary.leave ? (
                <Pressable onPress={() => router.push('/leave')} style={styles.balanceRow}>
                  <Text style={{ flex: 1, color: colors.text }}>My leave requests</Text>
                  <Text style={{ fontWeight: '700', color: colors.text }}>
                    {summary.leave.pending} waiting · {summary.leave.approved_upcoming} coming up
                  </Text>
                </Pressable>
              ) : null}
              {summary.payroll ? (
                <Pressable onPress={() => router.push('/salary-slips')} style={styles.balanceRow}>
                  <Text style={{ flex: 1, color: colors.text }}>Latest pay · {summary.payroll.period}</Text>
                  <Text style={{ fontWeight: '700', color: colors.text }}>{money(summary.payroll.net_salary, summary.payroll.currency)}</Text>
                </Pressable>
              ) : null}
            </Card>
          ) : null}

          {sections.map(([name, list]) => (
            <View key={name} style={{ marginBottom: 8 }}>
              <Text style={styles.section}>{name}</Text>
              <View style={styles.grid}>
                {list.map((t) => (
                  <Pressable key={t.path} testID={`tile-${t.path}`} onPress={() => router.push(t.path as never)} style={styles.tile}>
                    <Text style={{ fontSize: 26 }}>{t.icon}</Text>
                    <Text style={styles.tileLabel}>{t.label}</Text>
                    <Text style={styles.muted}>{t.hint}</Text>
                  </Pressable>
                ))}
              </View>
            </View>
          ))}

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
  section: { fontSize: 15, fontWeight: '800', color: colors.muted, marginVertical: 8, textTransform: 'uppercase', letterSpacing: 0.5 },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: 12 },
  tile: { width: '47%', backgroundColor: '#fff', borderRadius: 14, padding: 14, borderWidth: 1, borderColor: colors.border },
  tileLabel: { fontSize: 16, fontWeight: '700', color: colors.text, marginTop: 6 },
});
