import { useRouter } from 'expo-router';
import { useState } from 'react';
import { Text, View } from 'react-native';

import { PickerField } from '../../components/kit';
import { Banner, Button, Card, Chip, Empty, Field, Loading, ProgressBar, Row, Screen, colors } from '../../components/ui';
import { useAuth } from '../../lib/auth';
import { useLoad } from '../../lib/hooks';
import { PROJECT_STATUSES, listProjects, pct, statusName } from '../../lib/projects';

export default function ProjectsScreen() {
  const { can } = useAuth();
  const router = useRouter();
  const [search, setSearch] = useState('');
  const [query, setQuery] = useState('');
  const [status, setStatus] = useState<string | null>(null);
  const { data, error, loading, reload } = useLoad(
    () => listProjects({ search: query || undefined, status: status ?? undefined, sort: 'name' }).then((p) => p.items),
    [query, status],
  );

  return (
    <Screen onRefresh={reload}>
      {can('projects.manage') ? <Button testID="add-project" label="Add project" onPress={() => router.push('/projects/form')} /> : null}
      <Field label="Search" value={search} onChangeText={setSearch} onSubmitEditing={() => setQuery(search.trim())} placeholder="Name, code or client" returnKeyType="search" />
      <PickerField
        label="Status"
        value={status}
        allowClear
        placeholder="All statuses"
        options={PROJECT_STATUSES.map(([value, label]) => ({ value, label }))}
        onChange={(v) => setStatus(v as string | null)}
      />
      {error ? <Banner message={error} /> : null}
      {loading ? (
        <Loading />
      ) : !data?.length ? (
        <Empty message="No projects found." />
      ) : (
        <Card>
          {data.map((p) => (
            <View key={p.id}>
              <Row
                testID={`project-${p.id}`}
                title={p.name}
                subtitle={[p.code, p.project_manager?.full_name ? `PM: ${p.project_manager.full_name}` : null, statusName(p.status)].filter(Boolean).join(' · ')}
                right={p.behind_schedule ? <Chip label="Behind" tone="danger" /> : <Text style={{ color: colors.text, fontWeight: '700' }}>{pct(p.progress_percent)}</Text>}
                onPress={() => router.push(`/projects/${p.id}`)}
              />
              <ProgressBar value={Number(p.progress_percent)} />
              {p.upcoming_events?.[0] ? (
                <Text style={{ color: '#C2410C', marginBottom: 6 }}>
                  📅 {p.upcoming_events[0].label} · {p.upcoming_events[0].event_date}
                </Text>
              ) : null}
            </View>
          ))}
        </Card>
      )}
    </Screen>
  );
}
