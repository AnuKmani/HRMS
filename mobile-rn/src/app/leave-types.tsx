import { useState } from 'react';
import { Switch, Text, View } from 'react-native';

import { FormModal } from '../components/kit';
import { Banner, Button, Card, Chip, Field, Loading, Muted, Row, Screen, colors } from '../components/ui';
import { api, type Page } from '../lib/api';
import { useAction, useLoad } from '../lib/hooks';
import type { LeaveType } from '../lib/leave';

type Form = { id?: number; name: string; code: string; days: string; unlimited: boolean; max: string; paid: boolean; certificate: boolean; deadline: string; active: boolean };

const blank: Form = { name: '', code: '', days: '0', unlimited: false, max: '0', paid: true, certificate: false, deadline: '1', active: true };

/** HR sets up leave types: days per year, unlimited, paid, certificate. */
export default function LeaveTypesScreen() {
  const { data, error: loadError, loading, reload } = useLoad(() => api.get<Page<LeaveType>>('/leave-types', { per_page: 100 }).then((p) => p.items));
  const { busy, error, notice, run, setError } = useAction();
  const [f, setF] = useState<Form | null>(null);
  const set = (k: keyof Form) => (v: string | boolean) => setF((s) => (s ? { ...s, [k]: v } : s));

  return (
    <Screen onRefresh={reload}>
      <Button testID="add-leave-type" label="Add leave type" onPress={() => { setError(null); setF(blank); }} />
      <Muted>Days per year apply to everyone. To give one person different days, use Leave overview.</Muted>
      <Screen.Spacer />
      {loadError ? <Banner message={loadError} /> : null}
      {notice ? <Banner message={notice} tone="success" /> : null}
      {loading ? (
        <Loading />
      ) : (
        <Card>
          {data?.map((t) => (
            <Row
              key={t.id}
              title={`${t.name} (${t.code})`}
              subtitle={[t.is_paid ? 'Paid' : 'Unpaid', t.requires_document ? `certificate within ${t.document_deadline_days} day(s)` : null, t.maximum_days_per_request ? `max ${t.maximum_days_per_request} per request` : null].filter(Boolean).join(' · ')}
              right={<Chip label={t.status !== 'active' ? 'Inactive' : t.is_unlimited ? 'Unlimited' : `${t.entitlement_days} days`} tone={t.status !== 'active' ? 'neutral' : 'info'} />}
              onPress={() => {
                setError(null);
                setF({
                  id: t.id,
                  name: t.name,
                  code: t.code,
                  days: String(t.entitlement_days),
                  unlimited: !!t.is_unlimited,
                  max: String(t.maximum_days_per_request ?? 0),
                  paid: t.is_paid,
                  certificate: t.requires_document,
                  deadline: String(t.document_deadline_days || 1),
                  active: t.status === 'active',
                });
              }}
            />
          ))}
        </Card>
      )}
      <FormModal
        visible={!!f}
        title={f?.id ? 'Edit leave type' : 'New leave type'}
        busy={busy === 'save'}
        onClose={() => setF(null)}
        onSave={async () => {
          if (!f) return;
          const body = {
            name: f.name.trim(),
            code: f.code.trim().toUpperCase(),
            entitlement_days: f.unlimited ? 0 : Number(f.days) || 0,
            is_unlimited: f.unlimited,
            maximum_days_per_request: Number(f.max) || 0,
            is_paid: f.paid,
            requires_document: f.certificate,
            document_deadline_days: Number(f.deadline) || 1,
            status: f.active ? 'active' : 'inactive',
          };
          if (await run('save', () => (f.id ? api.put(`/leave-types/${f.id}`, body) : api.post('/leave-types', body)), 'Leave type saved.')) {
            setF(null);
            await reload();
          }
        }}
      >
        {error ? <Banner message={error} /> : null}
        {f ? (
          <>
            <Field label="Name" required value={f.name} onChangeText={set('name')} placeholder="Sick Leave" />
            <Field label="Short code" required value={f.code} onChangeText={set('code')} autoCapitalize="characters" placeholder="SL" />
            <Toggle label="Unlimited" hint="No yearly limit — never refused for days" value={f.unlimited} onChange={set('unlimited')} />
            {!f.unlimited ? <Field label="Days per year" value={f.days} onChangeText={set('days')} keyboardType="number-pad" /> : null}
            <Field label="Most days in one request (0 = no limit)" value={f.max} onChangeText={set('max')} keyboardType="number-pad" />
            <Toggle label="Paid leave" value={f.paid} onChange={set('paid')} />
            <Toggle label="Needs a certificate" hint="Without it the leave becomes loss of pay" value={f.certificate} onChange={set('certificate')} />
            {f.certificate ? <Field label="Days after the leave to upload it" value={f.deadline} onChangeText={set('deadline')} keyboardType="number-pad" /> : null}
            <Toggle label="Active" hint="Employees can choose it" value={f.active} onChange={set('active')} />
          </>
        ) : null}
      </FormModal>
    </Screen>
  );
}

function Toggle({ label, hint, value, onChange }: { label: string; hint?: string; value: boolean; onChange: (v: boolean) => void }) {
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', marginBottom: 14 }}>
      <View style={{ flex: 1 }}>
        <Text style={{ fontWeight: '600', color: colors.text, fontSize: 15 }}>{label}</Text>
        {hint ? <Muted>{hint}</Muted> : null}
      </View>
      <Switch value={value} onValueChange={onChange} />
    </View>
  );
}
