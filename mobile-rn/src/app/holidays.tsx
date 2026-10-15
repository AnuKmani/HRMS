import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import { DateField } from '../components/DateField';
import { FormModal, PickerField, type Option } from '../components/kit';
import { Banner, Button, Card, Chip, Empty, Field, Loading, Row, Screen, colors } from '../components/ui';
import { api, type Page } from '../lib/api';
import { useAuth } from '../lib/auth';
import { prettyDate } from '../lib/format';
import { useAction, useLoad } from '../lib/hooks';
import { siteOptions } from '../lib/people';

type Holiday = { id: number; name: string; date: string; type: string; site?: { name: string } | null; site_id?: number | null; description?: string | null; status?: string };

const TYPES: [string, string][] = [
  ['public', 'Public holiday'],
  ['company', 'Company holiday'],
  ['site', 'Site holiday'],
];

/** The holiday calendar: everyone reads it, HR keeps it. */
export default function HolidaysScreen() {
  const { can } = useAuth();
  const manage = can('holidays.manage');
  const [year, setYear] = useState(new Date().getFullYear());
  const { data, error: loadError, loading, reload } = useLoad(
    () => api.get<Page<Holiday>>('/holidays', { from: `${year}-01-01`, to: `${year}-12-31`, per_page: 200 }).then((p) => p.items),
    [year],
  );
  const { busy, error, notice, run, setError } = useAction();
  const [f, setF] = useState<Record<string, string> | null>(null);
  const [sites, setSites] = useState<Option[]>([]);

  async function edit(h?: Holiday) {
    setError(null);
    if (!sites.length) setSites(await siteOptions().catch(() => []));
    setF(h ? { id: String(h.id), name: h.name, date: h.date, type: h.type, site_id: h.site_id ? String(h.site_id) : '', description: h.description ?? '' } : { name: '', date: '', type: 'public', site_id: '', description: '' });
  }

  return (
    <Screen onRefresh={reload}>
      <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 24, marginBottom: 12 }}>
        <Pressable onPress={() => setYear((y) => y - 1)}><Text style={{ fontSize: 22, color: colors.primary }}>‹</Text></Pressable>
        <Text style={{ fontSize: 18, fontWeight: '700', color: colors.text }}>{year}</Text>
        <Pressable onPress={() => setYear((y) => y + 1)}><Text style={{ fontSize: 22, color: colors.primary }}>›</Text></Pressable>
      </View>
      {manage ? <Button label="Add holiday" onPress={() => edit()} /> : null}
      {loadError ? <Banner message={loadError} /> : null}
      {notice ? <Banner message={notice} tone="success" /> : null}
      {loading ? (
        <Loading />
      ) : !data?.length ? (
        <Empty message="No holidays this year." />
      ) : (
        <Card>
          {data.map((h) => (
            <Row
              key={h.id}
              title={h.name}
              subtitle={[prettyDate(h.date), h.site?.name, h.description].filter(Boolean).join(' · ')}
              right={<Chip label={TYPES.find(([t]) => t === h.type)?.[1] ?? h.type} tone="info" />}
              onPress={manage ? () => edit(h) : undefined}
            />
          ))}
        </Card>
      )}
      <FormModal
        visible={!!f}
        title={f?.id ? 'Edit holiday' : 'New holiday'}
        busy={busy === 'save'}
        onClose={() => setF(null)}
        onSave={async () => {
          if (!f) return;
          const body = { name: f.name, date: f.date, type: f.type, site_id: f.type === 'site' && f.site_id ? Number(f.site_id) : null, description: f.description || null };
          if (await run('save', () => (f.id ? api.put(`/holidays/${f.id}`, body) : api.post('/holidays', body)), 'Holiday saved.')) {
            setF(null);
            await reload();
          }
        }}
      >
        {error ? <Banner message={error} /> : null}
        {f ? (
          <>
            <Field label="Name" required value={f.name} onChangeText={(v) => setF({ ...f, name: v })} />
            <DateField label="Date" required value={f.date} onChange={(v) => setF({ ...f, date: v })} />
            <PickerField label="Type" value={f.type} options={TYPES.map(([value, label]) => ({ value, label }))} onChange={(v) => setF({ ...f, type: String(v) })} />
            {f.type === 'site' ? <PickerField label="Site" value={f.site_id ? Number(f.site_id) : null} options={sites} onChange={(v) => setF({ ...f, site_id: String(v ?? '') })} /> : null}
            <Field label="Description" value={f.description} onChangeText={(v) => setF({ ...f, description: v })} />
          </>
        ) : null}
      </FormModal>
    </Screen>
  );
}
