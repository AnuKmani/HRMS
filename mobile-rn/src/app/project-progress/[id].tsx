import { Stack, useLocalSearchParams } from 'expo-router';
import { Pressable, Text, View } from 'react-native';

import { SectionHeader } from '../../components/kit';
import { Banner, Card, Chip, Loading, Muted, ProgressBar, Row, Screen, colors } from '../../components/ui';
import { api } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { openRemoteFile } from '../../lib/files';
import { useAction, useLoad } from '../../lib/hooks';
import { money, pct, statusName, type Milestone, type Stage } from '../../lib/projects';

type Progress = {
  id: number;
  name: string;
  code: string;
  status: string;
  start_date?: string | null;
  end_date?: string | null;
  progress_percent: number;
  expected_percent?: number | null;
  behind_schedule: boolean;
  stages: Stage[];
  payment_milestones: Milestone[];
};

/** One project's progress: stages, milestones, PDF. */
export default function ProjectProgressDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { can } = useAuth();
  const { data: p, error: loadError, loading, reload } = useLoad(() => api.get<Progress>(`/project-progress/${id}`), [id]);
  const { error, notice, run } = useAction();
  if (loading) return <Loading />;
  if (!p) return <Screen>{loadError ? <Banner message={loadError} /> : null}</Screen>;

  return (
    <Screen onRefresh={reload}>
      <Stack.Screen options={{ title: p.name }} />
      {error ? <Banner message={error} /> : null}
      {notice ? <Banner message={notice} tone="success" /> : null}
      <Card>
        <SectionHeader title="Progress" action="PDF" onAction={() => run('pdf', () => openRemoteFile(`/project-progress/${p.id}/pdf`, `progress-${p.code}.pdf`))} />
        <Muted>{[p.code, statusName(p.status), p.start_date || p.end_date ? `${p.start_date ?? '?'} → ${p.end_date ?? '?'}` : null].filter(Boolean).join(' · ')}</Muted>
        <View style={{ flexDirection: 'row', alignItems: 'center', marginTop: 8 }}>
          <Text style={{ fontSize: 30, fontWeight: '800', color: colors.text, flex: 1 }}>{pct(p.progress_percent)}</Text>
          {p.behind_schedule ? <Chip label="Behind schedule" tone="danger" /> : null}
        </View>
        <ProgressBar value={p.progress_percent} />
        {p.expected_percent !== null && p.expected_percent !== undefined ? <Muted>Expected by today: {pct(p.expected_percent)}</Muted> : null}
        {p.stages.map((s) => (
          <View key={s.id} style={{ marginTop: 8 }}>
            <Text style={{ color: colors.text }}>
              {s.name} · {pct(s.percent_complete)}
            </Text>
            <ProgressBar value={s.percent_complete} />
          </View>
        ))}
      </Card>
      <Card>
        <SectionHeader title="Payment milestones" />
        {p.payment_milestones.length === 0 ? <Muted>The Project Manager has not set any milestones yet.</Muted> : null}
        {p.payment_milestones.map((m) => (
          <Row
            key={m.id}
            title={m.name}
            subtitle={[`at ${pct(m.trigger_percent)}`, m.amount ? money(m.amount) : null].filter(Boolean).join(' · ')}
            right={
              <View style={{ alignItems: 'flex-end', gap: 4 }}>
                <Chip label={m.status} tone={m.status === 'paid' ? 'success' : m.status === 'reached' ? 'warning' : 'neutral'} />
                {can('projects.billing.manage') && (m.status === 'reached' || m.status === 'requested') ? (
                  <Pressable onPress={async () => { if (await run('ms', () => api.post(`/projects/${p.id}/milestones/${m.id}/status`, { status: m.status === 'reached' ? 'requested' : 'paid' }), 'Milestone updated.')) await reload(); }}>
                    <Text style={{ color: colors.primary, fontWeight: '700' }}>{m.status === 'reached' ? 'Mark requested' : 'Mark paid'}</Text>
                  </Pressable>
                ) : null}
              </View>
            }
          />
        ))}
      </Card>
    </Screen>
  );
}
