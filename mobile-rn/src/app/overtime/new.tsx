import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useEffect, useState } from 'react';

import { DateField } from '../../components/DateField';
import { PickerField, type Option } from '../../components/kit';
import { Banner, Button, Field, Muted, Screen } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { ymd } from '../../lib/format';
import { projectOptions, siteOptions } from '../../lib/people';

type Draft = { overtime_date: string; requested_minutes: number; project_id: number | null; site_id: number | null; reason: string | null };

export default function NewOvertime() {
  const router = useRouter();
  const { id: editId } = useLocalSearchParams<{ id?: string }>();
  const [date, setDate] = useState(ymd(new Date()));
  const [hours, setHours] = useState('');
  const [minutes, setMinutes] = useState('');
  const [projectId, setProjectId] = useState<number | null>(null);
  const [siteId, setSiteId] = useState<number | null>(null);
  const [reason, setReason] = useState('');
  const [opts, setOpts] = useState<{ projects: Option[]; sites: Option[] }>({ projects: [], sites: [] });
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    Promise.all([projectOptions().catch(() => []), siteOptions().catch(() => [])]).then(([projects, sites]) => setOpts({ projects, sites }));
    if (editId) {
      api.get<Draft>(`/overtime/${editId}`).then((o) => {
        setDate(o.overtime_date);
        setHours(String(Math.floor(o.requested_minutes / 60)));
        setMinutes(String(o.requested_minutes % 60));
        setProjectId(o.project_id);
        setSiteId(o.site_id);
        setReason(o.reason ?? '');
      }, (e) => setError((e as ApiError).message));
    }
  }, [editId]);

  async function save(submit: boolean) {
    setBusy(submit ? 'submit' : 'draft');
    setError(null);
    setErrors({});
    try {
      const total = (Number(hours) || 0) * 60 + (Number(minutes) || 0);
      const payload = { overtime_date: date, requested_minutes: total, project_id: projectId, site_id: siteId, reason };
      const o = editId ? await api.put<{ id: number }>(`/overtime/${editId}`, payload) : await api.post<{ id: number }>('/overtime', payload);
      if (submit) await api.post(`/overtime/${o.id}/submit`);
      if (editId) router.back();
      else router.replace(`/overtime/${o.id}`);
    } catch (e) {
      const err = e as ApiError;
      setError(err.message);
      setErrors(err.errors);
    } finally {
      setBusy(null);
    }
  }

  return (
    <Screen>
      {editId ? <Stack.Screen options={{ title: 'Edit overtime' }} /> : null}
      {error ? <Banner message={error} /> : null}
      <DateField label="Date worked" required value={date} onChange={setDate} />
      <Field label="Extra hours" value={hours} onChangeText={setHours} keyboardType="number-pad" error={errors.requested_minutes} />
      <Field label="Extra minutes" value={minutes} onChangeText={setMinutes} keyboardType="number-pad" />
      <PickerField label="Project" allowClear value={projectId} options={opts.projects} onChange={(v) => setProjectId(v as number | null)} error={errors.project_id} />
      <PickerField label="Site" allowClear value={siteId} options={opts.sites} onChange={(v) => setSiteId(v as number | null)} error={errors.site_id} />
      <Field label="Reason" required value={reason} onChangeText={setReason} multiline error={errors.reason} />
      <Muted>Submitting sends it for approval. A draft can be changed and submitted later.</Muted>
      <Screen.Spacer />
      <Button label="Submit for approval" onPress={() => save(true)} busy={busy === 'submit'} />
      <Button label="Save as draft" kind="outline" onPress={() => save(false)} busy={busy === 'draft'} />
    </Screen>
  );
}
