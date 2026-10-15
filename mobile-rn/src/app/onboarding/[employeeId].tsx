import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useState } from 'react';
import { Text, View } from 'react-native';

import { PickerField, SectionHeader, confirm } from '../../components/kit';
import { Banner, Button, Card, Chip, Field, Loading, Muted, ProgressBar, Screen, colors } from '../../components/ui';
import { api } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { useAction, useLoad } from '../../lib/hooks';
import { ONBOARDING_STATUS, onboardingTone } from './index';

type Requirement = { code: string; name: string; description?: string | null; is_mandatory: boolean; state: string; missing_fields?: string[] | null; document?: { id: number } | null };
type Detail = {
  employee_id: number;
  employee?: { full_name?: string } | null;
  status: string;
  is_completed: boolean;
  notes?: string | null;
  requirements: Requirement[];
  totals: { total: number; satisfied: number };
  can_complete: boolean;
};

const STATE: Record<string, [string, 'success' | 'warning' | 'danger' | 'neutral']> = {
  satisfied: ['Done', 'success'],
  pending_verification: ['To verify', 'warning'],
  rejected: ['Rejected', 'danger'],
  expired: ['Expired', 'danger'],
  missing: ['Missing', 'neutral'],
};

export default function OnboardingDetail() {
  const { employeeId } = useLocalSearchParams<{ employeeId: string }>();
  const router = useRouter();
  const { can } = useAuth();
  const manage = can('onboarding.manage');
  const { data: d, error: loadError, loading, reload } = useLoad(() => api.get<Detail>(`/onboarding/${employeeId}`), [employeeId]);
  const { busy, error, notice, run } = useAction();
  const [notes, setNotes] = useState<string | null>(null);

  if (loading) return <Loading />;
  if (!d) return <Screen>{loadError ? <Banner message={loadError} /> : null}</Screen>;
  const pct = d.totals.total ? (d.totals.satisfied / d.totals.total) * 100 : 0;
  const act = async (key: string, fn: () => Promise<unknown>, done: string) => {
    if (await run(key, fn, done)) await reload();
  };

  return (
    <Screen onRefresh={reload}>
      <Stack.Screen options={{ title: d.employee?.full_name ?? 'Onboarding' }} />
      {error ? <Banner message={error} /> : null}
      {notice ? <Banner message={notice} tone="success" /> : null}
      <Card>
        <View style={{ flexDirection: 'row', alignItems: 'center', marginBottom: 8 }}>
          <Text style={{ flex: 1, fontSize: 18, fontWeight: '700', color: colors.text }}>{d.employee?.full_name}</Text>
          <Chip label={ONBOARDING_STATUS[d.status] ?? d.status} tone={onboardingTone(d.status)} />
        </View>
        <Muted>
          {d.totals.satisfied} of {d.totals.total} done
        </Muted>
        <View style={{ marginTop: 8 }}>
          <ProgressBar value={pct} />
        </View>
      </Card>
      <Card>
        <SectionHeader title="Checklist" action={can('documents.create') ? 'Upload' : undefined} onAction={() => router.push('/documents/new')} />
        {d.requirements.map((r) => {
          const s = STATE[r.state] ?? [r.state, 'neutral'];
          return (
            <View key={r.code} style={{ flexDirection: 'row', alignItems: 'center', paddingVertical: 8 }}>
              <View style={{ flex: 1 }}>
                <Text style={{ fontWeight: '600', color: colors.text }}>
                  {r.name}
                  {r.is_mandatory ? ' *' : ''}
                </Text>
                {r.missing_fields?.length ? <Muted>Missing: {r.missing_fields.join(', ').replace(/_/g, ' ')}</Muted> : r.description ? <Muted>{r.description}</Muted> : null}
              </View>
              <Chip label={s[0]} tone={s[1]} />
            </View>
          );
        })}
        <Muted>* required before onboarding can be completed. Documents are uploaded and verified in Documents.</Muted>
      </Card>
      {manage && !d.is_completed ? (
        <Card>
          <PickerField
            label="Stage"
            value={d.status}
            options={Object.entries(ONBOARDING_STATUS)
              .filter(([k]) => k !== 'completed')
              .map(([value, label]) => ({ value, label }))}
            onChange={(v) => act('status', () => api.put(`/onboarding/${d.employee_id}`, { status: v }), 'Stage updated.')}
          />
          <Field label="Notes" value={notes ?? d.notes ?? ''} onChangeText={setNotes} multiline />
          {notes !== null ? <Button label="Save notes" kind="outline" busy={busy === 'notes'} onPress={() => act('notes', () => api.put(`/onboarding/${d.employee_id}`, { notes }), 'Notes saved.')} /> : null}
          <Button
            label="Complete onboarding"
            busy={busy === 'complete'}
            onPress={async () => {
              if (!d.can_complete) return void (await confirm('Not ready yet', 'Every required item must be done first.', 'OK'));
              if (await confirm('Complete onboarding?', `${d.employee?.full_name} is marked fully onboarded.`, 'Complete')) await act('complete', () => api.post(`/onboarding/${d.employee_id}/complete`), 'Onboarding completed.');
            }}
          />
        </Card>
      ) : null}
    </Screen>
  );
}
