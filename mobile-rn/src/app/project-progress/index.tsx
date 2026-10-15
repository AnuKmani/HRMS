import { useRouter } from 'expo-router';

import { Banner, Card, Chip, Empty, Loading, Row, Screen } from '../../components/ui';
import { api } from '../../lib/api';
import { useLoad } from '../../lib/hooks';
import { pct, statusName } from '../../lib/projects';

type P = { id: number; name: string; code: string; status: string; progress_percent: number; behind_schedule: boolean; milestones_due: number };

/** Sales: every project's progress and payments due. */
export default function ProjectProgressList() {
  const router = useRouter();
  const { data, error, loading, reload } = useLoad(() => api.get<P[]>('/project-progress'));
  if (loading) return <Loading />;
  return (
    <Screen onRefresh={reload}>
      {error ? <Banner message={error} /> : null}
      {!data?.length ? <Empty message="No projects yet." /> : null}
      {data?.length ? (
        <Card>
          {data.map((p) => (
            <Row
              key={p.id}
              title={p.name}
              subtitle={`${p.code} · ${statusName(p.status)}${p.milestones_due ? ` · ${p.milestones_due} payment due` : ''}`}
              right={p.behind_schedule ? <Chip label="Behind" tone="danger" /> : <Chip label={pct(p.progress_percent)} tone="info" />}
              onPress={() => router.push(`/project-progress/${p.id}`)}
            />
          ))}
        </Card>
      ) : null}
    </Screen>
  );
}
