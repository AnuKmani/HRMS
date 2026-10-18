import * as ImagePicker from 'expo-image-picker';
import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { Image, Pressable, Text, View } from 'react-native';

import { KeyValues, SectionHeader, confirm } from '../../components/kit';
import { Banner, Button, Card, Chip, Loading, Muted, Screen, colors } from '../../components/ui';
import { api, getToken } from '../../lib/api';
import { uploadField } from '../../lib/photos';
import { useAuth } from '../../lib/auth';
import { apiUrl } from '../../lib/config';
import { prettyDate } from '../../lib/format';
import { useAction, useLoad } from '../../lib/hooks';
import { reportGps, type SiteActivityReport } from '../../lib/siteActivity';

export default function SiteActivityDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const router = useRouter();
  const { can } = useAuth();
  const { data: r, error: loadError, loading, reload } = useLoad(() => api.get<SiteActivityReport>(`/site-activity-reports/${id}`), [id]);
  const { busy, error, notice, run } = useAction();

  if (loading) return <Loading />;
  if (!r) return <Screen>{loadError ? <Banner message={loadError} /> : null}</Screen>;

  // The server decides who may change it; is_editable already accounts for that.
  const editable = r.is_editable && can('site_activity_reports.update');
  const photos = r.photos ?? [];

  async function addPhotos(fromCamera: boolean) {
    const permission = fromCamera ? await ImagePicker.requestCameraPermissionsAsync() : await ImagePicker.requestMediaLibraryPermissionsAsync();
    if (!permission.granted) return;
    const result = fromCamera
      ? await ImagePicker.launchCameraAsync({ quality: 0.6 })
      : await ImagePicker.launchImageLibraryAsync({ quality: 0.6, allowsMultipleSelection: true, selectionLimit: 6, mediaTypes: ['images'] });
    if (result.canceled || !result.assets?.length) return;
    const form = new FormData();
    for (const [i, a] of result.assets.slice(0, 6).entries()) form.append('photos[]', await uploadField(a.uri, `activity-photo-${i + 1}.jpg`, 'image/jpeg'));
    if (await run('photos', () => api.post(`/site-activity-reports/${r!.id}/photos`, form), 'Photographs added.')) await reload();
  }

  async function submit() {
    if (await run('submit', async () => api.post(`/site-activity-reports/${r!.id}/submit`, await reportGps()), 'Report submitted.')) await reload();
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
            ['Written by', r.employee?.full_name],
            ['Category', r.work_category],
            ['Progress', `${r.progress_percentage}%`],
            ['Location', r.has_gps_fix ? `Recorded (±${Math.round(Number(r.gps_accuracy ?? 0))} m)` : 'Not recorded'],
            ['Submitted', r.submitted_at ? prettyDate(r.submitted_at) : null],
          ]}
        />
      </Card>
      <Card>
        <SectionHeader title="Work performed" />
        <Text style={{ color: colors.text }}>{r.work_performed}</Text>
      </Card>
      {r.manpower || r.materials_used || r.equipment_used || r.issues || r.safety_issues || r.remarks ? (
        <Card>
          <KeyValues
            rows={[
              ['Manpower', r.manpower],
              ['Materials', r.materials_used],
              ['Equipment', r.equipment_used],
              ['Issues', r.issues],
              ['Safety', r.safety_issues],
              ['Remarks', r.remarks],
            ]}
          />
        </Card>
      ) : null}
      <Card>
        <SectionHeader title={`Photographs (${photos.length})`} />
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
          {photos.map((ph) => (
            <Pressable
              key={ph.id}
              onLongPress={async () => {
                if (editable && (await confirm('Remove photograph?', 'It will be deleted from the report.', 'Remove'))) {
                  if (await run('del', () => api.del(`/site-activity-reports/${r.id}/photos/${ph.id}`), 'Photograph removed.')) await reload();
                }
              }}
            >
              <Image
                source={{ uri: `${apiUrl()}/site-activity-reports/${r.id}/photos/${ph.id}`, headers: { Authorization: `Bearer ${getToken() ?? ''}` } }}
                style={{ width: 100, height: 100, borderRadius: 8, backgroundColor: '#E5E7EB' }}
              />
            </Pressable>
          ))}
        </View>
        {editable ? (
          <View style={{ marginTop: 10 }}>
            <Button label="Take photo" kind="outline" onPress={() => addPhotos(true)} busy={busy === 'photos'} />
            <Button label="Choose from gallery" kind="outline" onPress={() => addPhotos(false)} />
            {photos.length ? <Muted>Long-press a photo to remove it.</Muted> : null}
          </View>
        ) : null}
      </Card>
      {editable && r.is_draft ? <Button label="Submit report" onPress={submit} busy={busy === 'submit'} /> : null}
      {editable ? <Button label="Edit report" kind="outline" onPress={() => router.push(`/site-activity/form?id=${r.id}`)} /> : null}
      {!r.is_editable ? <Muted>Submitted reports can no longer be changed.</Muted> : null}
    </Screen>
  );
}
