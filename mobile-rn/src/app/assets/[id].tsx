import { useLocalSearchParams, useRouter } from 'expo-router';
import { useState } from 'react';
import { Text, View } from 'react-native';

import { DateField } from '../../components/DateField';
import { FormModal, KeyValues, PickerField, SectionHeader, type Option } from '../../components/kit';
import { Banner, Button, Card, Chip, Field, Loading, Muted, Row, Screen, colors } from '../../components/ui';
import { api } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { prettyDate, ymd } from '../../lib/format';
import { useAction, useLoad } from '../../lib/hooks';
import { employeeOptions } from '../../lib/people';
import { ASSET_STATUS, CONDITIONS, assetTone, type Asset } from './index';

export default function AssetDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const router = useRouter();
  const { can } = useAuth();
  const { data: a, error: loadError, loading, reload } = useLoad(() => api.get<Asset>(`/assets/${id}`), [id]);
  const { busy, error, notice, run, setError } = useAction();
  const [modal, setModal] = useState<'assign' | 'return' | 'status' | null>(null);
  const [f, setF] = useState<Record<string, string>>({});
  const [people, setPeople] = useState<Option[]>([]);

  if (loading) return <Loading />;
  if (!a) return <Screen>{loadError ? <Banner message={loadError} /> : null}</Screen>;

  async function open(kind: typeof modal, initial: Record<string, string>) {
    setError(null);
    setF(initial);
    if (kind === 'assign') setPeople(await employeeOptions().catch(() => []));
    setModal(kind);
  }
  const act = async (key: string, fn: () => Promise<unknown>, done: string) => {
    if (await run(key, fn, done)) {
      setModal(null);
      await reload();
    }
  };
  const cond = (v?: string | null) => CONDITIONS.find((c) => c.value === v)?.label ?? v ?? '—';

  return (
    <Screen onRefresh={reload}>
      {error && !modal ? <Banner message={error} /> : null}
      {notice ? <Banner message={notice} tone="success" /> : null}
      {a.current_assignment?.is_overdue ? <Banner message="Overdue — it should have been returned already." tone="warning" /> : null}
      <Card>
        <View style={{ flexDirection: 'row', alignItems: 'center', marginBottom: 8 }}>
          <Text style={{ flex: 1, fontSize: 18, fontWeight: '700', color: colors.text }}>{a.name}</Text>
          <Chip label={ASSET_STATUS[a.status] ?? a.status} tone={assetTone(a.status)} />
        </View>
        <KeyValues
          rows={[
            ['Code', a.asset_code],
            ['Type', a.asset_type?.name],
            ['With', a.current_holder?.full_name],
            ...(a.current_assignment ? ([['Since', prettyDate(a.current_assignment.assigned_date)], ['Due back', prettyDate(a.current_assignment.expected_return_date)]] as [string, string][]) : []),
            ['Condition', cond(a.current_condition)],
            ['Serial', a.serial_number],
            ['Make / model', [a.manufacturer, a.model].filter(Boolean).join(' ')],
            ['Bought', prettyDate(a.purchase_date)],
            ['Notes', a.notes],
          ]}
        />
      </Card>
      {can('assets.assign') && a.is_assignable ? <Button label="Hand over to someone" onPress={() => open('assign', { employee_id: '', assigned_date: ymd(new Date()), expected_return_date: '', assigned_condition: a.current_condition, remarks: '' })} /> : null}
      {can('assets.return') && a.status === 'assigned' ? <Button label="Take it back" onPress={() => open('return', { returned_date: ymd(new Date()), returned_condition: a.current_condition, remarks: '' })} /> : null}
      {can('assets.update') ? <Button label="Edit details" kind="outline" onPress={() => router.push(`/assets/form?id=${a.id}`)} /> : null}
      {can('assets.manage') && a.status !== 'assigned' ? <Button label="Change status" kind="outline" onPress={() => open('status', { status: a.status, notes: '' })} /> : null}

      <Card>
        <SectionHeader title="History" />
        {!a.assignments?.length ? <Muted>Never handed out.</Muted> : null}
        {a.assignments?.map((h) => (
          <Row
            key={h.id}
            title={h.employee?.full_name ?? 'Employee'}
            subtitle={`${prettyDate(h.assigned_date)} → ${h.returned_date ? prettyDate(h.returned_date) : 'now'} · ${cond(h.assigned_condition)}${h.returned_condition ? ` → ${cond(h.returned_condition)}` : ''}${h.remarks ? ` · ${h.remarks}` : ''}`}
          />
        ))}
      </Card>

      <FormModal
        visible={modal === 'assign'}
        title="Hand over"
        busy={busy === 'assign'}
        onClose={() => setModal(null)}
        onSave={() =>
          act(
            'assign',
            () =>
              api.post(`/assets/${a.id}/assign`, {
                employee_id: Number(f.employee_id),
                assigned_date: f.assigned_date,
                expected_return_date: f.expected_return_date || null,
                assigned_condition: f.assigned_condition,
                remarks: f.remarks || null,
              }),
            'Handed over.',
          )
        }
      >
        {error ? <Banner message={error} /> : null}
        <PickerField label="Employee" required value={f.employee_id ? Number(f.employee_id) : null} options={people} onChange={(v) => setF({ ...f, employee_id: String(v) })} />
        <DateField label="Handed over on" value={f.assigned_date ?? ''} onChange={(v) => setF({ ...f, assigned_date: v })} />
        <DateField label="Return by (optional)" value={f.expected_return_date ?? ''} onChange={(v) => setF({ ...f, expected_return_date: v })} />
        <PickerField label="Condition" value={f.assigned_condition} options={CONDITIONS} onChange={(v) => setF({ ...f, assigned_condition: String(v) })} />
        <Field label="Remarks" value={f.remarks ?? ''} onChangeText={(v) => setF({ ...f, remarks: v })} multiline />
      </FormModal>

      <FormModal
        visible={modal === 'return'}
        title="Take it back"
        busy={busy === 'return'}
        onClose={() => setModal(null)}
        onSave={() => act('return', () => api.post(`/assets/${a.id}/return`, { returned_date: f.returned_date, returned_condition: f.returned_condition, remarks: f.remarks || null }), 'Returned.')}
      >
        {error ? <Banner message={error} /> : null}
        <DateField label="Returned on" value={f.returned_date ?? ''} onChange={(v) => setF({ ...f, returned_date: v })} />
        <PickerField label="Condition now" required value={f.returned_condition} options={CONDITIONS} onChange={(v) => setF({ ...f, returned_condition: String(v) })} />
        <Field label="Remarks" value={f.remarks ?? ''} onChangeText={(v) => setF({ ...f, remarks: v })} multiline />
      </FormModal>

      <FormModal
        visible={modal === 'status'}
        title="Change status"
        busy={busy === 'status'}
        onClose={() => setModal(null)}
        onSave={() => act('status', () => api.patch(`/assets/${a.id}/status`, { status: f.status, notes: f.notes || null }), 'Status changed.')}
      >
        {error ? <Banner message={error} /> : null}
        <PickerField
          label="Status"
          value={f.status}
          options={Object.entries(ASSET_STATUS)
            .filter(([k]) => k !== 'assigned')
            .map(([value, label]) => ({ value, label }))}
          onChange={(v) => setF({ ...f, status: String(v) })}
        />
        <Field label="Notes" value={f.notes ?? ''} onChangeText={(v) => setF({ ...f, notes: v })} multiline />
      </FormModal>
    </Screen>
  );
}
