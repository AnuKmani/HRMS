import { useRouter } from 'expo-router';
import { useState } from 'react';

import { FormModal, PickerField } from '../../components/kit';
import { Banner, Button, Card, Chip, Empty, Field, Loading, Muted, Row, Screen } from '../../components/ui';
import { api, type Page } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { MONTH_NAMES, money } from '../../lib/format';
import { useAction, useLoad } from '../../lib/hooks';
import { PAYROLL_STATUS, payrollTone, type Payroll } from '../../lib/payroll';

const monthOptions = MONTH_NAMES.map((m, i) => ({ value: i + 1, label: m }));

/** HR: run payroll for a month and move it through review → finalise → lock. */
export default function PayrollScreen() {
  const router = useRouter();
  const { can } = useAuth();
  const now = new Date();
  const [year, setYear] = useState(now.getFullYear());
  const [month, setMonth] = useState(now.getMonth() + 1);
  const [search, setSearch] = useState('');
  const { data, error: loadError, loading, reload } = useLoad(
    () => api.get<Page<Payroll>>('/payroll', { year, month, per_page: 200, search: search || undefined }).then((p) => p.items),
    [year, month, search],
  );
  const { busy, error, notice, run } = useAction();
  const [runOpen, setRunOpen] = useState(false);
  const total = (data ?? []).reduce((s, p) => s + Number(p.net_salary || 0), 0);

  return (
    <Screen onRefresh={reload}>
      <PickerField label="Month" value={month} options={monthOptions} onChange={(v) => setMonth(Number(v))} />
      <Field label="Year" value={String(year)} onChangeText={(v) => setYear(Number(v) || now.getFullYear())} keyboardType="number-pad" />
      <Field label="Search employee" value={search} onChangeText={setSearch} placeholder="Name or code" />
      {can('payroll.process') ? <Button label={`Run payroll for ${MONTH_NAMES[month - 1]} ${year}`} onPress={() => setRunOpen(true)} /> : null}
      {loadError ? <Banner message={loadError} /> : null}
      {notice ? <Banner message={notice} tone="success" /> : null}
      {loading ? (
        <Loading />
      ) : !data?.length ? (
        <Empty message="No payroll for this month yet." />
      ) : (
        <Card>
          <Muted>
            {data.length} employee(s) · net total {money(total)}
          </Muted>
          {data.map((p) => (
            <Row
              key={p.id}
              title={p.employee?.full_name ?? 'Employee'}
              subtitle={`Net ${money(p.net_salary, p.currency)}${Number(p.lop_days) ? ` · LOP ${p.lop_days} day(s)` : ''}`}
              right={<Chip label={PAYROLL_STATUS[p.status] ?? p.status} tone={payrollTone(p.status)} />}
              onPress={() => router.push(`/payroll/${p.id}`)}
            />
          ))}
        </Card>
      )}
      <FormModal
        visible={runOpen}
        title="Run payroll"
        saveLabel="Run"
        busy={busy === 'run'}
        onClose={() => setRunOpen(false)}
        onSave={async () => {
          if (await run('run', () => api.post('/payroll/process', { year, month }), `Payroll calculated for ${MONTH_NAMES[month - 1]} ${year}.`)) {
            setRunOpen(false);
            await reload();
          }
        }}
      >
        {error ? <Banner message={error} /> : null}
        <Muted>
          Calculates salary for every active employee for {MONTH_NAMES[month - 1]} {year}: allowances, approved overtime, unpaid leave (LOP), loan instalments and
          adjustments. Rows already locked are not touched. You can run it again after changes.
        </Muted>
      </FormModal>
    </Screen>
  );
}
