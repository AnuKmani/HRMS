import * as DocumentPicker from 'expo-document-picker';
import * as ImagePicker from 'expo-image-picker';
import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useEffect, useState } from 'react';
import { Pressable, Text } from 'react-native';

import { DateField } from '../../components/DateField';
import { PickerField, type Option } from '../../components/kit';
import { Banner, Button, Field, Muted, Row, Screen, colors } from '../../components/ui';
import { api, ApiError, fileField } from '../../lib/api';
import { ymd } from '../../lib/format';
import { projectOptions, siteOptions } from '../../lib/people';

type Category = { id: number; name: string; requires_receipt: boolean; maximum_amount?: string | number | null; is_active: boolean };
type Picked = { uri: string; name: string; type: string };

type Draft = { expense_date: string; amount: string | number; currency: string; description: string; expense_category_id: number; project_id: number | null; site_id: number | null };

export default function NewExpense() {
  const router = useRouter();
  const { id: editId } = useLocalSearchParams<{ id?: string }>();
  const editing = !!editId;
  const [f, setF] = useState({ expense_date: ymd(new Date()), amount: '', currency: 'AED', description: '' });
  const [categoryId, setCategoryId] = useState<number | null>(null);
  const [projectId, setProjectId] = useState<number | null>(null);
  const [siteId, setSiteId] = useState<number | null>(null);
  const [files, setFiles] = useState<Picked[]>([]);
  const [cats, setCats] = useState<Category[]>([]);
  const [opts, setOpts] = useState<{ projects: Option[]; sites: Option[] }>({ projects: [], sites: [] });
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    api.get<Category[] | { items: Category[] }>('/expense-categories').then((r) => setCats((Array.isArray(r) ? r : r.items).filter((c) => c.is_active)), () => {});
    Promise.all([projectOptions().catch(() => []), siteOptions().catch(() => [])]).then(([projects, sites]) => setOpts({ projects, sites }));
    if (editId) {
      api.get<Draft>(`/expenses/${editId}`).then((e) => {
        setF({ expense_date: e.expense_date, amount: String(e.amount), currency: e.currency, description: e.description ?? '' });
        setCategoryId(e.expense_category_id);
        setProjectId(e.project_id);
        setSiteId(e.site_id);
      }, (e) => setError((e as ApiError).message));
    } else {
      // The company's currency, rather than a guess built into the app.
      api.get<{ default_currency: string }>('/client-settings').then((s) => setF((cur) => (cur.amount ? cur : { ...cur, currency: s.default_currency })), () => {});
    }
  }, [editId]);

  const cat = cats.find((c) => c.id === categoryId);

  async function pick() {
    const r = await DocumentPicker.getDocumentAsync({ type: ['image/*', 'application/pdf'], multiple: true, copyToCacheDirectory: true });
    if (r.canceled) return;
    setFiles((cur) => [...cur, ...r.assets.map((a) => ({ uri: a.uri, name: a.name, type: a.mimeType ?? 'application/octet-stream' }))].slice(0, 6));
  }

  async function snap() {
    const permission = await ImagePicker.requestCameraPermissionsAsync();
    if (!permission.granted) return;
    const r = await ImagePicker.launchCameraAsync({ quality: 0.6 });
    if (r.canceled || !r.assets?.length) return;
    setFiles((cur) => [...cur, { uri: r.assets[0].uri, name: `receipt-${cur.length + 1}.jpg`, type: 'image/jpeg' }].slice(0, 6));
  }

  async function save(submit: boolean) {
    setBusy(submit ? 'submit' : 'draft');
    setError(null);
    setErrors({});
    let id: number | null = null;
    try {
      const payload = { ...f, amount: Number(f.amount), expense_category_id: categoryId, project_id: projectId, site_id: siteId };
      const e = editing ? await api.put<{ id: number }>(`/expenses/${editId}`, payload) : await api.post<{ id: number }>('/expenses', payload);
      id = e.id;
      if (files.length) {
        const body = new FormData();
        files.forEach((p) => body.append('receipts[]', fileField(p.uri, p.name, p.type)));
        await api.post(`/expenses/${id}/receipts`, body);
      }
      if (submit) await api.post(`/expenses/${id}/submit`);
      if (editing) router.back();
      else router.replace(`/expenses/${id}`);
    } catch (e) {
      const err = e as ApiError;
      if (id) {
        // Saved as a draft already; open it so the problem can be fixed there.
        router.replace(`/expenses/${id}?problem=${encodeURIComponent(err.message)}`);
        return;
      }
      setError(err.message);
      setErrors(err.errors);
    } finally {
      setBusy(null);
    }
  }

  return (
    <Screen>
      {editing ? <Stack.Screen options={{ title: 'Edit expense' }} /> : null}
      {error ? <Banner message={error} /> : null}
      <DateField label="Date spent" required value={f.expense_date} onChange={(v) => setF({ ...f, expense_date: v })} />
      <PickerField
        label="Category"
        required
        value={categoryId}
        options={cats.map((c) => ({ value: c.id, label: c.name, hint: c.requires_receipt ? 'Receipt needed' : null }))}
        onChange={(v) => setCategoryId(v as number)}
        error={errors.expense_category_id}
      />
      <Field label="Amount" required value={f.amount} onChangeText={(v) => setF({ ...f, amount: v })} keyboardType="decimal-pad" error={errors.amount} />
      {cat?.maximum_amount ? <Muted>Most you can claim in this category: {Number(cat.maximum_amount).toLocaleString('en-US')}</Muted> : null}
      <Field label="Currency" value={f.currency} onChangeText={(v) => setF({ ...f, currency: v.toUpperCase() })} autoCapitalize="characters" maxLength={3} error={errors.currency} />
      <Field label="What was it for?" required value={f.description} onChangeText={(v) => setF({ ...f, description: v })} multiline error={errors.description} />
      <PickerField label="Project" allowClear value={projectId} options={opts.projects} onChange={(v) => setProjectId(v as number | null)} />
      <PickerField label="Site" allowClear value={siteId} options={opts.sites} onChange={(v) => setSiteId(v as number | null)} />
      <Button label="Take photo of receipt" kind="outline" onPress={snap} />
      <Button label={`Attach receipts (${files.length}/6)`} kind="outline" onPress={pick} />
      {editing ? <Muted>Receipts already attached stay as they are; any added here are added to them.</Muted> : null}
      {files.map((p, i) => (
        <Row
          key={`${p.uri}-${i}`}
          title={p.name}
          right={
            <Pressable onPress={() => setFiles(files.filter((_, j) => j !== i))}>
              <Text style={{ color: colors.danger }}>Remove</Text>
            </Pressable>
          }
        />
      ))}
      {cat?.requires_receipt && !files.length ? <Muted>This category needs a receipt before you can submit.</Muted> : null}
      <Screen.Spacer />
      <Button label="Submit for approval" onPress={() => save(true)} busy={busy === 'submit'} />
      <Button label="Save as draft" kind="outline" onPress={() => save(false)} busy={busy === 'draft'} />
    </Screen>
  );
}
