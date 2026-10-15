import { useState } from 'react';

import { DateField } from '../components/DateField';
import { FormModal } from '../components/kit';
import { Banner, Button, Card, Empty, Loading, Muted, Row, Screen } from '../components/ui';
import { api, type Page } from '../lib/api';
import { useAuth } from '../lib/auth';
import { prettyDate, timeOf, ymd } from '../lib/format';
import { useAction, useLoad } from '../lib/hooks';

type Sheet = {
  id: number;
  employee?: { full_name?: string } | null;
  timesheet_date: string;
  site?: { name: string } | null;
  check_in_at?: string | null;
  check_out_at?: string | null;
  working_minutes?: number | null;
  overtime_minutes?: number | null;
  status: string;
};

const hours = (m?: number | null) => (m ? `${Math.floor(m / 60)}h ${m % 60}m` : '0h');

/** Working time per day, built from attendance. */
export default function TimesheetsScreen() {
  const { can, user } = useAuth();
  const manage = can('timesheets.manage');
  const first = new Date();
  first.setDate(1);
  const [from, setFrom] = useState(ymd(first));
  const [to, setTo] = useState(ymd(new Date()));
  const { data, error: loadError, loading, reload } = useLoad(
    () => api.get<Page<Sheet>>('/timesheets', { from, to, per_page: 200, ...(manage ? {} : { employee_id: user?.employee?.id }) }).then((p) => p.items),
    [from, to],
  );
  const { busy, error, notice, run } = useAction();
  const [gen, setGen] = useState(false);
  const total = (data ?? []).reduce((s, t) => s + (t.working_minutes ?? 0), 0);

  return (
    <Screen onRefresh={reload}>
      <DateField label="From" value={from} onChange={setFrom} />
      <DateField label="To" value={to} onChange={setTo} />
      {manage ? <Button label="Build timesheets from attendance" kind="outline" onPress={() => setGen(true)} /> : null}
      {loadError ? <Banner message={loadError} /> : null}
      {notice ? <Banner message={notice} tone="success" /> : null}
      {loading ? (
        <Loading />
      ) : !data?.length ? (
        <Empty message="No timesheets in this period." />
      ) : (
        <Card>
          <Muted>Total worked: {hours(total)}</Muted>
          {data.map((t) => (
            <Row
              key={t.id}
              title={`${manage ? `${t.employee?.full_name ?? ''} · ` : ''}${prettyDate(t.timesheet_date)}`}
              subtitle={`${timeOf(t.check_in_at)} – ${timeOf(t.check_out_at)} · ${hours(t.working_minutes)}${t.overtime_minutes ? ` · OT ${hours(t.overtime_minutes)}` : ''}${t.site ? ` · ${t.site.name}` : ''}`}
            />
          ))}
        </Card>
      )}
      <FormModal
        visible={gen}
        title="Build timesheets"
        saveLabel="Build"
        busy={busy === 'gen'}
        onClose={() => setGen(false)}
        onSave={async () => {
          if (await run('gen', () => api.post('/timesheets/generate', { from, to }), 'Timesheets built.')) {
            setGen(false);
            await reload();
          }
        }}
      >
        {error ? <Banner message={error} /> : null}
        <Muted>Builds a timesheet for every attendance day from {prettyDate(from)} to {prettyDate(to)}.</Muted>
      </FormModal>
    </Screen>
  );
}
