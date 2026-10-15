import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import { DateField } from '../components/DateField';
import { FormModal, MultiPickerField, PickerField, Tabs, type Option } from '../components/kit';
import { Banner, Button, Card, Chip, Empty, Field, Loading, Muted, Screen, colors } from '../components/ui';
import { api } from '../lib/api';
import { useAuth } from '../lib/auth';
import { prettyDate, ymd } from '../lib/format';
import { useAction, useLoad } from '../lib/hooks';
import { projectOptions } from '../lib/people';

type Plan = {
  id: number;
  visit_date: string;
  status: string;
  purpose: string;
  notes?: string | null;
  project_name: string;
  site_name?: string | null;
  employee_name?: string | null;
  assigned_by_name?: string | null;
  completion_note?: string | null;
  is_mine: boolean;
};

/** Visits a Project Manager plans for engineers, up to a month ahead. */
export default function VisitPlansScreen() {
  const { can } = useAuth();
  const manager = can('site_visit_plans.manage');
  const [tab, setTab] = useState<'upcoming' | 'completed' | 'missed'>('upcoming');
  const [scope, setScope] = useState<'mine' | 'all'>(manager ? 'all' : 'mine');
  const { data, error: loadError, loading, reload } = useLoad(
    () => api.get<{ items: Plan[] }>('/site-visit-plans', { status: tab, scope: scope === 'mine' ? 'mine' : undefined }).then((d) => d.items),
    [tab, scope],
  );
  const { busy, error, notice, run, setError } = useAction();
  const [planning, setPlanning] = useState(false);
  const [people, setPeople] = useState<Option[]>([]);
  const [projects, setProjects] = useState<Option[]>([]);
  const [f, setF] = useState({ employees: [] as number[], project: null as number | null, date: ymd(new Date()), purpose: '', notes: '' });

  async function startPlanning() {
    setError(null);
    const [a, p] = await Promise.all([
      api.get<{ items: { id: number; name: string; role?: string }[] }>('/site-visit-plans/assignees').then((d) => d.items).catch(() => []),
      projectOptions().catch(() => []),
    ]);
    setPeople(a.map((x) => ({ value: x.id, label: x.name, hint: x.role ?? null })));
    setProjects(p);
    setPlanning(true);
  }

  return (
    <Screen onRefresh={reload}>
      {manager ? <Button testID="plan-visit" label="Plan a visit" onPress={startPlanning} /> : null}
      {manager ? (
        <Tabs value={scope} onChange={setScope} options={[['all', 'Plans I made'], ['mine', 'My visits']]} />
      ) : null}
      <Tabs value={tab} onChange={setTab} options={[['upcoming', 'Upcoming'], ['completed', 'Completed'], ['missed', 'Missed']]} />
      {loadError ? <Banner message={loadError} /> : null}
      {error && !planning ? <Banner message={error} /> : null}
      {notice ? <Banner message={notice} tone="success" /> : null}
      {loading ? <Loading /> : !data?.length ? <Empty message="No visits here." /> : null}
      {data?.map((p) => (
        <Card key={p.id}>
          <View style={{ flexDirection: 'row', alignItems: 'center' }}>
            <Text style={{ flex: 1, fontWeight: '700', color: colors.text, fontSize: 16 }}>{prettyDate(p.visit_date)}</Text>
            <Chip label={p.status} tone={p.status === 'completed' ? 'success' : p.status === 'cancelled' ? 'neutral' : 'info'} />
          </View>
          <Text style={{ color: colors.text, marginTop: 4 }}>{[p.project_name, p.site_name].filter(Boolean).join(' · ')}</Text>
          <Muted>{p.purpose}{p.notes ? ` — ${p.notes}` : ''}</Muted>
          <Muted>{p.is_mine ? `Planned by ${p.assigned_by_name ?? '—'}` : `For ${p.employee_name ?? '—'}`}</Muted>
          {p.completion_note ? <Muted>Done: {p.completion_note}</Muted> : null}
          {p.status === 'planned' ? (
            <View style={{ flexDirection: 'row', gap: 16, marginTop: 8 }}>
              {p.is_mine ? (
                <Pressable onPress={async () => { if (await run('done', () => api.post(`/site-visit-plans/${p.id}/complete`, {}), 'Marked as done.')) await reload(); }}>
                  <Text style={{ color: colors.primary, fontWeight: '700' }}>✓ Mark done</Text>
                </Pressable>
              ) : null}
              {manager && !p.is_mine ? (
                <Pressable onPress={async () => { if (await run('cancel', () => api.post(`/site-visit-plans/${p.id}/cancel`), 'Plan cancelled.')) await reload(); }}>
                  <Text style={{ color: colors.danger, fontWeight: '700' }}>Cancel plan</Text>
                </Pressable>
              ) : null}
            </View>
          ) : null}
        </Card>
      ))}
      <FormModal
        visible={planning}
        title="Plan a site visit"
        busy={busy === 'plan'}
        onClose={() => setPlanning(false)}
        onSave={async () => {
          const ok = await run(
            'plan',
            () => api.post('/site-visit-plans', { employee_ids: f.employees, project_id: f.project, visit_date: f.date, purpose: f.purpose, notes: f.notes || null }),
            'Visit planned.',
          );
          if (ok) {
            setPlanning(false);
            setF({ employees: [], project: null, date: ymd(new Date()), purpose: '', notes: '' });
            await reload();
          }
        }}
      >
        {error ? <Banner message={error} /> : null}
        <MultiPickerField label="Who visits" values={f.employees} options={people} onChange={(v) => setF((s) => ({ ...s, employees: v as number[] }))} />
        <PickerField label="Project" required value={f.project} options={projects} onChange={(v) => setF((s) => ({ ...s, project: v as number }))} />
        <DateField label="Visit date" required value={f.date} onChange={(v) => setF((s) => ({ ...s, date: v }))} />
        <Field label="Purpose" required value={f.purpose} onChangeText={(v) => setF((s) => ({ ...s, purpose: v }))} />
        <Field label="Notes" value={f.notes} onChangeText={(v) => setF((s) => ({ ...s, notes: v }))} />
        <Muted>Visits can be planned up to a month ahead.</Muted>
      </FormModal>
    </Screen>
  );
}
