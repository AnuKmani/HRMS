import { useEffect, useState } from 'react';

import { STATUS_LABEL } from '../components/Approvals';
import { FormModal, KeyValues, PickerField, Tabs, type Option } from '../components/kit';
import { Banner, Button, Card, Chip, Empty, Field, Loading, Row, Screen } from '../components/ui';
import { api, type Page } from '../lib/api';
import { useAuth } from '../lib/auth';
import { openRemoteFile } from '../lib/files';
import { prettyDate } from '../lib/format';
import { useAction, useLoad } from '../lib/hooks';
import { employeeOptions } from '../lib/people';

type Cert = {
  id: number;
  employee_id: number;
  employee?: { full_name?: string } | null;
  reference?: string | null;
  request_date: string;
  purpose: string;
  status: string;
  can_issue?: boolean;
  remarks?: string | null;
};

const LABEL: Record<string, string> = { ...STATUS_LABEL, generated: 'Ready' };
const tone = (s: string) => (s === 'generated' || s === 'approved' ? 'success' : s === 'rejected' ? 'danger' : s === 'pending' ? 'warning' : 'neutral');

/** Salary certificates: ask HR for one, then download the PDF. */
export default function SalaryCertificates() {
  const { can, user } = useAuth();
  const hr = can('salary_certificates.manage');
  const [tab, setTab] = useState<'mine' | 'pending' | 'all'>('mine');
  const { data, error: loadError, loading, reload } = useLoad(
    () =>
      api
        .get<Page<Cert>>('/salary-certificate-requests', { per_page: 100, ...(tab === 'mine' ? { employee_id: user?.employee?.id } : tab === 'pending' ? { status: 'pending' } : {}) })
        .then((p) => (tab === 'mine' ? p.items.filter((c) => c.employee_id === user?.employee?.id) : p.items)),
    [tab],
  );
  const { busy, error, notice, run, setError } = useAction();
  const [form, setForm] = useState<{ purpose: string; employee_id: number | null } | null>(null);
  const [people, setPeople] = useState<Option[]>([]);
  const [open, setOpen] = useState<Cert | null>(null);
  const [remarks, setRemarks] = useState('');

  useEffect(() => {
    if (hr) employeeOptions().then(setPeople, () => {});
  }, [hr]);

  const done = async (ok: boolean) => {
    if (ok) {
      setOpen(null);
      setForm(null);
      await reload();
    }
  };

  return (
    <Screen onRefresh={reload}>
      {user?.employee || hr ? (
        <Button
          label="Request a salary certificate"
          onPress={() => {
            setError(null);
            setForm({ purpose: '', employee_id: user?.employee?.id ?? null });
          }}
        />
      ) : null}
      {hr ? <Tabs value={tab} onChange={setTab} options={[['mine', 'Mine'], ['pending', 'To approve'], ['all', 'All']]} /> : null}
      {loadError ? <Banner message={loadError} /> : null}
      {notice ? <Banner message={notice} tone="success" /> : null}
      {loading ? (
        <Loading />
      ) : !data?.length ? (
        <Empty message="No requests." />
      ) : (
        <Card>
          {data.map((c) => (
            <Row
              key={c.id}
              title={`${tab !== 'mine' ? `${c.employee?.full_name ?? ''} · ` : ''}${c.purpose}`}
              subtitle={`${prettyDate(c.request_date)}${c.reference ? ` · ${c.reference}` : ''}`}
              right={<Chip label={LABEL[c.status] ?? c.status} tone={tone(c.status)} />}
              onPress={() => {
                setError(null);
                setRemarks('');
                setOpen(c);
              }}
            />
          ))}
        </Card>
      )}

      <FormModal
        visible={!!form}
        title="Request a salary certificate"
        saveLabel="Send request"
        busy={busy === 'new'}
        onClose={() => setForm(null)}
        onSave={() =>
          form &&
          run('new', () => api.post('/salary-certificate-requests', { purpose: form.purpose, employee_id: hr ? form.employee_id : undefined }), 'Request sent to HR.').then(done)
        }
      >
        {error ? <Banner message={error} /> : null}
        {form && hr ? <PickerField label="For employee" value={form.employee_id} options={people} onChange={(v) => setForm({ ...form, employee_id: v as number })} /> : null}
        {form ? <Field label="Purpose" required value={form.purpose} onChangeText={(v) => setForm({ ...form, purpose: v })} placeholder="e.g. Bank loan, visa, rental" /> : null}
      </FormModal>

      <FormModal visible={!!open} title="Salary certificate" saveLabel="Close" onSave={() => setOpen(null)} onClose={() => setOpen(null)}>
        {error ? <Banner message={error} /> : null}
        {open ? (
          <>
            <KeyValues
              rows={[
                ['Employee', open.employee?.full_name],
                ['Purpose', open.purpose],
                ['Requested', prettyDate(open.request_date)],
                ['Status', LABEL[open.status] ?? open.status],
                ['Remarks', open.remarks],
              ]}
            />
            {open.can_issue || open.status === 'generated' || open.status === 'approved' ? (
              <Button label="Download PDF" busy={busy === 'pdf'} onPress={() => run('pdf', () => openRemoteFile(`/salary-certificate-requests/${open.id}/pdf`, `salary-certificate-${open.id}.pdf`))} />
            ) : null}
            {hr && open.status === 'pending' ? (
              <>
                <Field label="Remarks" value={remarks} onChangeText={setRemarks} multiline />
                <Button label="Approve" busy={busy === 'approve'} onPress={() => run('approve', () => api.post(`/salary-certificate-requests/${open.id}/approve`, { remarks }), 'Approved. It can be downloaded now.').then(done)} />
                <Button label="Reject" kind="danger" busy={busy === 'reject'} onPress={() => run('reject', () => api.post(`/salary-certificate-requests/${open.id}/reject`, { remarks }), 'Rejected.').then(done)} />
              </>
            ) : null}
            {open.employee_id === user?.employee?.id && open.status === 'pending' ? (
              <Button label="Cancel request" kind="outline" busy={busy === 'cancel'} onPress={() => run('cancel', () => api.post(`/salary-certificate-requests/${open.id}/cancel`), 'Cancelled.').then(done)} />
            ) : null}
          </>
        ) : null}
      </FormModal>
    </Screen>
  );
}
