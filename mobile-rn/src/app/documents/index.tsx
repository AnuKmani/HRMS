import { useRouter } from 'expo-router';
import { useState } from 'react';

import { Tabs } from '../../components/kit';
import { Banner, Button, Card, Chip, Empty, Loading, Row, Screen } from '../../components/ui';
import { api, type Page } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { DOC_STATUS, docTone, expiryText, type Doc } from '../../lib/documents';
import { prettyDate } from '../../lib/format';
import { useLoad } from '../../lib/hooks';

type TabKey = 'mine' | 'verify' | 'expiring' | 'all';

/** Passports, visas, IDs, licences — uploaded, verified, watched for expiry. */
export default function DocumentsScreen() {
  const router = useRouter();
  const { can, user } = useAuth();
  const tabs: [TabKey, string][] = [['mine', 'Mine']];
  if (can('documents.verify')) tabs.push(['verify', 'To verify']);
  if (can('documents.expiry.view')) tabs.push(['expiring', 'Expiring']);
  if (can('employees.view')) tabs.push(['all', 'All']);
  const [tab, setTab] = useState<TabKey>('mine');
  const { data, error, loading, reload } = useLoad(() => {
    if (tab === 'expiring') return api.get<Page<Doc>>('/employee-documents/expiring', { within: 60, per_page: 200 }).then((p) => p.items);
    const q = tab === 'mine' ? { employee_id: user?.employee?.id } : tab === 'verify' ? { status: 'pending' } : {};
    return api.get<Page<Doc>>('/employee-documents', { ...q, per_page: 200 }).then((p) => p.items);
  }, [tab]);

  return (
    <Screen onRefresh={reload}>
      {can('documents.create') ? <Button label="Upload a document" onPress={() => router.push('/documents/new')} /> : null}
      {tabs.length > 1 ? <Tabs value={tab} onChange={setTab} options={tabs} /> : null}
      {error ? <Banner message={error} /> : null}
      {loading ? (
        <Loading />
      ) : !data?.length ? (
        <Empty message={tab === 'expiring' ? 'Nothing expiring in the next 60 days.' : 'No documents.'} />
      ) : (
        <Card>
          {data.map((d) => {
            const exp = expiryText(d);
            return (
              <Row
                key={d.id}
                title={`${tab !== 'mine' ? `${d.employee?.full_name ?? ''} · ` : ''}${d.document_type?.name ?? 'Document'}`}
                subtitle={[d.document_number, d.expiry_date ? `expires ${prettyDate(d.expiry_date)}` : null, exp].filter(Boolean).join(' · ')}
                right={<Chip label={exp && d.status === 'valid' ? exp : DOC_STATUS[d.status] ?? d.status} tone={exp ? 'warning' : docTone(d.status)} />}
                onPress={() => router.push(`/documents/${d.id}`)}
              />
            );
          })}
        </Card>
      )}
    </Screen>
  );
}
