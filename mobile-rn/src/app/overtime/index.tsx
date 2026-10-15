import { useRouter } from 'expo-router';
import { useState } from 'react';

import { STATUS_LABEL, statusTone } from '../../components/Approvals';
import { Tabs } from '../../components/kit';
import { Banner, Button, Card, Chip, Empty, Loading, Row, Screen } from '../../components/ui';
import { api, type Page } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { prettyDate } from '../../lib/format';
import { useLoad } from '../../lib/hooks';

export type Overtime = {
  id: number;
  employee_id: number;
  employee?: { full_name?: string } | null;
  overtime_date: string;
  project?: { name: string } | null;
  site?: { name: string } | null;
  requested_minutes: number;
  approved_minutes?: number | null;
  reason: string;
  remarks?: string | null;
  status: string;
  approval_chain?: any[];
};

export const hm = (m?: number | null) => (m ? `${Math.floor(m / 60)}h${m % 60 ? ` ${m % 60}m` : ''}` : '0h');

export default function OvertimeList() {
  const router = useRouter();
  const { can, user } = useAuth();
  const approver = can('overtime.approve');
  const [tab, setTab] = useState<'mine' | 'team'>('mine');
  const { data, error, loading, reload } = useLoad(
    () =>
      api
        .get<Page<Overtime>>('/overtime', {
          per_page: 100,
          ...(tab === 'mine' ? { employee_id: user?.employee?.id } : { status: 'pending' }),
        })
        .then((p) => (tab === 'team' ? p.items.filter((o) => o.employee_id !== user?.employee?.id) : p.items)),
    [tab],
  );
  return (
    <Screen onRefresh={reload}>
      {can('overtime.create') && user?.employee ? <Button label="Claim overtime" onPress={() => router.push('/overtime/new')} /> : null}
      {approver ? <Tabs value={tab} onChange={setTab} options={[['mine', 'My overtime'], ['team', 'To approve']]} /> : null}
      {error ? <Banner message={error} /> : null}
      {loading ? (
        <Loading />
      ) : !data?.length ? (
        <Empty message={tab === 'team' ? 'Nothing waiting for approval.' : 'No overtime claims yet.'} />
      ) : (
        <Card>
          {data.map((o) => (
            <Row
              key={o.id}
              title={`${tab === 'team' ? `${o.employee?.full_name ?? ''} · ` : ''}${prettyDate(o.overtime_date)} · ${hm(o.requested_minutes)}`}
              subtitle={[o.project?.name, o.site?.name, o.reason].filter(Boolean).join(' · ')}
              right={<Chip label={STATUS_LABEL[o.status] ?? o.status} tone={statusTone(o.status)} />}
              onPress={() => router.push(`/overtime/${o.id}`)}
            />
          ))}
        </Card>
      )}
    </Screen>
  );
}
