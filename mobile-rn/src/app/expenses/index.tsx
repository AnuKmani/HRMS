import { useRouter } from 'expo-router';
import { useState } from 'react';

import { STATUS_LABEL, statusTone } from '../../components/Approvals';
import { Tabs } from '../../components/kit';
import { Banner, Button, Card, Chip, Empty, Loading, Row, Screen } from '../../components/ui';
import { api, type Page } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { prettyDate } from '../../lib/format';
import { useLoad } from '../../lib/hooks';

export type Expense = {
  id: number;
  employee_id: number;
  employee?: { full_name?: string } | null;
  category?: { name: string } | null;
  project?: { name: string } | null;
  site?: { name: string } | null;
  expense_date: string;
  amount: string | number;
  currency: string;
  description: string;
  status: string;
  is_draft: boolean;
  is_open: boolean;
  requires_receipt?: boolean;
  receipt_count?: number;
  receipts?: { id: number; original_name: string; mime_type: string }[];
  approval_chain?: any[];
};

export const amount = (e: Pick<Expense, 'amount' | 'currency'>) => `${e.currency} ${Number(e.amount).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

export default function ExpenseList() {
  const router = useRouter();
  const { can, user } = useAuth();
  const approver = can('expenses.approve');
  const [tab, setTab] = useState<'mine' | 'team'>('mine');
  const { data, error, loading, reload } = useLoad(
    () =>
      api
        .get<Page<Expense>>('/expenses', { per_page: 100, ...(tab === 'mine' ? { employee_id: user?.employee?.id } : { status: 'pending' }) })
        .then((p) => (tab === 'team' ? p.items.filter((e) => e.employee_id !== user?.employee?.id) : p.items.filter((e) => e.employee_id === user?.employee?.id))),
    [tab],
  );
  return (
    <Screen onRefresh={reload}>
      {can('expenses.create') && user?.employee ? <Button label="New expense claim" onPress={() => router.push('/expenses/new')} /> : null}
      {approver ? <Tabs value={tab} onChange={setTab} options={[['mine', 'My claims'], ['team', 'To approve']]} /> : null}
      {error ? <Banner message={error} /> : null}
      {loading ? (
        <Loading />
      ) : !data?.length ? (
        <Empty message={tab === 'team' ? 'Nothing waiting for approval.' : 'No expense claims yet.'} />
      ) : (
        <Card>
          {data.map((e) => (
            <Row
              key={e.id}
              title={`${tab === 'team' ? `${e.employee?.full_name ?? ''} · ` : ''}${amount(e)}`}
              subtitle={`${prettyDate(e.expense_date)} · ${e.category?.name ?? ''} · ${e.description}`}
              right={<Chip label={STATUS_LABEL[e.status] ?? e.status} tone={statusTone(e.status)} />}
              onPress={() => router.push(`/expenses/${e.id}`)}
            />
          ))}
        </Card>
      )}
    </Screen>
  );
}
