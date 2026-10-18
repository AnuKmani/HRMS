import * as ImagePicker from 'expo-image-picker';
import * as Location from 'expo-location';
import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { Alert, Pressable, Text, View } from 'react-native';

import { FormModal, PickerField } from '../components/kit';
import { Banner, Button, Card, Chip, Field, Loading, Muted, Screen, Title, colors } from '../components/ui';
import { api, ApiError } from '../lib/api';
import { shrinkPhoto } from '../lib/photos';
import { prettyDate, timeOf, uuid } from '../lib/format';
import { dismiss, enqueue, isOffline, pending, syncPending, type QueuedEvent } from '../lib/offline';

type Site = {
  id: number;
  name: string;
  code?: string;
  project_name?: string | null;
  latitude?: string | number | null;
  longitude?: string | number | null;
  geofence_radius?: string | number | null;
};
type Today = {
  date: string;
  checked_in: boolean;
  checked_out: boolean;
  attendance: { check_in_at?: string | null; check_out_at?: string | null; check_in_purpose?: string | null; site?: { name: string } | null } | null;
  open_attendance: { id: number; attendance_date?: string; check_in_at?: string | null } | null;
  site: Site | null;
  sites: Site[];
  requires_site?: boolean;
  shift?: { starts_at?: string | null; ends_at?: string | null; grace_minutes?: number } | null;
  working_minutes?: number;
  late_minutes?: number;
  max_gps_accuracy_metres?: number;
  can_check_in?: boolean;
  can_check_out?: boolean;
};
type Fix = { latitude: number; longitude: number; accuracy: number | null };

const PURPOSES = ['Site work', 'Office work', 'Inspection', 'Meeting', 'Material delivery'];

async function currentFix(): Promise<Fix> {
  const permission = await Location.requestForegroundPermissionsAsync();
  if (permission.status !== 'granted') {
    throw new ApiError('Location permission is needed to record where you check in and out.', 400);
  }
  const fix = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High });
  return { latitude: fix.coords.latitude, longitude: fix.coords.longitude, accuracy: fix.coords.accuracy ?? null };
}

/** Metres between two points. */
function distance(a: Fix, lat: number, lng: number): number {
  const r = 6371000;
  const rad = (d: number) => (d * Math.PI) / 180;
  const dLat = rad(lat - a.latitude);
  const dLng = rad(lng - a.longitude);
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.latitude)) * Math.cos(rad(lat)) * Math.sin(dLng / 2) ** 2;
  return 2 * r * Math.asin(Math.sqrt(h));
}

const hm = (m?: number) => (m ? `${Math.floor(m / 60)}h ${m % 60}m` : '0h');

