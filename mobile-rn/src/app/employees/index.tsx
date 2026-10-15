import { useRouter } from 'expo-router';
import { useState } from 'react';

import { Tabs } from '../../components/kit';
import { Banner, Button, Card, Chip, Empty, Field, Loading, Row, Screen } from '../../components/ui';
import { api, type Page } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { useLoad } from '../../lib/hooks';
import { EMPLOYMENT_STATUSES, label, type EmployeeRow } from '../../lib/people';

type Row_ = EmployeeRow & { designation?: { name: string } | null; department?: { name: string } | null };

export default function EmployeesScreen() {
  const router = useRouter();
  const { can } = useAuth();
  const [search, setSearch] = useState('');
  const [query, setQuery] = useState('');
  const [status, setStatus] = useState<'active' | 'resigned' | 'terminated' | 'all'>('active');
  const { data, error, loading, reload } = useLoad(
    () =>
      api
        .get<Page<Row_>>('/employees', {
          per_page: 100,
          search: query || undefined,
          employment_status: status === 'all' ? undefined : status,
          sort: 'first_name',
        })
        .then((p) => p.items),
    [query, status],
  );

  return (
    <Screen onRefresh={reload}>
      {can('employees.create') ? <Button testID="add-employee" label="Add employee" onPress={() => router.push('/employees/form')} /> : null}
      <Field label="Search" value={search} onChangeText={setSearch} onSubmitEditing={() => setQuery(search.trim())} placeholder="Name, code or email" returnKeyType="search" />
      <Tabs value={status} onChange={setStatus} options={[['active', 'Active'], ['resigned', 'Resigned'], ['terminated', 'Terminated'], ['all', 'All']]} />
      {error ? <Banner message={error} /> : null}
      {loading ? (
        <Loading />
      ) : !data?.length ? (
        <Empty message="No employees found." />
      ) : (
        <Card>
          {data.map((e) => (
            <Row
              key={e.id}
              testID={`employee-${e.id}`}
              title={e.full_name}
              subtitle={[e.employee_code, e.designation?.name, e.department?.name].filter(Boolean).join(' · ')}
              right={e.employment_status !== 'active' ? <Chip label={label(EMPLOYMENT_STATUSES, e.employment_status)} tone="neutral" /> : undefined}
              onPress={() => router.push(`/employees/${e.id}`)}
            />
          ))}
        </Card>
      )}
    </Screen>
  );
}
