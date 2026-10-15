import { Stack, useLocalSearchParams } from 'expo-router';
import { useEffect, useState } from 'react';
import { Text, View } from 'react-native';

import { DateField } from '../../components/DateField';
import { PickerField, type Option } from '../../components/kit';
import { Banner, Button, Card, Empty, Loading, Muted, Screen, colors } from '../../components/ui';
import { api } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { openRemoteFile } from '../../lib/files';
import { money, prettyDate, ymd } from '../../lib/format';
import { useAction, useLoad } from '../../lib/hooks';
import { employeeOptions, lookupOptions, siteOptions } from '../../lib/people';
import type { ReportDef } from './index';

type Result = { report: ReportDef; filter_description?: string; items: Record<string, any>[]; meta: { total: number; current_page: number; last_page: number } };

const MIME: Record<string, string> = { csv: 'text/csv', xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', pdf: 'application/pdf' };

function cell(type: string, v: any): string {
  if (v === null || v === undefined || v === '') return '—';
  if (type === 'date') return prettyDate(String(v));
  if (type === 'datetime') return new Date(String(v)).toLocaleString();
  if (type === 'money') return money(v);
  if (type === 'minutes') return `${Math.floor(Number(v) / 60)}h ${Number(v) % 60}m`;
  return String(v);
}

export default function ReportView() {
  const { key } = useLocalSearchParams<{ key: string }>();
  const { can } = useAuth();
  const first = new Date();
  first.setDate(1);
  const [filters, setFilters] = useState<Record<string, string>>({ from: ymd(first), to: ymd(new Date()) });
  const [page, setPage] = useState(1);
  const { data, error: loadError, loading, reload } = useLoad(() => api.get<Result>(`/reports/${key}`, { ...filters, page, per_page: 50 }), [key, filters, page]);
  const { busy, error, run } = useAction();
  const [opts, setOpts] = useState<Record<string, Option[]>>({});
  const supportedKey = (data?.report.supported_filters ?? []).join(',');
  const supported = supportedKey ? supportedKey.split(',') : [];

  useEffect(() => {
    const supported = supportedKey.split(',');
    if (supported.includes('employee_id') && !opts.employee_id) employeeOptions().then((o) => setOpts((s) => ({ ...s, employee_id: o })), () => {});
    if (supported.includes('site_id') && !opts.site_id) siteOptions().then((o) => setOpts((s) => ({ ...s, site_id: o })), () => {});
    if (supported.includes('department_id') && !opts.department_id) lookupOptions('/departments').then((o) => setOpts((s) => ({ ...s, department_id: o })), () => {});
  }, [supportedKey, opts]);

  const set = (k: string) => (v: string | number | null) => {
    setPage(1);
    setFilters((s) => {
      const n = { ...s };
      if (v === null || v === '') delete n[k];
      else n[k] = String(v);
      return n;
    });
  };

  function exportAs(format: string) {
    const qs = Object.entries({ ...filters, format })
      .map(([k, v]) => `${k}=${encodeURIComponent(v)}`)
      .join('&');
    run(`export-${format}`, () => openRemoteFile(`/reports/${key}/export?${qs}`, `${key}-${filters.from ?? ''}.${format}`, MIME[format] ?? 'application/octet-stream'));
  }

  const columns = data?.report.columns ?? [];
  const [titleCol, ...rest] = columns;

  return (
    <Screen onRefresh={reload}>
      <Stack.Screen options={{ title: data?.report.title ?? 'Report' }} />
      {loadError ? <Banner message={loadError} /> : null}
      {error ? <Banner message={error} /> : null}
      {supported.includes('from') ? <DateField label="From" value={filters.from ?? ''} onChange={set('from')} /> : null}
      {supported.includes('to') ? <DateField label="To" value={filters.to ?? ''} onChange={set('to')} /> : null}
      {supported.includes('employee_id') ? <PickerField label="Employee" allowClear value={filters.employee_id ? Number(filters.employee_id) : null} options={opts.employee_id ?? []} onChange={set('employee_id')} /> : null}
      {supported.includes('site_id') ? <PickerField label="Site" allowClear value={filters.site_id ? Number(filters.site_id) : null} options={opts.site_id ?? []} onChange={set('site_id')} /> : null}
      {supported.includes('department_id') ? (
        <PickerField label="Department" allowClear value={filters.department_id ? Number(filters.department_id) : null} options={opts.department_id ?? []} onChange={set('department_id')} />
      ) : null}
      {can('reports.export') && data ? (
        <View style={{ flexDirection: 'row', gap: 8 }}>
          {data.report.formats.map((f) => (
            <View key={f} style={{ flex: 1 }}>
              <Button label={f.toUpperCase()} kind="outline" busy={busy === `export-${f}`} onPress={() => exportAs(f)} />
            </View>
          ))}
        </View>
      ) : null}
      {loading ? (
        <Loading />
      ) : !data?.items.length ? (
        <Empty message="No rows for these filters." />
      ) : (
        <>
          <Muted>
            {data.meta.total} row(s){data.filter_description ? ` · ${data.filter_description}` : ''}
          </Muted>
          {data.items.map((row, i) => (
            <Card key={i}>
              {titleCol ? <Text style={{ fontWeight: '700', color: colors.text, marginBottom: 4 }}>{cell(titleCol.type, row[titleCol.key])}</Text> : null}
              {rest.map((c) => (
                <View key={c.key} style={{ flexDirection: 'row', paddingVertical: 2 }}>
                  <Text style={{ width: 130, color: colors.muted }}>{c.label}</Text>
                  <Text style={{ flex: 1, color: colors.text }}>{cell(c.type, row[c.key])}</Text>
                </View>
              ))}
            </Card>
          ))}
          {data.meta.last_page > 1 ? (
            <View style={{ flexDirection: 'row', gap: 8, alignItems: 'center' }}>
              <View style={{ flex: 1 }}>
                <Button label="‹ Previous" kind="outline" onPress={() => setPage(Math.max(1, page - 1))} />
              </View>
              <Text style={{ color: colors.muted }}>
                {data.meta.current_page} / {data.meta.last_page}
              </Text>
              <View style={{ flex: 1 }}>
                <Button label="Next ›" kind="outline" onPress={() => setPage(Math.min(data.meta.last_page, page + 1))} />
              </View>
            </View>
          ) : null}
        </>
      )}
    </Screen>
  );
}