/** Check in (site, purpose, location, selfie) and check out — with or without signal. */
export default function AttendanceScreen() {
  const router = useRouter();
  const [today, setToday] = useState<Today | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState<'in' | 'out' | 'sync' | 'gps' | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [siteId, setSiteId] = useState<number | null>(null);
  const [fix, setFix] = useState<Fix | null>(null);
  const [queue, setQueue] = useState<QueuedEvent[]>([]);
  const [asking, setAsking] = useState(false);
  const [purpose, setPurpose] = useState('');

  const refreshFix = useCallback(async () => {
    setBusy((b) => b ?? 'gps');
    try {
      setFix(await currentFix());
    } catch {
      // Shown when they try to check in.
    } finally {
      setBusy((b) => (b === 'gps' ? null : b));
    }
  }, []);

  const load = useCallback(async () => {
    setQueue(pending());
    if (pending().some((e) => !e.error)) {
      const r = await syncPending().catch(() => null);
      if (r && r.sent > 0) setNotice(`${r.sent} saved record(s) sent to the server.`);
      setQueue(pending());
    }
    try {
      const t = await api.get<Today>('/attendance/today');
      setToday(t);
      setSiteId((s) => s ?? t.site?.id ?? (t.sites.length === 1 ? t.sites[0].id : null));
      setError(null);
    } catch (e) {
      if (!isOffline(e)) setError((e as ApiError).message);
      else setError('No connection. You can still check in or out — it is saved on the phone and sent when you are back online.');
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      void load();
      void refreshFix();
    }, [load, refreshFix]),
  );

  const sites = today?.sites ?? [];
  const site = sites.find((s) => s.id === siteId) ?? today?.site ?? null;
  const maxAccuracy = today?.max_gps_accuracy_metres ?? 100;
  const siteDistance =
    fix && site && site.latitude != null && site.longitude != null ? Math.round(distance(fix, Number(site.latitude), Number(site.longitude))) : null;
  const radius = site?.geofence_radius != null ? Number(site.geofence_radius) : null;
  const queuedIn = queue.some((e) => e.kind === 'check-in' && !e.error);
  const queuedOut = queue.some((e) => e.kind === 'check-out' && !e.error);

  async function checkIn() {
    setAsking(false);
    setError(null);
    setNotice(null);
    setBusy('in');
    const id = uuid();
    try {
      const f = await currentFix();
      setFix(f);
      const camera = await ImagePicker.requestCameraPermissionsAsync();
      if (camera.status !== 'granted') throw new ApiError('Camera permission is needed for the check-in selfie.', 400);
      const shot = await ImagePicker.launchCameraAsync({ cameraType: ImagePicker.CameraType.front, quality: 0.5, allowsEditing: false });
      if (shot.canceled || !shot.assets?.length) return;
      const selfie = await shrinkPhoto(shot.assets[0].uri);
      const fields: Record<string, string> = {
        latitude: String(f.latitude),
        longitude: String(f.longitude),
        client_event_id: id,
        device_reference: 'HRMS app',
      };
      if (site?.id) fields.site_id = String(site.id);
      if (purpose.trim()) fields.purpose = purpose.trim();
      if (f.accuracy !== null) fields.accuracy = String(f.accuracy);
      const form = new FormData();
      for (const [k, v] of Object.entries({ ...fields, source: 'online' })) form.append(k, v);
      form.append('selfie', { uri: selfie, name: 'selfie.jpg', type: 'image/jpeg' } as never);
      try {
        await api.post('/attendance/check-in', form);
        Alert.alert('You are checked in', `${site ? `${site.name} · ` : ''}${new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`);
        setPurpose('');
        await load();
      } catch (e) {
        if (!isOffline(e)) throw e;
        enqueue({ id, kind: 'check-in', at: new Date().toISOString(), fields: { ...fields, source: 'offline' }, selfieUri: selfie });
        setQueue(pending());
        setNotice('No signal — your check-in is saved on the phone and will be sent automatically. Tap “Sync now” when you are back online.');
      }
    } catch (e) {
      setError(e instanceof ApiError ? e.firstMessage : 'Check-in failed. Please try again.');
    } finally {
      setBusy(null);
    }
  }

  async function checkOut() {
    setError(null);
    setNotice(null);
    setBusy('out');
    const id = uuid();
    try {
      const f = await currentFix();
      setFix(f);
      const fields: Record<string, string> = {
        latitude: String(f.latitude),
        longitude: String(f.longitude),
        client_event_id: id,
        device_reference: 'HRMS app',
      };
      if (site?.id) fields.site_id = String(site.id);
      if (f.accuracy !== null) fields.accuracy = String(f.accuracy);
      try {
        await api.post('/attendance/check-out', { ...fields, source: 'online' });
        Alert.alert('You are checked out', 'See you tomorrow.');
        await load();
      } catch (e) {
        if (!isOffline(e)) throw e;
        enqueue({ id, kind: 'check-out', at: new Date().toISOString(), fields: { ...fields, source: 'offline' } });
        setQueue(pending());
        setNotice('No signal — your check-out is saved on the phone and will be sent automatically.');
      }
    } catch (e) {
      setError(e instanceof ApiError ? e.firstMessage : 'Check-out failed. Please try again.');
    } finally {
      setBusy(null);
    }
  }

  async function syncNow() {
    setBusy('sync');
    setError(null);
    const r = await syncPending();
    setQueue(pending());
    setNotice(r.sent ? `${r.sent} record(s) sent.` : r.left ? 'Still no connection — try again in a moment.' : 'Nothing waiting.');
    setBusy(null);
    await load();
  }

  if (loading) return <Loading />;

  const open = today?.open_attendance;
  const openFromEarlier = open && open.attendance_date && open.attendance_date !== today?.date;
  const canIn = !queuedIn && (today ? (today.can_check_in ?? !today.checked_in) && !openFromEarlier : true);
  const canOut = !queuedOut && (today ? (today.can_check_out ?? (today.checked_in && !today.checked_out)) || !!openFromEarlier : queuedIn);
  const needsSite = !!today?.requires_site && sites.length === 0 && !today.site;

  return (
    <Screen onRefresh={load}>
      {error ? <Banner message={error} tone={error.startsWith('No connection') ? 'warning' : 'error'} /> : null}
      {notice ? <Banner message={notice} tone="success" /> : null}

      {queue.length ? (
        <Card>
          <Title>Saved on this phone</Title>
          {queue.map((e) => (
            <View key={e.id} style={{ paddingVertical: 6 }}>
              <Text style={{ color: colors.text, fontWeight: '600' }}>
                {e.kind === 'check-in' ? 'Check-in' : 'Check-out'} · {new Date(e.at).toLocaleString()}
              </Text>
              {e.error ? (
                <>
                  <Text style={{ color: colors.danger }}>Not accepted: {e.error}</Text>
                  <Pressable onPress={() => (dismiss(e.id), setQueue(pending()))}>
                    <Text style={{ color: colors.primary }}>Remove</Text>
                  </Pressable>
                </>
              ) : (
                <Muted>Waiting for a connection</Muted>
              )}
            </View>
          ))}
          <Button label="Sync now" onPress={syncNow} busy={busy === 'sync'} />
        </Card>
      ) : null}

      <Card>
        <Title>{prettyDate(today?.date ?? new Date().toISOString().slice(0, 10))}</Title>
        {today?.checked_out ? (
          <Chip label="Day complete" tone="success" />
        ) : today?.checked_in || queuedIn ? (
          <Chip label={queuedIn ? 'Checked in (saved on phone)' : 'Checked in'} tone="info" />
        ) : (
          <Chip label="Not checked in yet" tone="warning" />
        )}
        <View style={{ marginTop: 12, gap: 4 }}>
          <Text style={{ color: colors.text }}>In: {timeOf(today?.attendance?.check_in_at)}</Text>
          <Text style={{ color: colors.text }}>Out: {timeOf(today?.attendance?.check_out_at)}</Text>
          {today?.attendance?.check_in_purpose ? <Muted>Purpose: {today.attendance.check_in_purpose}</Muted> : null}
          {today?.working_minutes ? <Muted>Worked: {hm(today.working_minutes)}</Muted> : null}
          {today?.late_minutes ? <Muted>Late by {today.late_minutes} min</Muted> : null}
          {today?.shift?.starts_at ? (
            <Muted>
              Shift {timeOf(today.shift.starts_at)} – {timeOf(today.shift.ends_at)}
              {today.shift.grace_minutes ? ` · ${today.shift.grace_minutes} min grace` : ''}
            </Muted>
          ) : null}
        </View>
      </Card>

      {needsSite ? <Banner tone="warning" message="You are not assigned to a site yet. Ask your manager or HR to assign you before checking in." /> : null}

      {sites.length > 1 && canIn ? (
        <PickerField
          label="Work location"
          value={siteId}
          options={sites.map((s) => ({ value: s.id, label: s.name, hint: s.project_name ?? s.code ?? null }))}
          onChange={(v) => setSiteId(v as number)}
        />
      ) : site ? (
        <Muted>Work location: {site.name}{site.project_name ? ` · ${site.project_name}` : ''}</Muted>
      ) : null}

      <Card>
        <Title>Location</Title>
        {fix ? (
          <>
            <Text style={{ color: fix.accuracy != null && fix.accuracy > maxAccuracy ? colors.danger : colors.text }}>
              GPS accuracy: {fix.accuracy != null ? `±${Math.round(fix.accuracy)} m` : 'unknown'}
              {fix.accuracy != null && fix.accuracy > maxAccuracy ? ' — weak signal, move to open sky' : ''}
            </Text>
            {siteDistance != null ? (
              <Text style={{ color: radius != null && siteDistance > radius ? colors.warning : colors.success }}>
                {siteDistance} m from {site?.name}
                {radius != null ? (siteDistance > radius ? ` — outside the ${radius} m site area` : ' — inside the site area') : ''}
              </Text>
            ) : null}
          </>
        ) : (
          <Muted>Finding your location…</Muted>
        )}
        <Pressable onPress={refreshFix} style={{ marginTop: 6 }}>
          <Text style={{ color: colors.primary, fontWeight: '600' }}>{busy === 'gps' ? 'Locating…' : 'Refresh location'}</Text>
        </Pressable>
      </Card>

      {openFromEarlier ? (
        <Banner tone="warning" message={`You did not check out on ${prettyDate(open.attendance_date)}. Check out now to close that day.`} />
      ) : null}

      {canIn && !needsSite ? <Button testID="check-in" label="Check in (selfie)" onPress={() => setAsking(true)} busy={busy === 'in'} /> : null}
      {canOut ? <Button testID="check-out" label="Check out" onPress={checkOut} busy={busy === 'out'} /> : null}

      <Button label="My attendance history & earnings" kind="outline" onPress={() => router.push('/attendance-records')} />
      <Muted>Your location is recorded when you check in and out, and a selfie at check-in. With no signal it is saved on the phone and sent later.</Muted>

      <FormModal visible={asking} title="Check in" saveLabel="Take selfie" onClose={() => setAsking(false)} onSave={checkIn}>
        {site ? <Muted>At: {site.name}</Muted> : null}
        <Field label="Purpose" value={purpose} onChangeText={setPurpose} placeholder="What will you be doing here today?" />
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
          {PURPOSES.map((p) => (
            <Pressable key={p} onPress={() => setPurpose(p)} style={{ borderWidth: 1, borderColor: colors.border, borderRadius: 16, paddingHorizontal: 10, paddingVertical: 6 }}>
              <Text style={{ color: colors.text }}>{p}</Text>
            </Pressable>
          ))}
        </View>
      </FormModal>
    </Screen>
  );
}
