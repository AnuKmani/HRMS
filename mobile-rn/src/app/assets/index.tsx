import { useRouter } from 'expo-router';
import { useState } from 'react';

import { Tabs } from '../../components/kit';
import { Banner, Button, Card, Chip, Empty, Field, Loading, Row, Screen } from '../../components/ui';
import { api, type Page } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { useLoad } from '../../lib/hooks';

export type Asset = {
  id: number;
  asset_code: string;
  asset_type_id: number;
  asset_type?: { id: number; name: string } | null;
  name: string;
  description?: string | null;
  serial_number?: string | null;
  manufacturer?: string | null;
  model?: string | null;
  purchase_date?: string | null;
  current_condition: string;
  status: string;
  notes?: string | null;
  current_holder?: { id: number; full_name?: string } | null;
  current_assignment?: { id: number; assigned_date: string; expected_return_date?: string | null; is_overdue?: boolean } | null;
  assignments?: {
    id: number;
    employee?: { full_name?: string } | null;
    assigned_date: string;
    returned_date?: string | null;
    assigned_condition?: string | null;
    returned_condition?: string | null;
    remarks?: string | null;
    status: string;
  }[];
  is_assignable?: boolean;
  is_retired?: boolean;
};

export const ASSET_STATUS: Record<string, string> = { available: 'Available', assigned: 'Assigned', maintenance: 'In maintenance', damaged: 'Damaged', lost: 'Lost', retired: 'Retired' };
export const assetTone = (s: string) => (s === 'available' ? 'success' : s === 'assigned' ? 'info' : s === 'damaged' || s === 'lost' ? 'danger' : s === 'maintenance' ? 'warning' : 'neutral');
export const CONDITIONS = [
  { value: 'new', label: 'New' },
  { value: 'good', label: 'Good' },
  { value: 'fair', label: 'Fair' },
  { value: 'poor', label: 'Poor' },
];

/** Laptops, phones, tools, vehicles — who holds what. */
export default function AssetsScreen() {
  const router = useRouter();
  const { can } = useAuth();
  const [status, setStatus] = useState<'all' | 'available' | 'assigned'>('all');
  const [search, setSearch] = useState('');
  const [query, setQuery] = useState('');
  const { data, error, loading, reload } = useLoad(
    () => api.get<Page<Asset>>('/assets', { per_page: 200, status: status === 'all' ? undefined : status, search: query || undefined }).then((p) => p.items),
    [status, query],
  );
  return (
    <Screen onRefresh={reload}>
      {can('assets.create') ? <Button label="Add asset" onPress={() => router.push('/assets/form')} /> : null}
      <Field label="Search" value={search} onChangeText={setSearch} onSubmitEditing={() => setQuery(search.trim())} placeholder="Code, name or serial" returnKeyType="search" />
      <Tabs value={status} onChange={setStatus} options={[['all', 'All'], ['available', 'Available'], ['assigned', 'Assigned']]} />
      {error ? <Banner message={error} /> : null}
      {loading ? (
        <Loading />
      ) : !data?.length ? (
        <Empty message="No assets." />
      ) : (
        <Card>
          {data.map((a) => (
            <Row
              key={a.id}
              title={`${a.name} (${a.asset_code})`}
              subtitle={[a.asset_type?.name, a.current_holder?.full_name ? `with ${a.current_holder.full_name}` : null, a.serial_number].filter(Boolean).join(' · ')}
              right={<Chip label={ASSET_STATUS[a.status] ?? a.status} tone={assetTone(a.status)} />}
              onPress={() => router.push(`/assets/${a.id}`)}
            />
          ))}
        </Card>
      )}
    </Screen>
  );
}
