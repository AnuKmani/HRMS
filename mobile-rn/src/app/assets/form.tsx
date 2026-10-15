import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useEffect, useState } from 'react';

import { DateField } from '../../components/DateField';
import { PickerField, type Option } from '../../components/kit';
import { Banner, Button, Field, Loading, Screen } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { CONDITIONS, type Asset } from './index';

const KEYS = ['asset_code', 'asset_type_id', 'name', 'description', 'serial_number', 'manufacturer', 'model', 'purchase_date', 'current_condition', 'notes'] as const;

export default function AssetForm() {
  const { id } = useLocalSearchParams<{ id?: string }>();
  const router = useRouter();
  const editing = !!id;
  const [loading, setLoading] = useState(true);
  const [f, setF] = useState<Record<string, string>>({ current_condition: 'good' });
  const [types, setTypes] = useState<Option[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    (async () => {
      const t = await api.get<{ id: number; name: string }[] | { items: { id: number; name: string }[] }>('/asset-types').catch(() => []);
      setTypes((Array.isArray(t) ? t : t.items).map((x) => ({ value: x.id, label: x.name })));
      if (editing) {
        try {
          const a = await api.get<Asset>(`/assets/${id}`);
          const next: Record<string, string> = {};
          for (const k of KEYS) {
            const v = (a as Record<string, any>)[k];
            if (v !== null && v !== undefined) next[k] = String(v);
          }
          setF(next);
        } catch (e) {
          setError((e as ApiError).message);
        }
      }
      setLoading(false);
    })();
  }, [editing, id]);

  const set = (k: string) => (v: string) => setF((s) => ({ ...s, [k]: v }));

  async function save() {
    setBusy(true);
    setError(null);
    setErrors({});
    const body: Record<string, unknown> = {};
    for (const k of KEYS) body[k] = f[k] ? f[k] : null;
    body.asset_type_id = f.asset_type_id ? Number(f.asset_type_id) : null;
    if (editing) delete body.asset_code;
    try {
      const a = await (editing ? api.put<Asset>(`/assets/${id}`, body) : api.post<Asset>('/assets', body));
      router.replace(`/assets/${a.id}`);
    } catch (e) {
      const err = e as ApiError;
      setError(err.message);
      setErrors(err.errors);
    } finally {
      setBusy(false);
    }
  }

  if (loading) return <Loading />;

  return (
    <Screen>
      <Stack.Screen options={{ title: editing ? 'Edit asset' : 'New asset' }} />
      {error ? <Banner message={error} /> : null}
      {!editing ? <Field label="Asset code" required value={f.asset_code ?? ''} onChangeText={set('asset_code')} autoCapitalize="characters" error={errors.asset_code} /> : null}
      <PickerField label="Type" required value={f.asset_type_id ? Number(f.asset_type_id) : null} options={types} onChange={(v) => set('asset_type_id')(String(v))} error={errors.asset_type_id} />
      <Field label="Name" required value={f.name ?? ''} onChangeText={set('name')} error={errors.name} />
      <Field label="Serial number" value={f.serial_number ?? ''} onChangeText={set('serial_number')} error={errors.serial_number} />
      <Field label="Manufacturer" value={f.manufacturer ?? ''} onChangeText={set('manufacturer')} />
      <Field label="Model" value={f.model ?? ''} onChangeText={set('model')} />
      <DateField label="Purchase date" value={f.purchase_date ?? ''} onChange={set('purchase_date')} />
      <PickerField label="Condition" value={f.current_condition} options={CONDITIONS} onChange={(v) => set('current_condition')(String(v))} />
      <Field label="Description" value={f.description ?? ''} onChangeText={set('description')} multiline />
      <Field label="Notes" value={f.notes ?? ''} onChangeText={set('notes')} multiline />
      <Button label={editing ? 'Save changes' : 'Add asset'} onPress={save} busy={busy} />
    </Screen>
  );
}
