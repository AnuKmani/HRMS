import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { Text, View } from 'react-native';

import { Banner, Card, Empty, Loading, Muted, ProgressBar, Screen, colors } from '../components/ui';
import { ApiError } from '../lib/api';
import { useAuth } from '../lib/auth';
import { days } from '../lib/format';
import { myBalances, type LeaveBalance } from '../lib/leave';

/** Each leave type: given, used, waiting, left. */
export default function LeaveBalancesScreen() {
  const { user } = useAuth();
  const [rows, setRows] = useState<LeaveBalance[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!user?.employee) return setRows([]);
    try {
      setRows(await myBalances(user.employee.id));
    } catch (e) {
      setError((e as ApiError).message);
      setRows([]);
    }
  }, [user]);

  useFocusEffect(
    useCallback(() => {
      void load();
    }, [load]),
  );

  if (rows === null) return <Loading />;

  return (
    <Screen onRefresh={load}>
      {error ? <Banner message={error} /> : null}
      {rows.length === 0 ? <Empty message="Balances appear once HR sets leave for the year." /> : null}
      {rows.map((b) => {
        const given = Number(b.entitlement) + Number(b.carry_forward) + Number(b.adjustment);
        return (
          <Card key={b.id}>
            <View style={{ flexDirection: 'row' }}>
              <Text style={{ flex: 1, fontSize: 17, fontWeight: '700', color: colors.text }}>{b.leave_type?.name ?? 'Leave'}</Text>
              <Text style={{ fontSize: 17, fontWeight: '800', color: colors.primary }}>
                {b.unlimited ? 'Unlimited' : `${days(b.remaining)} left`}
              </Text>
            </View>
            {!b.unlimited && given > 0 ? <ProgressBar value={((Number(b.used) + Number(b.pending)) / given) * 100} /> : null}
            <Muted>
              {b.unlimited
                ? `${days(b.used)} used · ${days(b.pending)} waiting for approval`
                : `${given} given · ${days(b.used)} used · ${days(b.pending)} waiting for approval`}
            </Muted>
          </Card>
        );
      })}
    </Screen>
  );
}
