import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useEffect, useState } from 'react';
import { Alert } from 'react-native';

import { DateField } from '../../components/DateField';
import { PickerField, type Option } from '../../components/kit';
import { Banner, Button, Field, Loading, Muted, Screen } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { employeeOptions } from '../../lib/people';
import { PROJECT_STATUSES, getProject, type Project } from '../../lib/projects';

/** Add a project (code is automatic) or edit one. */
export default function ProjectFormScreen() {
  const { id } = useLocalSearchParams<{ id?: string }>();
  const router = useRouter();
  const editing = !!id;
  const [loading, setLoading] = useState(editing);
  const [f, setF] = useState({
    name: '',
    code: '',
    client: '',
    main_contractor: '',
    location: '',
    description: '',
    start_date: '',
    end_date: '',
    status: 'active',
    project_manager_id: null as number | null,
  });
  const [people, setPeople] = useState<Option[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    employeeOptions().then(setPeople).catch(() => setPeople([]));
    if (!editing) return;
    getProject(id!)
      .then((p: Project) =>
        setF({
          name: p.name,
          code: p.code,
          client: p.client ?? '',
          main_contractor: p.main_contractor ?? '',
          location: p.location ?? '',
          description: p.description ?? '',
          start_date: p.start_date ?? '',
          end_date: p.end_date ?? '',
          status: p.status,
          project_manager_id: p.project_manager_id ?? null,
        }),
      )
      .catch((e) => setError((e as ApiError).message))
      .finally(() => setLoading(false));
  }, [editing, id]);

  const set = (k: keyof typeof f) => (v: string) => setF((s) => ({ ...s, [k]: v }));

  async function save() {
    setError(null);
    setErrors({});
    if (!f.name.trim()) return setErrors({ name: 'Enter the project name.' });
    setBusy(true);
    const blank = (v: string) => (v.trim() ? v.trim() : null);
    const body = {
      name: f.name.trim(),
      code: blank(f.code),
      client: blank(f.client),
      main_contractor: blank(f.main_contractor),
      location: blank(f.location),
      description: blank(f.description),
      start_date: blank(f.start_date),
      end_date: blank(f.end_date),
      status: f.status,
      ...(editing ? { project_manager_id: f.project_manager_id } : {}),
    };
    try {
      const saved = editing ? await api.put<Project>(`/projects/${id}`, body) : await api.post<Project>('/projects', body);
      router.replace(`/projects/${saved.id}`);
    } catch (e) {
      const err = e as ApiError;
      setError(err.message);
      setErrors(err.errors);
      if (err.errors.name?.includes('already added')) Alert.alert('Project already added', err.errors.name);
    } finally {
      setBusy(false);
    }
  }

  if (loading) return <Loading />;

  return (
    <Screen>
      <Stack.Screen options={{ title: editing ? 'Edit project' : 'New project' }} />
      {error ? <Banner message={error} /> : null}
      <Field testID="project-name" label="Name" required value={f.name} onChangeText={set('name')} error={errors.name} />
      <Field label="Code" value={f.code} onChangeText={set('code')} error={errors.code} placeholder={editing ? '' : 'Automatic (PRJ-0001…)'} />
      <Field label="Client" value={f.client} onChangeText={set('client')} error={errors.client} />
      <Field label="Main contractor" value={f.main_contractor} onChangeText={set('main_contractor')} error={errors.main_contractor} />
      <Field label="Location" value={f.location} onChangeText={set('location')} error={errors.location} />
      <Field label="Description" value={f.description} onChangeText={set('description')} multiline />
      {editing ? (
        <PickerField label="Project manager" value={f.project_manager_id} options={people} onChange={(v) => setF((s) => ({ ...s, project_manager_id: v as number }))} error={errors.project_manager_id} />
      ) : null}
      <DateField label="Start date" value={f.start_date} onChange={set('start_date')} error={errors.start_date} />
      <DateField label="End date" value={f.end_date} onChange={set('end_date')} error={errors.end_date} />
      <PickerField label="Status" value={f.status} options={PROJECT_STATUSES.map(([value, label]) => ({ value, label }))} onChange={(v) => setF((s) => ({ ...s, status: String(v) }))} />
      {!editing ? <Muted>Systems / stages start from the standard template, and you add subcontractors and the team on the project page.</Muted> : null}
      <Screen.Spacer />
      <Button testID="save-project" label={editing ? 'Save changes' : 'Create project'} onPress={save} busy={busy} />
    </Screen>
  );
}
