import * as Location from 'expo-location';
import { useState } from 'react';
import { Text, View } from 'react-native';

import { PickerField, SectionHeader } from '../components/kit';
import { Banner, Button, Card, Chip, Empty, Field, Loading, Muted, Row, Screen, colors } from '../components/ui';
import { api, ApiError } from '../lib/api';
import { timeOf, uuid } from '../lib/format';
import { useAction, useLoad } from '../lib/hooks';

type Visit = { id: number; site?: { name: string } | null; started_at: string; ended_at?: string | null; duration_minutes?: number | null; purpose?: string | null };
type SiteSummary = { id: number; name: string; project_name?: string | null };

async function fix() {
  const p = await Location.requestForegroundPermissionsAsync();
  if (p.status !== 'granted') throw new ApiError('Location permission is needed to record the visit.');
  const f = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High });
  return { latitude: f.coords.latitude, longitude: f.coords.longitude, accuracy: f.coords.accuracy ?? null };
}

/** Start a site visit when you arrive, end it (site out) when you leave. */
export default function SiteVisitsScreen() {
  const { data, loading, error: loadError, reload } = useLoad(async () => {
    const [visits, today] = await Promise.all([
      api.get<{ items: Visit[] }>('/site-visits/today').then((d) => d.items),
      api.get<{ visit_sites: SiteSummary[] }>('/attendance/today'),
    ]);
    return { visits, sites: today.visit_sites ?? [] };
  });
  const { busy, error, notice, run } = useAction();
  const [siteId, setSiteId] = useState<number | null>(null);
  const [purpose, setPurpose] = useState('');

  if (loading) return <Loading />;
  const openVisit = data?.visits.find((v) => !v.ended_at);

  return (
    <Screen onRefresh={reload}>
      {loadError ? <Banner message={loadError} /> : null}
      {error ? <Banner message={error} /> : null}
      {notice ? <Banner message={notice} tone="success" /> : null}
      {openVisit ? (
        <Card>
          <SectionHeader title="On site now" />
          <Text style={{ fontSize: 17, fontWeight: '700', color: colors.text }}>{openVisit.site?.name}</Text>
          <Muted>Since {timeOf(openVisit.started_at)} · {openVisit.purpose}</Muted>
          <View style={{ marginTop: 10 }}>
            <Button
              testID="end-visit"
              label="Site out (end visit)"
              busy={busy === 'end'}
              onPress={async () => {
                if (await run('end', async () => api.post(`/site-visits/${openVisit.id}/end`, { ...(await fix()), client_event_id: uuid() }), 'Visit ended.')) await reload();
              }}
            />
          </View>
        </Card>
      ) : (
        <Card>
          <SectionHeader title="Start a site visit" />
          <PickerField label="Site" required value={siteId} options={(data?.sites ?? []).map((s) => ({ value: s.id, label: s.name, hint: s.project_name ?? null }))} onChange={(v) => setSiteId(v as number)} />
          <Field label="Purpose" required value={purpose} onChangeText={setPurpose} placeholder="Inspection, meeting, snag check…" />
          <Button
            testID="start-visit"
            label="Start visit"
            busy={busy === 'start'}
            onPress={async () => {
              if (!siteId || !purpose.trim()) return;
              const ok = await run(
                'start',
                async () => api.post('/site-visits/start', { site_id: siteId, purpose: purpose.trim(), ...(await fix()), client_event_id: uuid(), device_reference: 'HRMS app' }),
                'Visit started.',
              );
              if (ok) {
                setPurpose('');
                await reload();
              }
            }}
          />
        </Card>
      )}
      <Card>
        <SectionHeader title="Today's visits" />
        {!data?.visits.length ? <Empty message="No visits today." /> : null}
        {data?.visits.map((v) => (
          <Row
            key={v.id}
            title={v.site?.name ?? 'Site'}
            subtitle={`${timeOf(v.started_at)} – ${v.ended_at ? timeOf(v.ended_at) : 'now'}${v.duration_minutes ? ` · ${v.duration_minutes} min` : ''}${v.purpose ? ` · ${v.purpose}` : ''}`}
            right={<Chip label={v.ended_at ? 'Done' : 'On site'} tone={v.ended_at ? 'success' : 'info'} />}
          />
        ))}
      </Card>
    </Screen>
  );
}
