import * as Location from 'expo-location';
import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useEffect, useState } from 'react';

import { PickerField, confirm, type Option } from '../../components/kit';
import { Banner, Button, Field, Loading, Muted, Screen } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { employeeOptions, projectOptions } from '../../lib/people';

type Site = Record<string, any> & { id: number };

/** Add or edit a site, with its location for check-ins. */
export default function SiteForm() {
  const { id } = useLocalSearchParams<{ id?: string }>();
  const router = useRouter();
  const editing = !!id;
  const [loading, setLoading] = useState(true);
  const [f, setF] = useState<Record<string, string>>({ status: 'active', geofence_radius: '200' });
  const [opts, setOpts] = useState<{ projects: Option[]; people: Option[] }>({ projects: [], people: [] });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    (async () => {
      const [projects, people] = await Promise.all([projectOptions().catch(() => []), employeeOptions().catch(() => [])]);
      setOpts({ projects, people });
      if (editing) {
        try {
          const s = await api.get<Site>(`/sites/${id}`);
          const next: Record<string, string> = {};
          for (const k of ['project_id', 'name', 'code', 'address', 'site_manager_id', 'site_supervisor_id', 'latitude', 'longitude', 'geofence_radius', 'status']) {
            if (s[k] !== null && s[k] !== undefined) next[k] = String(s[k]);
          }
          setF(next);
        } catch (e) {
          setError((e as ApiError).message);
        }
      }
      setLoading(false);
    })();
  }, [editing, id]);

  const set = (k: string) => (v: string) => setF((s) => ({ ...s, [k]: v }));
  const pick = (k: string) => (v: number | string | null) => setF((s) => ({ ...s, [k]: v === null ? '' : String(v) }));

  async function here() {
    const p = await Location.requestForegroundPermissionsAsync();
    if (p.status !== 'granted') return setError('Location permission is needed.');
    const fix = await Location.getCurrentPositionAsync({ accuracy: Location.Accuracy.High });
    setF((s) => ({ ...s, latitude: fix.coords.latitude.toFixed(7), longitude: fix.coords.longitude.toFixed(7) }));
  }

  async function save() {
    setBusy(true);
    setError(null);
    setErrors({});
    const body: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(f)) body[k] = v === '' ? null : v;
    for (const k of ['project_id', 'site_manager_id', 'site_supervisor_id', 'geofence_radius']) body[k] = f[k] ? Number(f[k]) : null;
    for (const k of ['latitude', 'longitude']) body[k] = f[k] ? Number(f[k]) : null;
    try {
      await (editing ? api.put(`/sites/${id}`, body) : api.post('/sites', body));
      router.back();
    } catch (e) {
      const err = e as ApiError;
      setError(err.message);
      setErrors(err.errors);
    } finally {
      setBusy(false);
    }
  }

  if (loading) return <Loading />;

  return (
    <Screen>
      <Stack.Screen options={{ title: editing ? 'Edit site' : 'New site' }} />
      {error ? <Banner message={error} /> : null}
      <PickerField label="Project" required value={f.project_id ? Number(f.project_id) : null} options={opts.projects} onChange={pick('project_id')} error={errors.project_id} />
      <Field label="Name" required value={f.name ?? ''} onChangeText={set('name')} error={errors.name} />
      <Field label="Code" required value={f.code ?? ''} onChangeText={set('code')} autoCapitalize="characters" error={errors.code} />
      <Field label="Address" value={f.address ?? ''} onChangeText={set('address')} multiline />
      <PickerField label="Site manager" allowClear value={f.site_manager_id ? Number(f.site_manager_id) : null} options={opts.people} onChange={pick('site_manager_id')} />
      <PickerField label="Site supervisor" allowClear value={f.site_supervisor_id ? Number(f.site_supervisor_id) : null} options={opts.people} onChange={pick('site_supervisor_id')} error={errors.site_supervisor_id} />
      <Button label="Use my current location" kind="outline" onPress={here} />
      <Field label="Latitude" value={f.latitude ?? ''} onChangeText={set('latitude')} keyboardType="decimal-pad" error={errors.latitude} />
      <Field label="Longitude" value={f.longitude ?? ''} onChangeText={set('longitude')} keyboardType="decimal-pad" error={errors.longitude} />
      <Field label="Check-in radius (metres)" value={f.geofence_radius ?? ''} onChangeText={set('geofence_radius')} keyboardType="number-pad" error={errors.geofence_radius} />
      <Muted>Stand on the site and tap “Use my current location” to set it.</Muted>
      <Screen.Spacer />
      <PickerField label="Status" value={f.status} options={[{ value: 'active', label: 'Active' }, { value: 'inactive', label: 'Inactive' }]} onChange={pick('status')} />
      <Button label={editing ? 'Save changes' : 'Create site'} onPress={save} busy={busy} />
      {editing ? (
        <Button
          label="Remove site"
          kind="danger"
          onPress={async () => {
            if (!(await confirm('Remove this site?', 'Only a site nobody was ever posted to can be removed. Otherwise set it to Inactive.', 'Remove'))) return;
            setError(null);
            try {
              await api.del(`/sites/${id}`);
              router.back();
            } catch (e) {
              setError((e as ApiError).message);
            }
          }}
        />
      ) : null}
    </Screen>
  );
}
