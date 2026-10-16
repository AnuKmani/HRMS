import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import { Banner, Button, Empty, Loading, Muted, Screen, colors } from '../components/ui';
import { api, ApiError, type Page } from '../lib/api';

type Notification = {
  id: number;
  type: string;
  title: string;
  body: string;
  data: { route?: string };
  read: boolean;
  created_at: string;
};

/** Turns the server's link on a notification into a screen of this app. */
function appRoute(route?: string): string | null {
  if (!route) return null;
  const rewrites: [RegExp, (m: RegExpMatchArray) => string][] = [
    [/^\/site-visit-plans/, () => '/visit-plans'],
    [/^\/daily-reports\/(\d+)$/, (m) => `/site-reports/${m[1]}`],
    [/^\/salary-certificates(\/\d+)?$/, () => '/salary-certificates'],
    [/^\/home$/, () => '/'],
  ];
  for (const [pattern, to] of rewrites) {
    const m = route.match(pattern);
    if (m) return to(m);
  }
  const direct = [
    /^\/leave(\/\d+)?$/,
    /^\/attendance$/,
    /^\/projects(\/\d+)?$/,
    /^\/project-progress(\/\d+)?$/,
    /^\/employees\/\d+$/,
    /^\/overtime\/\d+$/,
    /^\/expenses\/\d+$/,
    /^\/loans\/\d+$/,
    /^\/documents\/\d+$/,
    /^\/training\/\d+$/,
    /^\/assets\/\d+$/,
    /^\/payroll\/\d+$/,
    /^\/salary-slips$/,
  ];
  return direct.some((r) => r.test(route)) ? route : null;
}

export default function NotificationsScreen() {
  const router = useRouter();
  const [rows, setRows] = useState<Notification[] | null>(null);
  const [earlier, setEarlier] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setError(null);
      setRows((await api.get<Page<Notification>>('/notifications', { per_page: 50, unread: earlier ? undefined : 1 })).items);
    } catch (e) {
      setError((e as ApiError).message);
      setRows([]);
    }
  }, [earlier]);

  useFocusEffect(
    useCallback(() => {
      void load();
    }, [load]),
  );

  async function open(n: Notification) {
    if (!n.read) {
      api.post(`/notifications/${n.id}/read`).catch(() => {});
      // Read means done: it leaves the list (still under "Show earlier").
      setRows((r) => (earlier ? r?.map((x) => (x.id === n.id ? { ...x, read: true } : x)) : r?.filter((x) => x.id !== n.id)) ?? r);
    }
    const route = appRoute(n.data?.route);
    if (route) router.push(route as never);
  }

  async function readAll() {
    await api.post('/notifications/read-all').catch(() => {});
    setRows((r) => (earlier ? r?.map((x) => ({ ...x, read: true })) : []) ?? r);
  }

  if (rows === null) return <Loading />;

  return (
    <Screen onRefresh={load}>
      {error ? <Banner message={error} /> : null}
      {rows.some((r) => !r.read) ? <Button label="Mark all as read" kind="outline" onPress={readAll} /> : null}
      {rows.length === 0 ? <Empty message={earlier ? 'No notifications yet.' : 'You are all caught up.'} /> : null}
      {rows.map((n) => (
        <Pressable
          key={n.id}
          testID={`notification-${n.id}`}
          onPress={() => open(n)}
          style={{
            backgroundColor: n.read ? '#fff' : '#EEF2FF',
            borderRadius: 12,
            padding: 14,
            marginBottom: 10,
            borderWidth: 1,
            borderColor: colors.border,
          }}
        >
          <View style={{ flexDirection: 'row', alignItems: 'center' }}>
            {!n.read ? <View style={{ width: 8, height: 8, borderRadius: 4, backgroundColor: colors.primary, marginRight: 8 }} /> : null}
            <Text style={{ flex: 1, fontWeight: '700', color: colors.text }}>{n.title}</Text>
          </View>
          <Text style={{ color: colors.text, marginTop: 4 }}>{n.body}</Text>
          <Muted>{new Date(n.created_at).toLocaleString()}</Muted>
        </Pressable>
      ))}
      <Pressable onPress={() => setEarlier((v) => !v)} style={{ paddingVertical: 14, alignItems: 'center' }}>
        <Text style={{ color: colors.primary, fontWeight: '600' }}>{earlier ? 'Show new only' : 'Show earlier notifications'}</Text>
      </Pressable>
    </Screen>
  );
}
