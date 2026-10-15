import { useRouter } from 'expo-router';
import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import { Banner, Card, Chip, Empty, Field, Loading, Muted, Screen, colors } from '../../components/ui';
import { useLoad } from '../../lib/hooks';
import { REQUEST_LABELS, left, overview } from '../../lib/hrleave';

/** HR: every employee's leave left and their requests this year. */
export default function LeaveOverviewScreen() {
  const router = useRouter();
  const [year, setYear] = useState(new Date().getFullYear());
  const [search, setSearch] = useState('');
  const [query, setQuery] = useState('');
  const { data, error, loading, reload } = useLoad(() => overview(year, query || undefined), [year, query]);

  return (
    <Screen onRefresh={reload}>
      <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 24, marginBottom: 12 }}>
        <Pressable onPress={() => setYear((y) => y - 1)}><Text style={{ fontSize: 22, color: colors.primary }}>‹</Text></Pressable>
        <Text style={{ fontSize: 18, fontWeight: '700', color: colors.text }}>{year}</Text>
        <Pressable onPress={() => setYear((y) => y + 1)}><Text style={{ fontSize: 22, color: colors.primary }}>›</Text></Pressable>
      </View>
      <Field label="Search" value={search} onChangeText={setSearch} onSubmitEditing={() => setQuery(search.trim())} placeholder="Employee name or code" returnKeyType="search" />
      {error ? <Banner message={error} /> : null}
      {loading ? <Loading /> : !data?.length ? <Empty message="No employees found." /> : null}
      {data?.map((r) => {
        const counts = REQUEST_LABELS.filter(([k]) => (r.requests[k] ?? 0) > 0).map(([k, l]) => `${r.requests[k]} ${l}`);
        return (
          <Pressable key={r.employee_id} testID={`overview-${r.employee_id}`} onPress={() => router.push(`/leave-overview/${r.employee_id}?year=${year}`)}>
            <Card>
              <Text style={{ fontSize: 16, fontWeight: '700', color: colors.text }}>{r.name}{r.employee_code ? ` · ${r.employee_code}` : ''}</Text>
              <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 6 }}>
                {r.balances.map((b) => (
                  <Chip key={b.id} label={`${b.code}: ${left(b)}`} tone="info" />
                ))}
              </View>
              {counts.length ? <Muted>{counts.join(' · ')}</Muted> : null}
            </Card>
          </Pressable>
        );
      })}
    </Screen>
  );
}
