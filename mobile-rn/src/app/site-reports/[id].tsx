import * as ImagePicker from 'expo-image-picker';
import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { Image, Pressable, Text, View } from 'react-native';

import { KeyValues, SectionHeader, confirm } from '../../components/kit';
import { Banner, Button, Card, Chip, Loading, Muted, Screen, colors } from '../../components/ui';
import { api, getToken } from '../../lib/api';
import { uploadField } from '../../lib/photos';
import { useAuth } from '../../lib/auth';
import { apiUrl } from '../../lib/config';
import { openRemoteFile } from '../../lib/files';
import { prettyDate } from '../../lib/format';
import { useAction, useLoad } from '../../lib/hooks';
import type { DailyReport } from '../../lib/reports';

export default function SiteReportDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const router = useRouter();
  const { user, can } = useAuth();
  const { data: r, error: loadError, loading, reload } = useLoad(() => api.get<DailyReport>(`/daily-site-reports/${id}`), [id]);
  const { busy, error, notice, run } = useAction();

  if (loading) return <Loading />;
  if (!r) return <Screen>{loadError ? <Banner message={loadError} /> : null}</Screen>;

  const mine = r.created_by === user?.id;
  const editable = r.is_editable && (mine || can('daily_site_reports.manage'));

  async function addPhotos(fromCamera: boolean) {
    const permission = fromCamera ? await ImagePicker.requestCameraPermissionsAsync() : await ImagePicker.requestMediaLibraryPermissionsAsync();
    if (!permission.granted) return;
    const result = fromCamera
      ? await ImagePicker.launchCameraAsync({ quality: 0.6 })
      : await ImagePicker.launchImageLibraryAsync({ quality: 0.6, allowsMultipleSelection: true, selectionLimit: 6, mediaTypes: ['images'] });
    if (result.canceled || !result.assets?.length) return;
    const form = new FormData();
    for (const [i, a] of result.assets.slice(0, 6).entries()) form.append('photos[]', await uploadField(a.uri, `site-photo-${i + 1}.jpg`, 'image/jpeg'));
    if (await run('photos', () => api.post(`/daily-site-reports/${r!.id}/photos`, form), 'Photographs added.')) await reload();
  }

  return (
    <Screen onRefresh={reload}>
      <Stack.Screen options={{ title: `${r.site?.name ?? 'Site'} · ${prettyDate(r.report_date)}` }} />
      {error ? <Banner message={error} /> : null}
      {notice ? <Banner message={notice} tone="success" /> : null}
      <Card>
        <View style={{ flexDirection: 'row', alignItems: 'center', marginBottom: 8 }}>
          <Text style={{ flex: 1, fontSize: 17, fontWeight: '700', color: colors.text }}>{r.site?.name}</Text>
          <Chip label={r.is_draft ? 'Draft' : 'Submitted'} tone={r.is_draft ? 'warning' : 'success'} />
        </View>
        <KeyValues
          rows={[
            ['Project', r.project?.name],
            ['Date', prettyDate(r.report_date)],
            ['Written by', r.creator?.name],
            ['Manpower', `${r.total_manpower} (${r.manpower.map((m) => `${m.category} ${m.count}`).join(', ') || '—'})`],
          ]}
        />
      </Card>
      <Card>
        <SectionHeader title="Work" />
        <Text style={{ fontWeight: '600', color: colors.text }}>Planned</Text>
        <Text style={{ color: colors.text, marginBottom: 8 }}>{r.work_planned}</Text>
        <Text style={{ fontWeight: '600', color: colors.text }}>Completed</Text>
        <Text style={{ color: colors.text }}>{r.work_completed}</Text>
      </Card>
      {r.stage_progress.length ? (
        <Card>
          <SectionHeader title="Progress by stage" />
          {r.stage_progress.map((s) => (
            <Text key={s.stage_id} style={{ color: colors.text, paddingVertical: 3 }}>
              {s.name}: {s.percent}%
            </Text>
          ))}
        </Card>
      ) : null}
      {r.materials.length || r.equipment.length ? (
        <Card>
          <SectionHeader title="Materials & equipment" />
          {r.materials.map((m, i) => (
            <Text key={`m${i}`} style={{ color: colors.text }}>• {m.material_name}: {m.quantity} {m.unit ?? ''}</Text>
          ))}
          {r.equipment.map((e, i) => (
            <Text key={`e${i}`} style={{ color: colors.text }}>• {e.equipment_name} × {e.quantity}{e.operating_hours ? ` · ${e.operating_hours} h` : ''}</Text>
          ))}
        </Card>
      ) : null}
      {r.safety_observations || r.delays || r.issues || r.remarks ? (
        <Card>
          <KeyValues rows={[['Safety', r.safety_observations], ['Delays', r.delays], ['Issues', r.issues], ['Remarks', r.remarks]]} />
        </Card>
      ) : null}
      <Card>
        <SectionHeader title={`Photographs (${r.photos.length})`} />
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
          {r.photos.map((ph) => (
            <Pressable
              key={ph.id}
              onLongPress={async () => {
                if (editable && (await confirm('Remove photograph?', 'It will be deleted from the report.', 'Remove'))) {
                  if (await run('del', () => api.del(`/daily-site-reports/${r.id}/photos/${ph.id}`), 'Photograph removed.')) await reload();
                }
              }}
            >
              <Image
                source={{ uri: `${apiUrl()}/daily-site-reports/${r.id}/photos/${ph.id}`, headers: { Authorization: `Bearer ${getToken() ?? ''}` } }}
                style={{ width: 100, height: 100, borderRadius: 8, backgroundColor: '#E5E7EB' }}
              />
            </Pressable>
          ))}
        </View>
        {editable ? (
          <View style={{ marginTop: 10 }}>
            <Button label="Take photo" kind="outline" onPress={() => addPhotos(true)} busy={busy === 'photos'} />
            <Button label="Choose from gallery" kind="outline" onPress={() => addPhotos(false)} />
            <Muted>Long-press a photo to remove it.</Muted>
          </View>
        ) : null}
      </Card>
      {editable && r.is_draft ? (
        <Button label="Submit report" onPress={async () => { if (await run('submit', () => api.post(`/daily-site-reports/${r.id}/submit`), 'Report submitted.')) await reload(); }} busy={busy === 'submit'} />
      ) : null}
      {editable ? <Button label="Edit report" kind="outline" onPress={() => router.push(`/site-reports/form?id=${r.id}`)} /> : null}
      {!r.is_editable ? <Muted>Reports can only be changed on the day they are written.</Muted> : null}
      {can('daily_site_reports.pdf') ? (
        <Button label="Open PDF" kind="outline" onPress={() => run('pdf', () => openRemoteFile(`/daily-site-reports/${r.id}/pdf`, `daily-report-${r.id}.pdf`))} busy={busy === 'pdf'} />
      ) : null}
    </Screen>
  );
}
