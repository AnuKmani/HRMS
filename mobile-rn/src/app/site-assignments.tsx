import { useState } from 'react';
import { Pressable, Text } from 'react-native';

import { DateField } from '../components/DateField';
import { FormModal, PickerField, Tabs, confirm, type Option } from '../components/kit';
import { Banner, Button, Card, Chip, Empty, Loading, Row, Screen, colors } from '../components/ui';
import { api, type Page } from '../lib/api';
import { useAuth } from '../lib/auth';
import { prettyDate, ymd } from '../lib/format';
import { useAction, useLoad } from '../lib/hooks';
import { employeeOptions } from '../lib/people';

type Assignment = {
  id: number;
  employee?: { full_name?: string } | null;
  project?: { name: string } | null;
  site?: { name: string } | null;
  project_id: number;
  site_id: number;
  start_date: string;
  end_date?: string | null;
  assignment_type: string;
  status: string;
};

/** Who works at which site. */
export default function SiteAssignmentsScreen() {
  const { can } = useAuth();
  const manage = can('assignments.manage');
  const [status, setStatus] = useState<'active' | 'ended' | 'all'>('active');
  const { data, error: loadError, loading, reload } = useLoad(
    () => api.get<Page<Assignment>>('/employee-site-assignments', { per_page: 200, status: status === 'all' ? undefined : status }).then((p) => p.items),
    [status],
  );
  const { busy, error, notice, run, setError } = useAction();
  const [f, setF] = useState<Record<string, string> | null>(null);
  const [opts, setOpts] = useState<{ people: Option[]; sites: (Option & { project: number })[] }>({ people: [], sites: [] });

  async function add() {
    setError(null);
    const [people, sites] = await Promise.all([
      employeeOptions().catch(() => []),
      api.get<Page<{ id: number; name: string; project_id: number; project?: { name: string } | null }>>('/sites', { per_page: 200 }).then((p) => p.items).catch(() => []),
    ]);
    setOpts({ people, sites: sites.map((s) => ({ value: s.id, label: s.name, hint: s.project?.name ?? null, project: s.project_id })) });
    setF({ employee_id: '', site_id: '', start_date: ymd(new Date()), end_date: '', assignment_type: 'primary' });
  }

  return (
    <Screen onRefresh={reload}>
      {manage ? <Button label="Assign someone to a site" onPress={add} /> : null}
      <Tabs value={status} onChange={setStatus} options={[['active', 'Active'], ['ended', 'Ended'], ['all', 'All']]} />
      {loadError ? <Banner message={loadError} /> : null}
      {error && !f ? <Banner message={error} /> : null}
      {notice ? <Banner message={notice} tone="success" /> : null}
      {loading ? (
        <Loading />
      ) : !data?.length ? (
        <Empty message="No assignments." />
      ) : (
        <Card>
          {data.map((a) => (
            <Row
              key={a.id}
              title={`${a.employee?.full_name ?? 'Employee'} → ${a.site?.name ?? 'Site'}`}
              subtitle={`${a.project?.name ?? ''} · ${a.assignment_type} · ${prettyDate(a.start_date)}${a.end_date ? ` → ${prettyDate(a.end_date)}` : ''}`}
              right={
                manage && a.status === 'active' ? (
                  <Pressable
                    onPress={async () => {
                      if (await confirm('End assignment?', 'They will no longer be posted to this site from today.', 'End')) {
                        if (await run('end', () => api.put(`/employee-site-assignments/${a.id}`, { status: 'ended', end_date: ymd(new Date()) }), 'Assignment ended.')) await reload();
                      }
                    }}
                  >
                    <Text style={{ color: colors.danger }}>End</Text>
                  </Pressable>
                ) : (
                  <Chip label={a.status} />
                )
              }
            />
          ))}
        </Card>
      )}
      <FormModal
        visible={!!f}
        title="Assign to a site"
        busy={busy === 'save'}
        onClose={() => setF(null)}
        onSave={async () => {
          if (!f) return;
          const site = opts.sites.find((s) => String(s.value) === f.site_id);
          const ok = await run(
            'save',
            () =>
              api.post('/employee-site-assignments', {
                employee_id: Number(f.employee_id),
                site_id: Number(f.site_id),
                project_id: site?.project,
                start_date: f.start_date,
                end_date: f.end_date || null,
                assignment_type: f.assignment_type,
              }),
            'Assigned.',
          );
          if (ok) {
            setF(null);
            await reload();
          }
        }}
      >
        {error ? <Banner message={error} /> : null}
        {f ? (
          <>
            <PickerField label="Employee" required value={f.employee_id ? Number(f.employee_id) : null} options={opts.people} onChange={(v) => setF({ ...f, employee_id: String(v) })} />
            <PickerField label="Site" required value={f.site_id ? Number(f.site_id) : null} options={opts.sites} onChange={(v) => setF({ ...f, site_id: String(v) })} />
            <PickerField label="Type" value={f.assignment_type} options={[{ value: 'primary', label: 'Primary' }, { value: 'temporary', label: 'Temporary' }, { value: 'additional', label: 'Additional' }]} onChange={(v) => setF({ ...f, assignment_type: String(v) })} />
            <DateField label="From" required value={f.start_date} onChange={(v) => setF({ ...f, start_date: v })} />
            <DateField label="Until (optional)" value={f.end_date} onChange={(v) => setF({ ...f, end_date: v })} />
          </>
        ) : null}
      </FormModal>
    </Screen>
  );
}
