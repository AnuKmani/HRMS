import { useRouter } from 'expo-router';
import { useState } from 'react';
import { Switch, Text, View } from 'react-native';

import { DateField } from '../../components/DateField';
import { FormModal, PickerField, Tabs, type Option } from '../../components/kit';
import { Banner, Button, Card, Chip, Empty, Field, Loading, Row, Screen, colors } from '../../components/ui';
import { api, type Page } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { prettyDate, ymd } from '../../lib/format';
import { useAction, useLoad } from '../../lib/hooks';
import { employeeOptions } from '../../lib/people';
import { TRAINING_STATUS, trainingTone, type Enrolment, type Program } from '../../lib/training';

type TabKey = 'mine' | 'all' | 'programs' | 'expiring';
type ProgForm = { id?: string; training_type_id: string; code: string; name: string; provider: string; duration_days: string; certificate_validity_days: string; certificate_required: boolean; status?: string };

export default function TrainingScreen() {
  const router = useRouter();
  const { can, user } = useAuth();
  const tabs: [TabKey, string][] = [['mine', 'My training']];
  if (can('training.assign') || can('employees.view')) tabs.push(['all', 'Everyone']);
  tabs.push(['programs', 'Programmes']);
  if (can('training.expiry.view')) tabs.push(['expiring', 'Expiring']);
  const [tab, setTab] = useState<TabKey>('mine');

  const { data, error: loadError, loading, reload } = useLoad<(Enrolment | Program)[]>(() => {
    if (tab === 'programs') return api.get<Page<Program>>('/training-programs', { per_page: 200 }).then((p) => p.items);
    if (tab === 'expiring') return api.get<Page<Enrolment>>('/employee-training/expiring', { within: 60, per_page: 200 }).then((p) => p.items);
    return api.get<Page<Enrolment>>('/employee-training', { per_page: 200, ...(tab === 'mine' ? { employee_id: user?.employee?.id } : {}) }).then((p) => p.items);
  }, [tab]);

  const { busy, error, notice, run, setError } = useAction();
  const [assign, setAssign] = useState<Record<string, string> | null>(null);
  const [prog, setProg] = useState<ProgForm | null>(null);
  const [opts, setOpts] = useState<{ people: Option[]; programs: Option[]; types: Option[] }>({ people: [], programs: [], types: [] });

  async function loadOpts() {
    const [people, programs, types] = await Promise.all([
      employeeOptions().catch(() => []),
      api.get<Page<Program>>('/training-programs', { per_page: 200 }).then((p) => p.items.filter((x) => x.status === 'active').map((x) => ({ value: x.id, label: x.name, hint: x.code }))).catch(() => []),
      api.get<{ id: number; name: string }[] | Page<{ id: number; name: string }>>('/training-types').then((r) => (Array.isArray(r) ? r : r.items).map((t) => ({ value: t.id, label: t.name }))).catch(() => []),
    ]);
    setOpts({ people, programs, types });
  }

  const done = async (ok: boolean) => {
    if (ok) {
      setAssign(null);
      setProg(null);
      await reload();
    }
  };

  return (
    <Screen onRefresh={reload}>
      {can('training.assign') && tab !== 'programs' ? (
        <Button
          label="Enrol someone in training"
          onPress={async () => {
            setError(null);
            await loadOpts();
            setAssign({ employee_id: '', training_program_id: '', enrollment_date: ymd(new Date()), training_date: '', trainer: '' });
          }}
        />
      ) : null}
      {can('training.create') && tab === 'programs' ? (
        <Button
          label="Add programme"
          onPress={async () => {
            setError(null);
            await loadOpts();
            setProg({ training_type_id: '', code: '', name: '', provider: '', duration_days: '', certificate_validity_days: '', certificate_required: false });
          }}
        />
      ) : null}
      <Tabs value={tab} onChange={setTab} options={tabs} />
      {can('training.expiry.view') || can('training.assign') ? (
        <Button label="Training compliance" kind="outline" onPress={() => router.push('/training-compliance')} />
      ) : null}
      {loadError ? <Banner message={loadError} /> : null}
      {notice ? <Banner message={notice} tone="success" /> : null}
      {loading ? (
        <Loading />
      ) : !data?.length ? (
        <Empty message={tab === 'programs' ? 'No programmes yet.' : tab === 'expiring' ? 'No certificates expiring in 60 days.' : 'No training.'} />
      ) : tab === 'programs' ? (
        <Card>
          {(data as Program[]).map((p) => (
            <Row
              key={p.id}
              title={`${p.name} (${p.code})`}
              subtitle={[p.training_type?.name, p.provider, p.duration_days ? `${p.duration_days} day(s)` : null, p.certificate_required ? `certificate${p.certificate_validity_days ? `, valid ${p.certificate_validity_days} d` : ''}` : null]
                .filter(Boolean)
                .join(' · ')}
              right={p.status !== 'active' ? <Chip label="Retired" /> : undefined}
              onPress={
                can('training.update')
                  ? async () => {
                      setError(null);
                      await loadOpts();
                      setProg({
                        id: String(p.id),
                        training_type_id: String(p.training_type_id),
                        code: p.code,
                        name: p.name,
                        provider: p.provider ?? '',
                        duration_days: p.duration_days ? String(p.duration_days) : '',
                        certificate_validity_days: p.certificate_validity_days ? String(p.certificate_validity_days) : '',
                        certificate_required: p.certificate_required,
                        status: p.status,
                      });
                    }
                  : undefined
              }
            />
          ))}
        </Card>
      ) : (
        <Card>
          {(data as Enrolment[]).map((e) => (
            <Row
              key={e.id}
              title={`${tab !== 'mine' ? `${e.employee?.full_name ?? ''} · ` : ''}${e.training_program?.name ?? 'Training'}`}
              subtitle={[
                e.training_date ? `on ${prettyDate(e.training_date)}` : `enrolled ${prettyDate(e.enrollment_date)}`,
                e.certificate_expiry_date ? `certificate until ${prettyDate(e.certificate_expiry_date)}` : null,
              ]
                .filter(Boolean)
                .join(' · ')}
              right={<Chip label={TRAINING_STATUS[e.status] ?? e.status} tone={trainingTone(e.status)} />}
              onPress={() => router.push(`/training/${e.id}`)}
            />
          ))}
        </Card>
      )}

      <FormModal
        visible={!!assign}
        title="Enrol in training"
        saveLabel="Enrol"
        busy={busy === 'assign'}
        onClose={() => setAssign(null)}
        onSave={() =>
          assign &&
          run(
            'assign',
            () =>
              api.post('/employee-training', {
                employee_id: Number(assign.employee_id),
                training_program_id: Number(assign.training_program_id),
                enrollment_date: assign.enrollment_date,
                training_date: assign.training_date || null,
                trainer: assign.trainer || null,
              }),
            'Enrolled.',
          ).then(done)
        }
      >
        {error ? <Banner message={error} /> : null}
        {assign ? (
          <>
            <PickerField label="Employee" required value={assign.employee_id ? Number(assign.employee_id) : null} options={opts.people} onChange={(v) => setAssign({ ...assign, employee_id: String(v) })} />
            <PickerField label="Programme" required value={assign.training_program_id ? Number(assign.training_program_id) : null} options={opts.programs} onChange={(v) => setAssign({ ...assign, training_program_id: String(v) })} />
            <DateField label="Enrolled on" required value={assign.enrollment_date} onChange={(v) => setAssign({ ...assign, enrollment_date: v })} />
            <DateField label="Training date (optional)" value={assign.training_date} onChange={(v) => setAssign({ ...assign, training_date: v })} />
            <Field label="Trainer" value={assign.trainer} onChangeText={(v) => setAssign({ ...assign, trainer: v })} />
          </>
        ) : null}
      </FormModal>

      <FormModal
        visible={!!prog}
        title={prog?.id ? 'Edit programme' : 'New programme'}
        busy={busy === 'prog'}
        onClose={() => setProg(null)}
        onSave={() => {
          if (!prog) return;
          const body = {
            training_type_id: Number(prog.training_type_id) || null,
            code: prog.code,
            name: prog.name,
            provider: prog.provider || null,
            duration_days: prog.duration_days ? Number(prog.duration_days) : null,
            certificate_required: !!prog.certificate_required,
            certificate_validity_days: prog.certificate_validity_days ? Number(prog.certificate_validity_days) : null,
            ...(prog.status ? { status: prog.status } : {}),
          };
          run('prog', () => (prog.id ? api.put(`/training-programs/${prog.id}`, body) : api.post('/training-programs', body)), 'Saved.').then(done);
        }}
      >
        {error ? <Banner message={error} /> : null}
        {prog ? (
          <>
            <PickerField label="Type" required value={prog.training_type_id ? Number(prog.training_type_id) : null} options={opts.types} onChange={(v) => setProg({ ...prog, training_type_id: String(v) })} />
            <Field label="Code" required value={prog.code} onChangeText={(v) => setProg({ ...prog, code: v.toUpperCase() })} autoCapitalize="characters" />
            <Field label="Name" required value={prog.name} onChangeText={(v) => setProg({ ...prog, name: v })} />
            <Field label="Provider" value={prog.provider} onChangeText={(v) => setProg({ ...prog, provider: v })} />
            <Field label="Duration (days)" value={prog.duration_days} onChangeText={(v) => setProg({ ...prog, duration_days: v })} keyboardType="number-pad" />
            <View style={{ flexDirection: 'row', alignItems: 'center', marginVertical: 8 }}>
              <Text style={{ flex: 1, color: colors.text }}>Gives a certificate</Text>
              <Switch value={!!prog.certificate_required} onValueChange={(v) => setProg({ ...prog, certificate_required: v })} />
            </View>
            {prog.certificate_required ? (
              <Field label="Certificate valid for (days)" value={prog.certificate_validity_days} onChangeText={(v) => setProg({ ...prog, certificate_validity_days: v })} keyboardType="number-pad" />
            ) : null}
            {prog.id ? (
              <PickerField label="Status" value={prog.status} options={[{ value: 'active', label: 'Active' }, { value: 'retired', label: 'Retired' }]} onChange={(v) => setProg({ ...prog, status: String(v) })} />
            ) : null}
          </>
        ) : null}
      </FormModal>
    </Screen>
  );
}
