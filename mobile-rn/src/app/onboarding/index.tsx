import { useRouter } from 'expo-router';
import { useState } from 'react';

import { Tabs } from '../../components/kit';
import { Banner, Card, Chip, Empty, Field, Loading, Row, Screen } from '../../components/ui';
import { api, type Page } from '../../lib/api';
import { prettyDate } from '../../lib/format';
import { useLoad } from '../../lib/hooks';

export const ONBOARDING_STATUS: Record<string, string> = { draft: 'Not started', pending_documents: 'Waiting for documents', hr_review: 'HR review', completed: 'Completed' };
export const onboardingTone = (s: string) => (s === 'completed' ? 'success' : s === 'hr_review' ? 'info' : 'warning');

type Item = {
  employee_id: number;
  employee?: { full_name?: string; employee_code?: string; joining_date?: string | null } | null;
  status: string;
  is_completed: boolean;
};

/** New joiners: documents collected and checked before they are fully set up. */
export default function OnboardingList() {
  const router = useRouter();
  const [tab, setTab] = useState<'incomplete' | 'completed' | 'all'>('incomplete');
  const [search, setSearch] = useState('');
  const [query, setQuery] = useState('');
  const { data, error, loading, reload } = useLoad(
    () => api.get<Page<Item>>('/onboarding', { per_page: 200, status: tab === 'all' ? undefined : tab, search: query || undefined }).then((p) => p.items),
    [tab, query],
  );
  return (
    <Screen onRefresh={reload}>
      <Field label="Search" value={search} onChangeText={setSearch} onSubmitEditing={() => setQuery(search.trim())} placeholder="Name or code" returnKeyType="search" />
      <Tabs value={tab} onChange={setTab} options={[['incomplete', 'In progress'], ['completed', 'Completed'], ['all', 'All']]} />
      {error ? <Banner message={error} /> : null}
      {loading ? (
        <Loading />
      ) : !data?.length ? (
        <Empty message="Nobody here." />
      ) : (
        <Card>
          {data.map((o) => (
            <Row
              key={o.employee_id}
              title={o.employee?.full_name ?? 'Employee'}
              subtitle={`${o.employee?.employee_code ?? ''}${o.employee?.joining_date ? ` · joined ${prettyDate(o.employee.joining_date)}` : ''}`}
              right={<Chip label={ONBOARDING_STATUS[o.status] ?? o.status} tone={onboardingTone(o.status)} />}
              onPress={() => router.push(`/onboarding/${o.employee_id}`)}
            />
          ))}
        </Card>
      )}
    </Screen>
  );
}
