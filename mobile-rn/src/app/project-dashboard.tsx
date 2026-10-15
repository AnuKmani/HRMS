import { useRouter } from 'expo-router';
import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import { Banner, Card, Chip, Empty, Field, Loading, Muted, ProgressBar, Screen, colors } from '../components/ui';
import { api } from '../lib/api';
import { useLoad } from '../lib/hooks';
import { money, pct, statusName } from '../lib/projects';

type Line = { id: number; name: string; progress_percent?: number; value?: number; billed_amount: number; billed_percent: number | null; contract_value?: number; system?: string | null };
type ProjectRow = {
  id: number;
  name: string;
  code: string;
  status: string;
  project_manager?: string | null;
  progress_percent: number;
  behind_schedule: boolean;
  value_total: number;
  billed_total: number;
  billed_percent: number | null;
  systems: Line[];
  subcontractors: Line[];
  subcontract_value_total: number;
  subcontract_billed_total: number;
  subcontract_billed_percent: number | null;
};
type Dashboard = { totals: Record<string, number | null>; items: ProjectRow[] };

/** Project Manager and above: progress, value and billing for every project. */
export default function ProjectDashboardScreen() {
  const router = useRouter();
  const [search, setSearch] = useState('');
  const [query, setQuery] = useState('');
  const [open, setOpen] = useState<number | null>(null);
  const { data, error, loading, reload } = useLoad(() => api.get<Dashboard>('/project-dashboard', { search: query || undefined }), [query]);

  if (loading) return <Loading />;
  const t = data?.totals ?? {};

  return (
    <Screen onRefresh={reload}>
      {error ? <Banner message={error} /> : null}
      <Card>
        <Text style={{ fontSize: 17, fontWeight: '700', color: colors.text }}>All projects ({t.projects ?? 0})</Text>
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 18, marginTop: 10 }}>
          <Figure label="Progress" value={pct(t.progress_percent)} />
          <Figure label="Project value" value={money(t.value_total)} />
          <Figure label="Billed by us" value={money(t.billed_total)} sub={pct(t.billed_percent)} />
          <Figure label="Subcontracts" value={money(t.subcontract_value_total)} sub={`billed ${money(t.subcontract_billed_total)} (${pct(t.subcontract_billed_percent)})`} />
        </View>
        {t.behind_schedule ? <View style={{ marginTop: 10 }}><Chip label={`${t.behind_schedule} project(s) behind schedule`} tone="danger" /></View> : null}
      </Card>
      <Field label="Search" value={search} onChangeText={setSearch} onSubmitEditing={() => setQuery(search.trim())} placeholder="Project, code or client" returnKeyType="search" />
      {!data?.items.length ? <Empty message="No projects." /> : null}
      {data?.items.map((p) => (
        <Card key={p.id}>
          <Pressable onPress={() => setOpen(open === p.id ? null : p.id)}>
            <View style={{ flexDirection: 'row', alignItems: 'center' }}>
              <Text style={{ flex: 1, fontSize: 16, fontWeight: '700', color: colors.text }}>{p.name}</Text>
              {p.behind_schedule ? <Chip label="Behind" tone="danger" /> : null}
            </View>
            <Muted>{[p.code, statusName(p.status), p.project_manager ? `PM: ${p.project_manager}` : null].filter(Boolean).join(' · ')}</Muted>
            <ProgressBar value={p.progress_percent} />
            <Text style={{ color: colors.text }}>
              {pct(p.progress_percent)} · value {money(p.value_total)} · billed {money(p.billed_total)} ({pct(p.billed_percent)})
            </Text>
            <Text style={{ color: colors.primary, marginTop: 4 }}>{open === p.id ? 'Hide details ▲' : 'Systems & subcontractors ▼'}</Text>
          </Pressable>
          {open === p.id ? (
            <View style={{ marginTop: 10 }}>
              <Text style={{ fontWeight: '700', color: colors.text, marginBottom: 4 }}>By system</Text>
              {p.systems.length === 0 ? <Muted>No systems set.</Muted> : null}
              {p.systems.map((s) => (
                <LineView key={s.id} title={s.name} bar={s.progress_percent ?? 0} detail={`Progress ${pct(s.progress_percent)} · value ${money(s.value)} · billed ${money(s.billed_amount)} (${pct(s.billed_percent)})`} />
              ))}
              <Total text={`Total: progress ${pct(p.progress_percent)} · value ${money(p.value_total)} · billed ${money(p.billed_total)} (${pct(p.billed_percent)})`} />
              <Text style={{ fontWeight: '700', color: colors.text, marginTop: 12, marginBottom: 4 }}>Installation subcontractors</Text>
              {p.subcontractors.length === 0 ? <Muted>None.</Muted> : null}
              {p.subcontractors.map((c) => (
                <LineView key={c.id} title={[c.name, c.system].filter(Boolean).join(' · ')} bar={c.billed_percent ?? 0} detail={`Contract ${money(c.contract_value)} · billed ${money(c.billed_amount)} (${pct(c.billed_percent)})`} />
              ))}
              {p.subcontractors.length ? (
                <Total text={`Total: contract ${money(p.subcontract_value_total)} · billed ${money(p.subcontract_billed_total)} (${pct(p.subcontract_billed_percent)})`} />
              ) : null}
              <Pressable onPress={() => router.push(`/projects/${p.id}`)} style={{ marginTop: 10 }}>
                <Text style={{ color: colors.primary, fontWeight: '700' }}>Open project →</Text>
              </Pressable>
            </View>
          ) : null}
        </Card>
      ))}
    </Screen>
  );
}

function Figure({ label, value, sub }: { label: string; value: string; sub?: string }) {
  return (
    <View>
      <Muted>{label}</Muted>
      <Text style={{ fontSize: 17, fontWeight: '800', color: colors.text }}>{value}</Text>
      {sub ? <Muted>{sub}</Muted> : null}
    </View>
  );
}

function LineView({ title, bar, detail }: { title: string; bar: number; detail: string }) {
  return (
    <View style={{ paddingVertical: 6 }}>
      <Text style={{ fontWeight: '600', color: colors.text }}>{title}</Text>
      <ProgressBar value={bar} />
      <Muted>{detail}</Muted>
    </View>
  );
}

function Total({ text }: { text: string }) {
  return (
    <View style={{ backgroundColor: '#EEF2FF', borderRadius: 8, padding: 8, marginTop: 4 }}>
      <Text style={{ fontWeight: '700', color: colors.text }}>{text}</Text>
    </View>
  );
}
