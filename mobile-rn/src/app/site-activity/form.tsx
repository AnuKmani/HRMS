import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useEffect, useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import { DateField } from '../../components/DateField';
import { PickerField, type Option } from '../../components/kit';
import { Banner, Button, Field, Loading, Muted, Screen, colors } from '../../components/ui';
import { api, ApiError, type Page } from '../../lib/api';
import { ymd } from '../../lib/format';
import { WORK_CATEGORIES, reportGps, type SiteActivityReport } from '../../lib/siteActivity';

type Site = { id: number; name: string; project_id: number; project?: { name: string } | null };

const EMPTY = { work_category: '', work_performed: '', progress_percentage: '', manpower: '', materials_used: '', equipment_used: '', issues: '', safety_issues: '', remarks: '' };

/** Write (or correct, while it is still a draft) a site activity report. */
export default function SiteActivityForm() {
  const { id } = useLocalSearchParams<{ id?: string }>();
  const router = useRouter();
  const editing = !!id;
  const [sites, setSites] = useState<Site[]>([]);
  const [loading, setLoading] = useState(true);
  const [siteId, setSiteId] = useState<number | null>(null);
  const [date, setDate] = useState(ymd(new Date()));
  const [text, setText] = useState(EMPTY);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const site = sites.find((s) => s.id === siteId);

  useEffect(() => {
    (async () => {
      try {
        const page = await api.get<Page<Site>>('/site-activity-reports/reportable-sites', { per_page: 200 });
        setSites(page.items);
        if (editing) {
          const r = await api.get<SiteActivityReport>(`/site-activity-reports/${id}`);
          setSiteId(r.site?.id ?? null);
          setDate(r.report_date);
          setText({
            work_category: r.work_category ?? '',
            work_performed: r.work_performed ?? '',
            progress_percentage: String(r.progress_percentage ?? ''),
            manpower: r.manpower ?? '',
            materials_used: r.materials_used ?? '',
            equipment_used: r.equipment_used ?? '',
            issues: r.issues ?? '',
            safety_issues: r.safety_issues ?? '',
            remarks: r.remarks ?? '',
          });
        } else if (page.items.length === 1) {
          setSiteId(page.items[0].id);
        }
      } catch (e) {
        setError((e as ApiError).message);
      } finally {
        setLoading(false);
      }
    })();
  }, [editing, id]);

  const options: Option[] = sites.map((s) => ({ value: s.id, label: s.name, hint: s.project?.name ?? null }));
  const setT = (k: keyof typeof EMPTY) => (v: string) => setText((t) => ({ ...t, [k]: v }));

  async function save(submit: boolean) {
    setError(null);
    setErrors({});
    if (!siteId || !site) return setErrors({ site_id: 'Choose the site.' });
    setBusy(submit ? 'submit' : 'save');
    try {
      // Where it was written is recorded when it can be; it is required to submit.
      let gps: Awaited<ReturnType<typeof reportGps>> | null = null;
      try {
        gps = await reportGps();
      } catch (e) {
        if (submit) throw e;
      }
      const body = {
        site_id: siteId,
        project_id: site.project_id,
        report_date: date,
        ...text,
        progress_percentage: text.progress_percentage === '' ? '' : Math.min(100, Math.max(0, Math.round(Number(text.progress_percentage) || 0))),
        ...(gps ?? {}),
      };
      const saved = editing ? await api.put<SiteActivityReport>(`/site-activity-reports/${id}`, body) : await api.post<SiteActivityReport>('/site-activity-reports', body);
      if (submit && saved.is_draft) await api.post(`/site-activity-reports/${saved.id}/submit`, gps ?? {});
      router.replace(`/site-activity/${saved.id}`);
    } catch (e) {
      const err = e as ApiError;
      setError(err.message);
      setErrors(err.errors ?? {});
    } finally {
      setBusy(null);
    }
  }

  if (loading) return <Loading />;

  return (
    <Screen>
      <Stack.Screen options={{ title: editing ? 'Edit activity report' : 'New activity report' }} />
      {error ? <Banner message={error} /> : null}
      <PickerField label="Site" required value={siteId} options={options} onChange={(v) => setSiteId(v as number)} error={errors.site_id ?? errors.project_id} />
      <DateField label="Date" required value={date} onChange={setDate} error={errors.report_date} />
      <Field label="Work category" required value={text.work_category} onChangeText={setT('work_category')} error={errors.work_category} maxLength={60} />
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: -6, marginBottom: 14 }}>
        {WORK_CATEGORIES.map((c) => (
          <Pressable
            key={c}
            onPress={() => setT('work_category')(c)}
            style={{ paddingHorizontal: 10, paddingVertical: 5, borderRadius: 14, borderWidth: 1, borderColor: text.work_category === c ? colors.primary : colors.border }}
          >
            <Text style={{ color: text.work_category === c ? colors.primary : colors.text, fontSize: 13 }}>{c}</Text>
          </Pressable>
        ))}
      </View>
      <Field label="Work performed" required value={text.work_performed} onChangeText={setT('work_performed')} multiline error={errors.work_performed} />
      <Field label="Progress (%)" required value={text.progress_percentage} onChangeText={setT('progress_percentage')} keyboardType="number-pad" error={errors.progress_percentage} />
      <Field label="Manpower" value={text.manpower} onChangeText={setT('manpower')} multiline placeholder="e.g. 4 masons, 6 helpers" error={errors.manpower} />
      <Field label="Materials used" value={text.materials_used} onChangeText={setT('materials_used')} multiline error={errors.materials_used} />
      <Field label="Equipment used" value={text.equipment_used} onChangeText={setT('equipment_used')} multiline error={errors.equipment_used} />
      <Field label="Issues" value={text.issues} onChangeText={setT('issues')} multiline error={errors.issues} />
      <Field label="Safety issues" value={text.safety_issues} onChangeText={setT('safety_issues')} multiline error={errors.safety_issues} />
      <Field label="Remarks" value={text.remarks} onChangeText={setT('remarks')} multiline error={errors.remarks} />
      {errors.gps_accuracy || errors.latitude ? <Banner message={errors.gps_accuracy ?? errors.latitude} /> : null}
      <Muted>Your location is recorded with the report. Add photos after saving.</Muted>
      <Button label="Save and submit" onPress={() => save(true)} busy={busy === 'submit'} />
      <Button label="Save as draft" kind="outline" onPress={() => save(false)} busy={busy === 'save'} />
    </Screen>
  );
}
