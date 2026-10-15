import { useRouter } from 'expo-router';

import { Banner, Card, Empty, Loading, Row, Screen } from '../../components/ui';
import { api } from '../../lib/api';
import { useLoad } from '../../lib/hooks';

export type ReportDef = {
  key: string;
  title: string;
  description?: string | null;
  supported_filters: string[];
  columns: { key: string; label: string; type: string }[];
  formats: string[];
};

/** Every report the signed-in person is allowed to run. */
export default function ReportsIndex() {
  const router = useRouter();
  const { data, error, loading, reload } = useLoad(() => api.get<{ items: ReportDef[] }>('/reports').then((r) => r.items), []);
  return (
    <Screen onRefresh={reload}>
      {error ? <Banner message={error} /> : null}
      {loading ? (
        <Loading />
      ) : !data?.length ? (
        <Empty message="No reports available to you." />
      ) : (
        <Card>
          {data.map((r) => (
            <Row key={r.key} title={r.title} subtitle={r.description ?? undefined} onPress={() => router.push(`/reports/${r.key}`)} />
          ))}
        </Card>
      )}
    </Screen>
  );
}
