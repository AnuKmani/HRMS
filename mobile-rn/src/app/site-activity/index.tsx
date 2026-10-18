import { useRouter } from 'expo-router';
import { useState } from 'react';

import { Tabs } from '../../components/kit';
import { Banner, Button, Card, Chip, Empty, Loading, Row, Screen } from '../../components/ui';
import { api, type Page } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { prettyDate } from '../../lib/format';
import { useLoad } from '../../lib/hooks';
import type { SiteActivityReport } from '../../lib/siteActivity';

/** Site activity reports: what one person did at a site, with photos and where it was written. */
export default function SiteActivityList() {
  const router = useRouter();
  const { can } = useAuth();
  const [status, setStatus] = useState<'all' | 'draft' | 'submitted'>('all');
  const { data, error, loading, reload } = useLoad(
    () => api.get<Page<SiteActivityReport>>('/site-activity-reports', { per_page: 50, status: status === 'all' ? undefined : status }).then((p) => p.items),
    [status],
  );

  return (
    <Screen onRefresh={reload}>
      {can('site_activity_reports.create') ? <Button label="New activity report" onPress={() => router.push('/site-activity/form')} /> : null}
      <Tabs value={status} onChange={setStatus} options={[['all', 'All'], ['draft', 'Drafts'], ['submitted', 'Submitted']]} />
      {error ? <Banner message={error} /> : null}
      {loading ? (
        <Loading />
      ) : !data?.length ? (
        <Empty message="No activity reports yet." />
      ) : (
        <Card>
          {data.map((r) => (
            <Row
              key={r.id}
              title={`${r.site?.name ?? 'Site'} · ${prettyDate(r.report_date)}`}
              subtitle={[r.work_category, `${r.progress_percentage}%`, r.employee?.full_name, r.photo_count ? `${r.photo_count} photo(s)` : null].filter(Boolean).join(' · ')}
              right={<Chip label={r.is_draft ? 'Draft' : 'Submitted'} tone={r.is_draft ? 'warning' : 'success'} />}
              onPress={() => router.push(`/site-activity/${r.id}`)}
            />
          ))}
        </Card>
      )}
    </Screen>
  );
}
