import { Text, View } from 'react-native';

import { SectionHeader } from '../components/kit';
import { Banner, Card, Empty, Loading, Muted, Row, Screen, colors } from '../components/ui';
import { api } from '../lib/api';
import { useLoad } from '../lib/hooks';

type Compliance = {
  warning_days: number;
  programs: { id: number; name: string; code?: string | null; enrollments_count: number; active_enrollments_count: number; certificate_required?: boolean }[];
  enrollments_by_status: Record<string, number>;
  certificates: { valid: number; expiring_soon: number; expired: number; none: number };
  totals: { enrollments: number; certificates: number };
};

function Stat({ label, value, tone }: { label: string; value: number; tone?: string }) {
  return (
    <View style={{ flexBasis: '47%', flexGrow: 1, padding: 12, borderRadius: 10, backgroundColor: '#F3F4F6' }}>
      <Text style={{ fontSize: 22, fontWeight: '700', color: tone ?? colors.text }}>{value}</Text>
      <Text style={{ color: colors.muted }}>{label}</Text>
    </View>
  );
}

/** How the people you can see stand on training: who is enrolled, whose certificates lapse. */
export default function TrainingCompliance() {
  const { data, error, loading, reload } = useLoad(() => api.get<Compliance>('/training-compliance'));

  if (loading) return <Loading />;

  return (
    <Screen onRefresh={reload}>
      {error ? <Banner message={error} /> : null}
      {data ? (
        <>
          <Card>
            <SectionHeader title="Certificates" />
            <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
              <Stat label="Valid" value={data.certificates.valid} tone={colors.success} />
              <Stat label={`Expiring in ${data.warning_days} days`} value={data.certificates.expiring_soon} tone={colors.warning} />
              <Stat label="Expired" value={data.certificates.expired} tone={colors.danger} />
              <Stat label="Enrolments" value={data.totals.enrollments} />
            </View>
          </Card>
          <Card>
            <SectionHeader title="Enrolments by status" />
            {Object.entries(data.enrollments_by_status).map(([status, n]) => (
              <View key={status} style={{ flexDirection: 'row', paddingVertical: 5 }}>
                <Text style={{ flex: 1, color: colors.text, textTransform: 'capitalize' }}>{status.replace(/_/g, ' ')}</Text>
                <Text style={{ color: colors.text, fontWeight: '600' }}>{n}</Text>
              </View>
            ))}
          </Card>
          <Card>
            <SectionHeader title="Programmes" />
            {!data.programs.length ? (
              <Empty message="No programmes yet." />
            ) : (
              data.programs.map((p) => (
                <Row key={p.id} title={p.name} subtitle={[p.code, `${p.enrollments_count} enrolled`, `${p.active_enrollments_count} active`].filter(Boolean).join(' · ')} />
              ))
            )}
          </Card>
          <Muted>Counts cover only the people you are allowed to see.</Muted>
        </>
      ) : null}
    </Screen>
  );
}
