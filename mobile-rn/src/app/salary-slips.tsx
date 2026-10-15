import { useState } from 'react';

import { FormModal, Tabs } from '../components/kit';
import { Banner, Button, Card, Chip, Empty, Loading, Muted, Row, Screen } from '../components/ui';
import { api, type Page } from '../lib/api';
import { useAuth } from '../lib/auth';
import { openRemoteFile } from '../lib/files';
import { money } from '../lib/format';
import { useAction, useLoad } from '../lib/hooks';
import type { Payroll } from '../lib/payroll';

const SLIP: Record<string, [string, 'warning' | 'success' | 'danger']> = {
  requested: ['Requested', 'warning'],
  approved: ['Released', 'success'],
  rejected: ['Declined', 'danger'],
};

/** Payslips: employees ask HR to release one, then download it. */
export default function SalarySlips() {
  const { can, user } = useAuth();
  const hr = can('salary_slips.manage');
  const [tab, setTab] = useState<'mine' | 'requests'>('mine');
  const { data, error: loadError, loading, reload } = useLoad(
    () =>
      api
        .get<Page<Payroll>>('/salary-slips', { per_page: 100, ...(tab === 'mine' ? { employee_id: user?.employee?.id } : {}) })
        .then((p) => (tab === 'requests' ? p.items.filter((s) => s.slip_request_status === 'requested') : p.items.filter((s) => s.employee_id === user?.employee?.id))),
    [tab],
  );
  const { busy, error, notice, run } = useAction();
  const [ask, setAsk] = useState<Payroll | null>(null);
  const [decide, setDecide] = useState<Payroll | null>(null);

  const open = (p: Payroll) => run('pdf', () => openRemoteFile(`/salary-slips/${p.id}/pdf`, `payslip-${p.payroll_year}-${p.payroll_month}.pdf`));

  function tap(p: Payroll) {
    if (tab === 'requests') return setDecide(p);
    if (p.can_download) return open(p);
    if (p.slip_request_status === 'requested') return;
    setAsk(p);
  }

  return (
    <Screen onRefresh={reload}>
      {hr ? <Tabs value={tab} onChange={setTab} options={[['mine', 'My payslips'], ['requests', 'Requests']]} /> : null}
      {(loadError || (error && !ask && !decide)) ? <Banner message={loadError || error || ''} /> : null}
      {notice ? <Banner message={notice} tone="success" /> : null}
      {loading ? (
        <Loading />
      ) : !data?.length ? (
        <Empty message={tab === 'requests' ? 'No payslip requests waiting.' : 'No payslips yet.'} />
      ) : (
        <Card>
          {tab === 'mine' ? <Muted>Tap a payslip to download it, or to ask HR to release it.</Muted> : null}
          {data.map((p) => {
            const s = p.slip_request_status ? SLIP[p.slip_request_status] : undefined;
            return (
              <Row
                key={p.id}
                title={`${tab === 'requests' ? `${p.employee?.full_name ?? ''} · ` : ''}${p.period_label}`}
                subtitle={`Net ${money(p.net_salary, p.currency)}${p.can_download && tab === 'mine' ? ' · Tap to download' : ''}`}
                right={p.can_download && tab === 'mine' ? <Chip label="Download" tone="success" /> : s ? <Chip label={s[0]} tone={s[1]} /> : <Chip label="Request" />}
                onPress={() => tap(p)}
              />
            );
          })}
        </Card>
      )}
      <FormModal
        visible={!!ask}
        title="Request payslip"
        saveLabel="Send request"
        busy={busy === 'ask'}
        onClose={() => setAsk(null)}
        onSave={async () => {
          if (ask && (await run('ask', () => api.post(`/salary-slips/${ask.id}/request`), 'Request sent. HR has been notified.'))) {
            setAsk(null);
            await reload();
          }
        }}
      >
        {error ? <Banner message={error} /> : null}
        <Muted>Ask HR to release your {ask?.period_label} payslip? You will get a notification when you can download it.</Muted>
      </FormModal>
      <FormModal visible={!!decide} title={`${decide?.employee?.full_name ?? ''} · ${decide?.period_label ?? ''}`} saveLabel="Close" onSave={() => setDecide(null)} onClose={() => setDecide(null)}>
        {error ? <Banner message={error} /> : null}
        <Muted>Asked to download this payslip.</Muted>
        <Button
          label="Release — let them download it"
          busy={busy === 'approve'}
          onPress={async () => {
            if (decide && (await run('approve', () => api.post(`/salary-slips/${decide.id}/approve`), 'Released.'))) {
              setDecide(null);
              await reload();
            }
          }}
        />
        <Button
          label="Decline"
          kind="danger"
          busy={busy === 'reject'}
          onPress={async () => {
            if (decide && (await run('reject', () => api.post(`/salary-slips/${decide.id}/reject`), 'Declined.'))) {
              setDecide(null);
              await reload();
            }
          }}
        />
        <Button label="Open the payslip" kind="outline" onPress={() => decide && open(decide)} />
      </FormModal>
    </Screen>
  );
}
