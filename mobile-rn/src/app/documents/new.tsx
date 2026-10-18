import * as DocumentPicker from 'expo-document-picker';
import * as ImagePicker from 'expo-image-picker';
import { useRouter } from 'expo-router';
import { useEffect, useState } from 'react';

import { DateField } from '../../components/DateField';
import { PickerField, type Option } from '../../components/kit';
import { Banner, Button, Field, Muted, Screen } from '../../components/ui';
import { api, ApiError, fileField } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { employeeOptions } from '../../lib/people';

type DocType = { id: number; name: string; requires_document_number: boolean; requires_issue_date: boolean; requires_expiry_date: boolean };

export default function NewDocument() {
  const router = useRouter();
  const { can, user } = useAuth();
  const forOthers = can('documents.verify') || can('employees.update');
  const [types, setTypes] = useState<DocType[]>([]);
  const [people, setPeople] = useState<Option[]>([]);
  const [employeeId, setEmployeeId] = useState<number | null>(user?.employee?.id ?? null);
  const [typeId, setTypeId] = useState<number | null>(null);
  const [f, setF] = useState({ document_number: '', issue_date: '', expiry_date: '', notes: '' });
  const [file, setFile] = useState<{ uri: string; name: string; type: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    api.get<DocType[] | { items: DocType[] }>('/document-types').then((r) => setTypes(Array.isArray(r) ? r : r.items), () => {});
    if (forOthers) employeeOptions().then(setPeople, () => {});
  }, [forOthers]);

  const t = types.find((x) => x.id === typeId);

  async function pick() {
    const r = await DocumentPicker.getDocumentAsync({ type: ['image/*', 'application/pdf'], copyToCacheDirectory: true });
    if (!r.canceled) setFile({ uri: r.assets[0].uri, name: r.assets[0].name, type: r.assets[0].mimeType ?? 'application/octet-stream' });
  }

  async function snap() {
    const permission = await ImagePicker.requestCameraPermissionsAsync();
    if (!permission.granted) return;
    const r = await ImagePicker.launchCameraAsync({ quality: 0.7 });
    if (!r.canceled && r.assets?.length) setFile({ uri: r.assets[0].uri, name: 'document-photo.jpg', type: 'image/jpeg' });
  }

  async function save() {
    setBusy(true);
    setError(null);
    setErrors({});
    const body = new FormData();
    if (employeeId) body.append('employee_id', String(employeeId));
    if (typeId) body.append('document_type_id', String(typeId));
    for (const [k, v] of Object.entries(f)) if (v) body.append(k, v);
    if (file) body.append('file', fileField(file.uri, file.name, file.type));
    try {
      const d = await api.post<{ id: number }>('/employee-documents', body);
      router.replace(`/documents/${d.id}`);
    } catch (e) {
      const err = e as ApiError;
      setError(err.message);
      setErrors(err.errors);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen>
      {error ? <Banner message={error} /> : null}
      {forOthers ? <PickerField label="Employee" required value={employeeId} options={people} onChange={(v) => setEmployeeId(v as number)} error={errors.employee_id} /> : null}
      <PickerField label="Document type" required value={typeId} options={types.map((x) => ({ value: x.id, label: x.name }))} onChange={(v) => setTypeId(v as number)} error={errors.document_type_id} />
      <Field label="Document number" required={t?.requires_document_number} value={f.document_number} onChangeText={(v) => setF({ ...f, document_number: v })} error={errors.document_number} />
      <DateField label="Issue date" required={t?.requires_issue_date} value={f.issue_date} onChange={(v) => setF({ ...f, issue_date: v })} />
      {errors.issue_date ? <Muted>{errors.issue_date}</Muted> : null}
      <DateField label="Expiry date" required={t?.requires_expiry_date} value={f.expiry_date} onChange={(v) => setF({ ...f, expiry_date: v })} />
      {errors.expiry_date ? <Muted>{errors.expiry_date}</Muted> : null}
      <Field label="Notes" value={f.notes} onChangeText={(v) => setF({ ...f, notes: v })} multiline />
      <Button label="Take a photo of it" kind="outline" onPress={snap} />
      <Button label={file ? `File: ${file.name}` : 'Choose file (photo or PDF)'} kind="outline" onPress={pick} />
      {errors.file ? <Banner message={errors.file} /> : null}
      <Screen.Spacer />
      <Button label="Upload" onPress={save} busy={busy} />
    </Screen>
  );
}
