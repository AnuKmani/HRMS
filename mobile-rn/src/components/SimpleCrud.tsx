import { useState } from 'react';
import { Switch, Text, View } from 'react-native';

import { api, type Page } from '../lib/api';
import { useAuth } from '../lib/auth';
import { useAction, useLoad } from '../lib/hooks';
import { FormModal, PickerField, type Option } from './kit';
import { Banner, Button, Card, Chip, Empty, Field, Loading, Muted, Row, Screen, colors } from './ui';

export type CrudField =
  | { key: string; label: string; kind: 'text'; required?: boolean; multiline?: boolean; upper?: boolean }
  | { key: string; label: string; kind: 'number'; required?: boolean }
  | { key: string; label: string; kind: 'pick'; options: () => Promise<Option[]>; required?: boolean };

/**
 * A list with add / edit for simple records (departments, designations…):
 * name, code, status and a few fields.
 */
export function SimpleCrud({
  path,
  managePermission,
  fields,
  title,
  subtitle,
  hint,
}: {
  path: string;
  managePermission: string;
  fields: CrudField[];
  title: (row: Record<string, any>) => string;
  subtitle?: (row: Record<string, any>) => string;
  hint?: string;
}) {
  const { can } = useAuth();
  const manage = can(managePermission);
  const { data, error: loadError, loading, reload } = useLoad(() => api.get<Page<Record<string, any>>>(path, { per_page: 200 }).then((p) => p.items), [path]);
  const { busy, error, notice, run, setError } = useAction();
  const [f, setF] = useState<Record<string, any> | null>(null);
  const [opts, setOpts] = useState<Record<string, Option[]>>({});

  async function open(row?: Record<string, any>) {
    setError(null);
    const loaded: Record<string, Option[]> = {};
    for (const fld of fields) if (fld.kind === 'pick') loaded[fld.key] = await fld.options().catch(() => []);
    setOpts(loaded);
    const next: Record<string, any> = { status: 'active' };
    for (const fld of fields) next[fld.key] = row?.[fld.key] ?? '';
    if (row) {
      next.id = row.id;
      next.status = row.status ?? 'active';
    }
    setF(next);
  }

  return (
    <Screen onRefresh={reload}>
      {manage ? <Button label="Add" onPress={() => open()} /> : null}
      {hint ? <Muted>{hint}</Muted> : null}
      {loadError ? <Banner message={loadError} /> : null}
      {notice ? <Banner message={notice} tone="success" /> : null}
      {loading ? (
        <Loading />
      ) : !data?.length ? (
        <Empty message="Nothing here yet." />
      ) : (
        <Card>
          {data.map((r) => (
            <Row
              key={r.id}
              title={title(r)}
              subtitle={subtitle?.(r)}
              right={r.status && r.status !== 'active' ? <Chip label={String(r.status)} /> : undefined}
              onPress={manage ? () => open(r) : undefined}
            />
          ))}
        </Card>
      )}
      <FormModal
        visible={!!f}
        title={f?.id ? 'Edit' : 'Add'}
        busy={busy === 'save'}
        onClose={() => setF(null)}
        onSave={async () => {
          if (!f) return;
          const body: Record<string, unknown> = { status: f.status };
          for (const fld of fields) {
            const v = f[fld.key];
            body[fld.key] = v === '' || v === null ? null : fld.kind === 'number' ? Number(v) : fld.kind === 'text' && fld.upper ? String(v).toUpperCase() : v;
          }
          if (await run('save', () => (f.id ? api.put(`${path}/${f.id}`, body) : api.post(path, body)), 'Saved.')) {
            setF(null);
            await reload();
          }
        }}
      >
        {error ? <Banner message={error} /> : null}
        {f
          ? fields.map((fld) =>
              fld.kind === 'pick' ? (
                <PickerField key={fld.key} label={fld.label} required={fld.required} allowClear={!fld.required} value={f[fld.key] || null} options={opts[fld.key] ?? []} onChange={(v) => setF({ ...f, [fld.key]: v ?? '' })} />
              ) : (
                <Field
                  key={fld.key}
                  label={fld.label}
                  required={fld.required}
                  value={String(f[fld.key] ?? '')}
                  onChangeText={(v) => setF({ ...f, [fld.key]: v })}
                  keyboardType={fld.kind === 'number' ? 'decimal-pad' : 'default'}
                  multiline={fld.kind === 'text' && fld.multiline}
                  autoCapitalize={fld.kind === 'text' && fld.upper ? 'characters' : 'sentences'}
                />
              ),
            )
          : null}
        {f ? (
          <View style={{ flexDirection: 'row', alignItems: 'center', marginBottom: 14 }}>
            <Text style={{ flex: 1, fontWeight: '600', color: colors.text }}>Active</Text>
            <Switch value={f.status === 'active'} onValueChange={(v) => setF({ ...f, status: v ? 'active' : 'inactive' })} />
          </View>
        ) : null}
      </FormModal>
    </Screen>
  );
}
