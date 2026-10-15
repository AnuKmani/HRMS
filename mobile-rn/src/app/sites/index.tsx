import { useRouter } from 'expo-router';
import { useState } from 'react';

import { Banner, Button, Card, Chip, Empty, Field, Loading, Row, Screen } from '../../components/ui';
import { api, type Page } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { useLoad } from '../../lib/hooks';

type Site = { id: number; name: string; code: string; status: string; project?: { name: string } | null; address?: string | null };

export default function SitesScreen() {
  const router = useRouter();
  const { can } = useAuth();
  const [search, setSearch] = useState('');
  const [query, setQuery] = useState('');
  const { data, error, loading, reload } = useLoad(() => api.get<Page<Site>>('/sites', { per_page: 200, search: query || undefined }).then((p) => p.items), [query]);
  return (
    <Screen onRefresh={reload}>
      {can('sites.manage') ? <Button label="Add site" onPress={() => router.push('/sites/form')} /> : null}
      <Field label="Search" value={search} onChangeText={setSearch} onSubmitEditing={() => setQuery(search.trim())} placeholder="Site name or code" returnKeyType="search" />
      {error ? <Banner message={error} /> : null}
      {loading ? (
        <Loading />
      ) : !data?.length ? (
        <Empty message="No sites." />
      ) : (
        <Card>
          {data.map((s) => (
            <Row
              key={s.id}
              title={`${s.name} (${s.code})`}
              subtitle={[s.project?.name, s.address].filter(Boolean).join(' · ')}
              right={s.status !== 'active' ? <Chip label="Inactive" /> : undefined}
              onPress={can('sites.manage') ? () => router.push(`/sites/form?id=${s.id}`) : undefined}
            />
          ))}
        </Card>
      )}
    </Screen>
  );
}
