import * as ImagePicker from 'expo-image-picker';
import * as Location from 'expo-location';
import { useFocusEffect } from 'expo-router';
import { useCallback, useState } from 'react';
import { Text, View } from 'react-native';

import { Banner, Button, Card, Chip, Loading, Muted, Screen, Title, colors } from '../components/ui';
import { api, ApiError, fileField } from '../lib/api';
import { prettyDate, timeOf, uuid } from '../lib/format';

type Site = { id: number; name: string; project_name?: string | null };
type Today = {
  date: string;
  checked_in: boolean;
  checked_out: boolean;
  attendance: { check_in_at?: string | null; check_out_at?: string | null; site?: { name: string } | null } | null;
  open_attendance: { id: number; attendance_date?: string; check_in_at?: string | null } | null;
  site: Site | null;
  sites: Site[];
};

async function currentFix() {
  const permission = await Location.requestForegroundPermissionsAsync();
  if (permission.status !== 'granted') {
    throw new ApiError('Location permission is needed to record where you check in and out.');
  }
  const fix = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High });
  return {
    latitude: fix.coords.latitude,
    longitude: fix.coords.longitude,
    accuracy: fix.coords.accuracy ?? null,
  };
}

/** Check in (location + selfie) and check out (location). */
export default function AttendanceScreen() {
  const [today, setToday] = useState<Today | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState<'in' | 'out' | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setToday(await api.get<Today>('/attendance/today'));
      setError(null);
    } catch (e) {
      setError((e as ApiError).message);
    } finally {
      setLoading(false);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      void load();
    }, [load]),
  );

  async function checkIn() {
    setError(null);
    setDone(null);
    setBusy('in');
    try {
      const fix = await currentFix();
      const camera = await ImagePicker.requestCameraPermissionsAsync();
      if (camera.status !== 'granted') throw new ApiError('Camera permission is needed for the check-in selfie.');
      const shot = await ImagePicker.launchCameraAsync({
        cameraType: ImagePicker.CameraType.front,
        quality: 0.6,
        allowsEditing: false,
      });
      if (shot.canceled || !shot.assets?.length) {
        setBusy(null);
        return;
      }
      const form = new FormData();
      if (today?.site?.id) form.append('site_id', String(today.site.id));
      form.append('latitude', String(fix.latitude));
      form.append('longitude', String(fix.longitude));
      if (fix.accuracy !== null) form.append('accuracy', String(fix.accuracy));
      form.append('client_event_id', uuid());
      form.append('source', 'online');
      form.append('device_reference', 'HRMS app');
      form.append('selfie', fileField(shot.assets[0].uri, 'selfie.jpg', 'image/jpeg'));
      await api.post('/attendance/check-in', form);
      setDone('Checked in.');
      await load();
    } catch (e) {
      setError(e instanceof ApiError ? e.firstMessage : 'Check-in failed. Please try again.');
    } finally {
      setBusy(null);
    }
  }

  async function checkOut() {
    setError(null);
    setDone(null);
    setBusy('out');
    try {
      const fix = await currentFix();
      await api.post('/attendance/check-out', {
        ...(today?.site?.id ? { site_id: today.site.id } : {}),
        ...fix,
        client_event_id: uuid(),
        source: 'online',
        device_reference: 'HRMS app',
      });
      setDone('Checked out. See you tomorrow.');
      await load();
    } catch (e) {
      setError(e instanceof ApiError ? e.firstMessage : 'Check-out failed. Please try again.');
    } finally {
      setBusy(null);
    }
  }

  if (loading) return <Loading />;

  const open = today?.open_attendance;
  const openFromEarlier = open && open.attendance_date && open.attendance_date !== today?.date;

  return (
    <Screen onRefresh={load}>
      {error ? <Banner message={error} /> : null}
      {done ? <Banner message={done} tone="success" /> : null}
      <Card>
        <Title>{prettyDate(today?.date)}</Title>
        {today?.checked_out ? (
          <Chip label="Day complete" tone="success" />
        ) : today?.checked_in ? (
          <Chip label="Checked in" tone="info" />
        ) : (
          <Chip label="Not checked in yet" tone="warning" />
        )}
        <View style={{ marginTop: 12, gap: 4 }}>
          <Text style={{ color: colors.text }}>In: {timeOf(today?.attendance?.check_in_at)}</Text>
          <Text style={{ color: colors.text }}>Out: {timeOf(today?.attendance?.check_out_at)}</Text>
          <Muted>Site: {today?.attendance?.site?.name ?? today?.site?.name ?? 'No site assigned'}</Muted>
        </View>
      </Card>

      {openFromEarlier ? (
        <Banner tone="warning" message={`You did not check out on ${prettyDate(open.attendance_date)}. Check out now to close that day.`} />
      ) : null}

      {!today?.checked_in && !openFromEarlier ? (
        <Button testID="check-in" label="Check in (selfie + location)" onPress={checkIn} busy={busy === 'in'} />
      ) : null}
      {(today?.checked_in && !today?.checked_out) || openFromEarlier ? (
        <Button testID="check-out" label="Check out" onPress={checkOut} busy={busy === 'out'} />
      ) : null}
      <Muted>Your location is recorded when you check in and out. A selfie is taken at check-in.</Muted>
    </Screen>
  );
}
