import { useRouter } from 'expo-router';

import { Banner, Button, Card, Chip, Empty, Loading, Row, Screen } from '../../components/ui';
import { api, type Page } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { prettyDate } from '../../lib/format';
import { useLoad } from '../../lib/hooks';
import type { DailyReport } from '../../lib/reports';

/** Daily site reports: one per site per day. */
export default function SiteReportsList() {
  const router = useRouter();
  const { can } = useAuth();
  const { data, error, loading, reload } = useLoad(() => api.get<Page<DailyReport>>('/daily-site-reports', { per_page: 50 }).then((p) => p.items));
  return (
    <Screen onRefresh={reload}>
      {can('daily_site_reports.create') ? <Button testID="new-report" label="New daily report" onPress={() => router.push('/site-reports/form')} /> : null}
      {error ? <Banner message={error} /> : null}
      {loading ? (
        <Loading />
      ) : !data?.length ? (
        <Empty message="No daily reports yet." />
      ) : (
        <Card>
          {data.map((r) => (
            <Row
              key={r.id}
              testID={`report-${r.id}`}
              title={`${r.site?.name ?? 'Site'} · ${prettyDate(r.report_date)}`}
              subtitle={[r.project?.name, r.creator?.name, `${r.total_manpower} people`].filter(Boolean).join(' · ')}
              right={<Chip label={r.is_draft ? 'Draft' : 'Submitted'} tone={r.is_draft ? 'warning' : 'success'} />}
              onPress={() => router.push(`/site-reports/${r.id}`)}
            />
          ))}
        </Card>
      )}
    </Screen>
  );
}
