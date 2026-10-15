import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useEffect, useState } from 'react';
import { Text, TextInput, View } from 'react-native';

import { DateField } from '../../components/DateField';
import { PickerField, type Option } from '../../components/kit';
import { RowsEditor } from '../../components/RowsEditor';
import { Banner, Button, Field, Loading, Muted, Screen, colors } from '../../components/ui';
import { api, ApiError, type Page } from '../../lib/api';
import { ymd } from '../../lib/format';
import type { DailyReport } from '../../lib/reports';

type Site = { id: number; name: string; project_id: number; project?: { name: string } | null };
type StageRow = { id: number; name: string; percent_complete: number };

/** Write (or correct, on the same day) the daily report for a site. */
export default function SiteReportForm() {
  const { id } = useLocalSearchParams<{ id?: string }>();
  const router = useRouter();
  const editing = !!id;
  const [sites, setSites] = useState<Site[]>([]);
  const [loading, setLoading] = useState(true);
  const [siteId, setSiteId] = useState<number | null>(null);
  const [date, setDate] = useState(ymd(new Date()));
  const [text, setText] = useState({ work_planned: '', work_completed: '', safety_observations: '', delays: '', issues: '', remarks: '' });
  const [manpower, setManpower] = useState<Record<string, string>[]>([{ category: 'Labour', count: '' }]);
  const [materials, setMaterials] = useState<Record<string, string>[]>([]);
  const [equipment, setEquipment] = useState<Record<string, string>[]>([]);
  const [stages, setStages] = useState<StageRow[]>([]);
  const [stagePct, setStagePct] = useState<Record<number, string>>({});
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
          const r = await api.get<DailyReport>(`/daily-site-reports/${id}`);
          setSiteId(r.site_id);
          setDate(r.report_date);
          setText({
            work_planned: r.work_planned ?? '',
            work_completed: r.work_completed ?? '',
            safety_observations: r.safety_observations ?? '',
            delays: r.delays ?? '',
            issues: r.issues ?? '',
            remarks: r.remarks ?? '',
          });
          setManpower(r.manpower.map((m) => ({ category: m.category, count: String(m.count) })));
          setMaterials(r.materials.map((m) => ({ material_name: m.material_name, quantity: String(m.quantity), unit: m.unit ?? '', remarks: m.remarks ?? '' })));
          setEquipment(r.equipment.map((e) => ({ equipment_name: e.equipment_name, quantity: String(e.quantity), operating_hours: e.operating_hours ? String(e.operating_hours) : '', condition: e.condition ?? '' })));
          setStagePct(Object.fromEntries(r.stage_progress.map((s) => [s.stage_id, String(s.percent)])));
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

  // The project's stages, to say how far each has got.
  useEffect(() => {
    if (!site) return;
    api
      .get<StageRow[]>(`/projects/${site.project_id}/stages`)
      .then((list) => {
        setStages(list);
        setStagePct((cur) => Object.fromEntries(list.map((s) => [s.id, cur[s.id] ?? String(s.percent_complete)])));
      })
      .catch(() => setStages([]));
  }, [site]);

  const options: Option[] = sites.map((s) => ({ value: s.id, label: s.name, hint: s.project?.name ?? null }));
  const setT = (k: keyof typeof text) => (v: string) => setText((t) => ({ ...t, [k]: v }));

  function body() {
    const rows = manpower.filter((m) => (m.category ?? '').trim());
    return {
      site_id: siteId,
      project_id: site?.project_id,
      report_date: date,
      ...text,
      manpower: rows.map((m) => ({ category: m.category.trim(), count: Number(m.count) || 0 })),
      total_manpower: rows.reduce((s, m) => s + (Number(m.count) || 0), 0),
      materials: materials
        .filter((m) => (m.material_name ?? '').trim())
        .map((m) => ({ material_name: m.material_name.trim(), quantity: Number(m.quantity) || 0, unit: (m.unit ?? '').trim() || 'nos', ...(m.remarks ? { remarks: m.remarks } : {}) })),
      equipment: equipment
        .filter((e) => (e.equipment_name ?? '').trim())
        .map((e) => ({
          equipment_name: e.equipment_name.trim(),
          quantity: Number(e.quantity) || 1,
          ...(e.operating_hours ? { operating_hours: Number(e.operating_hours) } : {}),
          ...(e.condition ? { condition: e.condition } : {}),
        })),
      ...(stages.length
        ? { stage_progress: stages.filter((s) => stagePct[s.id] !== '' && stagePct[s.id] !== undefined).map((s) => ({ stage_id: s.id, percent: Math.min(100, Math.max(0, Number(stagePct[s.id]) || 0)) })) }
        : {}),
    };
  }

  async function save(submit: boolean) {
    setError(null);
    setErrors({});
    if (!siteId) return setErrors({ site_id: 'Choose the site.' });
    setBusy(submit ? 'submit' : 'save');
    try {
      const saved = editing ? await api.put<DailyReport>(`/daily-site-reports/${id}`, body()) : await api.post<DailyReport>('/daily-site-reports', body());
      if (submit && saved.is_draft) await api.post(`/daily-site-reports/${saved.id}/submit`);
      router.replace(`/site-reports/${saved.id}`);
    } catch (e) {
      const err = e as ApiError;
      setError(err.message);
      setErrors(err.errors);
    } finally {
      setBusy(null);
    }
  }

  if (loading) return <Loading />;

  return (
    <Screen>
      <Stack.Screen options={{ title: editing ? 'Edit daily report' : 'New daily report' }} />
      {error ? <Banner message={error} /> : null}
      <PickerField label="Site" required value={siteId} options={options} onChange={(v) => setSiteId(v as number)} error={errors.site_id ?? errors.project_id} />
      <DateField label="Date" required value={date} onChange={setDate} error={errors.report_date} />
      <RowsEditor
        title="Manpower"
        columns={[
          { key: 'category', label: 'Trade / category', flex: 3 },
          { key: 'count', label: 'Count', numeric: true },
        ]}
        rows={manpower}
        onChange={setManpower}
      />
      <Field label="Work planned" required value={text.work_planned} onChangeText={setT('work_planned')} multiline error={errors.work_planned} />
      <Field label="Work completed" required value={text.work_completed} onChangeText={setT('work_completed')} multiline error={errors.work_completed} />
      {stages.length ? (
        <View style={{ marginBottom: 14 }}>
          <Text style={{ fontWeight: '700', color: colors.text, fontSize: 15 }}>Progress by stage</Text>
          <Muted>How far each stage has got, in %. Submitting updates the project progress.</Muted>
          {stages.map((s) => (
            <View key={s.id} style={{ flexDirection: 'row', alignItems: 'center', marginTop: 8 }}>
              <Text style={{ flex: 1, color: colors.text }}>{s.name}</Text>
              <TextInput
                value={stagePct[s.id] ?? ''}
                onChangeText={(t) => setStagePct((c) => ({ ...c, [s.id]: t }))}
                keyboardType="decimal-pad"
                style={{ width: 80, backgroundColor: '#fff', borderWidth: 1, borderColor: colors.border, borderRadius: 10, padding: 8, textAlign: 'right' }}
              />
              <Text style={{ marginLeft: 4, color: colors.muted }}>%</Text>
            </View>
          ))}
          {Object.keys(errors).some((k) => k.startsWith('stage_progress')) ? (
            <Text style={{ color: colors.danger }}>{Object.entries(errors).find(([k]) => k.startsWith('stage_progress'))?.[1]}</Text>
          ) : null}
        </View>
      ) : null}
      <RowsEditor
        title="Materials"
        columns={[
          { key: 'material_name', label: 'Material', flex: 3 },
          { key: 'quantity', label: 'Qty', numeric: true },
          { key: 'unit', label: 'Unit' },
        ]}
        rows={materials}
        onChange={setMaterials}
      />
      <RowsEditor
        title="Equipment"
        columns={[
          { key: 'equipment_name', label: 'Equipment', flex: 3 },
          { key: 'quantity', label: 'Qty', numeric: true },
          { key: 'operating_hours', label: 'Hours', numeric: true },
        ]}
        rows={equipment}
        onChange={setEquipment}
      />
      <Field label="Safety observations" value={text.safety_observations} onChangeText={setT('safety_observations')} multiline />
      <Field label="Delays" value={text.delays} onChangeText={setT('delays')} multiline />
      <Field label="Issues" value={text.issues} onChangeText={setT('issues')} multiline />
      <Field label="Remarks" value={text.remarks} onChangeText={setT('remarks')} multiline />
      <Button testID="submit-report" label="Save and submit" onPress={() => save(true)} busy={busy === 'submit'} />
      <Button testID="save-draft" label="Save as draft" kind="outline" onPress={() => save(false)} busy={busy === 'save'} />
    </Screen>
  );
}
