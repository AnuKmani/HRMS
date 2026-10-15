import { useRouter } from 'expo-router';
import { useState } from 'react';

import { Tabs } from '../../components/kit';
import { Banner, Button, Card, Chip, Empty, Loading, Row, Screen } from '../../components/ui';
import { api, type Page } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { money } from '../../lib/format';
import { useLoad } from '../../lib/hooks';
import { LOAN_STATUS, loanTone, type Loan } from '../../lib/loans';

export default function LoansScreen() {
  const router = useRouter();
  const { can, user } = useAuth();
  const approver = can('loans.approve');
  const [tab, setTab] = useState<'mine' | 'pending' | 'all'>('mine');
  const { data, error, loading, reload } = useLoad(
    () =>
      api
        .get<Page<Loan>>('/loans', { per_page: 100, ...(tab === 'mine' ? { employee_id: user?.employee?.id } : tab === 'pending' ? { status: 'pending' } : {}) })
        .then((p) => (tab === 'mine' ? p.items.filter((l) => l.employee_id === user?.employee?.id) : p.items)),
    [tab],
  );
  return (
    <Screen onRefresh={reload}>
      {can('loans.create') ? <Button label="Request a loan or advance" onPress={() => router.push('/loans/new')} /> : null}
      {approver ? <Tabs value={tab} onChange={setTab} options={[['mine', 'Mine'], ['pending', 'To approve'], ['all', 'All']]} /> : null}
      {error ? <Banner message={error} /> : null}
      {loading ? (
        <Loading />
      ) : !data?.length ? (
        <Empty message="No loans." />
      ) : (
        <Card>
          {data.map((l) => (
            <Row
              key={l.id}
              title={`${tab !== 'mine' ? `${l.employee?.full_name ?? ''} · ` : ''}${l.type_label ?? l.loan_type} · ${money(l.principal_amount, l.currency)}`}
              subtitle={`${l.number_of_installments} × ${money(l.installment_amount, l.currency)} · left ${money(l.outstanding_balance, l.currency)}`}
              right={<Chip label={LOAN_STATUS[l.status] ?? l.status} tone={loanTone(l.status)} />}
              onPress={() => router.push(`/loans/${l.id}`)}
            />
          ))}
        </Card>
      )}
    </Screen>
  );
}
