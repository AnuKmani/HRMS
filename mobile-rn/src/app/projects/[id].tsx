import * as DocumentPicker from 'expo-document-picker';
import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';

import { DateField } from '../../components/DateField';
import { FormModal, KeyValues, MultiPickerField, PickerField, SectionHeader, confirm, type Option } from '../../components/kit';
import { Banner, Button, Card, Chip, Field, Loading, Muted, ProgressBar, Row, Screen, colors } from '../../components/ui';
import { api } from '../../lib/api';
import { useAuth } from '../../lib/auth';
import { openRemoteFile } from '../../lib/files';
import { useAction, useLoad } from '../../lib/hooks';
import { uploadField } from '../../lib/photos';
import {
  DOC_CATEGORIES,
  EVENT_TYPES,
  getProject,
  managerCandidates,
  money,
  pct,
  statusName,
  teamCandidates,
  type Milestone,
  type Stage,
  type Subcontractor,
} from '../../lib/projects';

type Modal =
  | { kind: 'correct' | 'bill'; stage: Stage }
  | { kind: 'sub'; sub: Subcontractor | null }
  | { kind: 'remove-sub'; sub: Subcontractor }
  | { kind: 'team' }
  | { kind: 'milestone' }
  | { kind: 'doc' }
  | { kind: 'event' }
  | { kind: 'reassign' }
  | { kind: 'history' }
  | null;

export default function ProjectDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const router = useRouter();
  const { can } = useAuth();
  const { data: p, error: loadError, loading, reload } = useLoad(() => getProject(id), [id]);
  const { busy, error, notice, run, setError } = useAction();
  const [modal, setModal] = useState<Modal>(null);
  const [form, setForm] = useState<Record<string, string>>({});
  const [people, setPeople] = useState<Option[]>([]);
  const [team, setTeam] = useState<Record<string, number[]>>({});
  const [history, setHistory] = useState<{ stage?: string; stage_percent: number; project_percent: number; source: string; by?: string; at?: string; note?: string }[]>([]);

  if (loading) return <Loading />;
  if (!p) return <Screen>{loadError ? <Banner message={loadError} /> : null}</Screen>;

  const canEdit = !!p.can_edit;
  const canCoord = canEdit || !!p.can_coordinate;
  const canBill = canEdit || can('projects.billing.manage');
  const money_ = !!p.can_see_money;
  const active = (p.subcontractors ?? []).filter((s) => s.status !== 'removed');
  const removed = (p.subcontractors ?? []).filter((s) => s.status === 'removed');
  const v = (k: string) => form[k] ?? '';
  const setV = (k: string) => (val: string) => setForm((f) => ({ ...f, [k]: val }));
  const num = (k: string) => (v(k).trim() === '' ? null : Number(v(k)));

  async function act(key: string, fn: () => Promise<unknown>, done: string) {
    if (await run(key, fn, done)) {
      setModal(null);
      setForm({});
      await reload();
    }
  }

  function open(m: Modal, initial: Record<string, string> = {}) {
    setError(null);
    setForm(initial);
    setModal(m);
  }

  async function openTeam() {
    const list = await teamCandidates().catch(() => []);
    setPeople(list.map((x) => ({ value: x.id, label: x.name, hint: x.role ?? null })));
    const byRole: Record<string, number[]> = { site_engineer: [], site_supervisor: [], coordinator: [] };
    for (const m of p!.team ?? []) (byRole[m.role] ??= []).push(m.employee_id);
    setTeam(byRole);
    open({ kind: 'team' });
  }

  async function openReassign() {
    const list = await managerCandidates().catch(() => []);
    setPeople(list.map((x) => ({ value: x.id, label: x.name, hint: x.role ?? null })));
    open({ kind: 'reassign' }, { pm: String(p!.project_manager_id ?? '') });
  }

  async function openHistory() {
    setHistory(await api.get(`/projects/${id}/progress-history`).then((d) => d as typeof history).catch(() => []));
    setModal({ kind: 'history' });
  }

  async function uploadDoc() {
    const picked = await DocumentPicker.getDocumentAsync({ copyToCacheDirectory: true });
    if (picked.canceled || !picked.assets?.length) return;
    const file = picked.assets[0];
    setForm((f) => ({ ...f, fileUri: file.uri, fileName: file.name, fileType: file.mimeType ?? 'application/octet-stream', title: f.title || file.name.replace(/\.[^.]+$/, '') }));
  }

  return (
    <Screen onRefresh={reload}>
      <Stack.Screen options={{ title: p.name }} />
      {loadError ? <Banner message={loadError} /> : null}
      {error && !modal ? <Banner message={error} /> : null}
      {notice ? <Banner message={notice} tone="success" /> : null}

      <Card>
        <KeyValues
          rows={[
            ['Code', p.code],
            ['Status', statusName(p.status)],
            ['Client', p.client],
            ['Main contractor', p.main_contractor],
            ['Location', p.location],
            ['Project manager', p.project_manager?.full_name],
            ['Added by', p.created_by_name],
            ['Dates', p.start_date || p.end_date ? `${p.start_date ?? '?'} → ${p.end_date ?? '?'}` : null],
          ]}
        />
        {!canEdit && can('projects.manage') ? <Muted>Only {p.created_by_name ?? 'the Project Manager who added it'} can change this project.</Muted> : null}
        <View style={{ flexDirection: 'row', gap: 8, marginTop: 12, flexWrap: 'wrap' }}>
          {canEdit ? (
            <Pressable onPress={() => router.push(`/projects/form?id=${p.id}`)}>
              <Text style={{ color: colors.primary, fontWeight: '700' }}>✏️ Edit</Text>
            </Pressable>
          ) : null}
          {p.can_reassign ? (
            <Pressable testID="reassign-pm" onPress={openReassign}>
              <Text style={{ color: colors.primary, fontWeight: '700' }}>  🔁 Change Project Manager</Text>
            </Pressable>
          ) : null}
          {canEdit ? (
            <Pressable
              onPress={async () => {
                if (await confirm('Remove project?', `${p.name} will be removed from the list. Its sites must be moved first.`, 'Remove')) {
                  if (await run('delete', () => api.del(`/projects/${p.id}`))) router.replace('/projects');
                }
              }}
            >
              <Text style={{ color: colors.danger, fontWeight: '700' }}>  🗑 Remove</Text>
            </Pressable>
          ) : null}
        </View>
      </Card>

      {/* Progress and systems */}
      <Card>
        <SectionHeader title="Progress" action="PDF" onAction={() => run('pdf', () => openRemoteFile(`/project-progress/${p.id}/pdf`, `progress-${p.code}.pdf`))} />
        <View style={{ flexDirection: 'row', alignItems: 'center' }}>
          <Text style={{ fontSize: 30, fontWeight: '800', color: colors.text }}>{pct(p.progress_percent)}</Text>
          <Text style={{ marginLeft: 8, color: colors.muted, flex: 1 }}>complete</Text>
          {p.behind_schedule ? <Chip label="Behind schedule" tone="danger" /> : null}
        </View>
        <ProgressBar value={Number(p.progress_percent)} />
        {p.expected_percent !== null && p.expected_percent !== undefined ? <Muted>Expected by today: {pct(p.expected_percent)}</Muted> : null}
        <View style={{ marginTop: 10 }}>
          {(p.stages ?? []).map((s) => (
            <Pressable
              key={s.id}
              testID={`stage-${s.id}`}
              disabled={!canEdit && !canBill}
              onPress={() => open({ kind: canEdit ? 'correct' : 'bill', stage: s }, { percent: String(s.percent_complete), billed: String(s.billed_amount ?? 0) })}
              style={{ paddingVertical: 8, borderBottomWidth: 0.5, borderBottomColor: colors.border }}
            >
              <View style={{ flexDirection: 'row' }}>
                <Text style={{ flex: 1, fontWeight: '600', color: colors.text }}>
                  {s.name} · weight {pct(s.weight)}
                </Text>
                <Text style={{ color: colors.text }}>{pct(s.percent_complete)}{canEdit || canBill ? ' ✏️' : ''}</Text>
              </View>
              <ProgressBar value={Number(s.percent_complete)} />
              {money_ && s.contract_value ? (
                <Muted>
                  {money(s.contract_value)} · billed {money(s.billed_amount)} ({pct(s.contract_value ? ((s.billed_amount ?? 0) / s.contract_value) * 100 : null)})
                </Muted>
              ) : null}
            </Pressable>
          ))}
        </View>
        <View style={{ flexDirection: 'row', gap: 16, marginTop: 10 }}>
          <Pressable onPress={openHistory}>
            <Text style={{ color: colors.primary, fontWeight: '700' }}>History</Text>
          </Pressable>
          {canEdit ? (
            <Pressable testID="edit-systems" onPress={() => router.push(`/projects/stages?id=${p.id}`)}>
              <Text style={{ color: colors.primary, fontWeight: '700' }}>Edit systems / stages</Text>
            </Pressable>
          ) : null}
        </View>
      </Card>

      {/* Subcontractors */}
      <Card>
        <SectionHeader title="Subcontractors" action={canEdit ? 'Add' : undefined} onAction={() => open({ kind: 'sub', sub: null })} />
        <Muted>Main contractor: {p.main_contractor ?? '—'}</Muted>
        {active.length === 0 ? <Muted>No subcontractors.</Muted> : null}
        {active.map((c) => (
          <Row
            key={c.id}
            title={c.name}
            subtitle={[
              c.trade,
              [c.contact_name, c.contact_phone].filter(Boolean).join(' '),
              c.project_stage_id ? `System: ${(p.stages ?? []).find((s) => s.id === c.project_stage_id)?.name ?? '—'}` : null,
              money_ && c.contract_value ? `${money(c.contract_value)} · billed ${money(c.billed_amount)} (${pct(c.contract_value ? ((c.billed_amount ?? 0) / c.contract_value) * 100 : null)})` : null,
            ]
              .filter(Boolean)
              .join('\n')}
            onPress={
              canCoord
                ? () =>
                    open({ kind: 'sub', sub: c }, {
                      name: c.name,
                      trade: c.trade ?? '',
                      contact_name: c.contact_name ?? '',
                      contact_phone: c.contact_phone ?? '',
                      contract_value: c.contract_value ? String(c.contract_value) : '',
                      billed_amount: c.billed_amount ? String(c.billed_amount) : '',
                      system: c.project_stage_id ? String(c.project_stage_id) : '',
                      start_date: c.start_date ?? '',
                      end_date: c.end_date ?? '',
                    })
                : undefined
            }
            right={
              canEdit ? (
                <Pressable onPress={() => open({ kind: 'remove-sub', sub: c }, { removed_on: new Date().toISOString().slice(0, 10) })}>
                  <Text style={{ color: colors.danger }}>Remove</Text>
                </Pressable>
              ) : undefined
            }
          />
        ))}
        {removed.length ? (
          <>
            <Text style={{ marginTop: 10, fontWeight: '700', color: colors.muted }}>Removed ({removed.length})</Text>
            {removed.map((c) => (
              <Row
                key={c.id}
                title={c.name}
                subtitle={`Removed ${c.removed_on ?? ''}: ${c.removed_reason ?? ''}`}
                right={
                  canEdit ? (
                    <Pressable onPress={() => act('restore', () => api.post(`/projects/${p.id}/subcontractors/${c.id}/restore`), `${c.name} added back.`)}>
                      <Text style={{ color: colors.primary }}>Add back</Text>
                    </Pressable>
                  ) : undefined
                }
              />
            ))}
          </>
        ) : null}
      </Card>

      {/* Team */}
      <Card>
        <SectionHeader title="Project team" action={canEdit ? 'Edit' : undefined} onAction={openTeam} />
        {(p.team ?? []).length === 0 ? <Muted>Nobody on the team yet.</Muted> : null}
        {(p.team ?? []).map((m) => (
          <Row key={m.employee_id} title={m.name} subtitle={m.role === 'coordinator' ? 'Project coordinator' : m.role === 'site_supervisor' ? 'Site supervisor' : 'Site engineer'} />
        ))}
      </Card>

      {/* Milestones */}
      <Card>
        <SectionHeader title="Payment milestones" action={canEdit ? 'Add' : undefined} onAction={() => open({ kind: 'milestone' })} />
        {(p.payment_milestones ?? []).length === 0 ? <Muted>None yet. Sales is told when progress reaches a milestone.</Muted> : null}
        {(p.payment_milestones ?? []).map((m: Milestone) => (
          <Row
            key={m.id}
            title={m.name}
            subtitle={[`at ${pct(m.trigger_percent)}`, m.amount_percent ? `${pct(m.amount_percent)} of contract` : null, m.amount ? money(m.amount) : null].filter(Boolean).join(' · ')}
            right={
              <View style={{ alignItems: 'flex-end', gap: 4 }}>
                <Chip label={m.status} tone={m.status === 'paid' ? 'success' : m.status === 'reached' ? 'warning' : m.status === 'requested' ? 'info' : 'neutral'} />
                {can('projects.billing.manage') && (m.status === 'reached' || m.status === 'requested') ? (
                  <Pressable onPress={() => act('ms', () => api.post(`/projects/${p.id}/milestones/${m.id}/status`, { status: m.status === 'reached' ? 'requested' : 'paid' }), 'Milestone updated.')}>
                    <Text style={{ color: colors.primary, fontWeight: '700' }}>{m.status === 'reached' ? 'Mark requested' : 'Mark paid'}</Text>
                  </Pressable>
                ) : null}
                {canEdit && (m.status === 'pending' || m.status === 'reached') ? (
                  <Pressable onPress={() => act('ms-del', () => api.del(`/projects/${p.id}/milestones/${m.id}`), 'Milestone removed.')}>
                    <Text style={{ color: colors.danger }}>Remove</Text>
                  </Pressable>
                ) : null}
              </View>
            }
          />
        ))}
      </Card>

      {/* Documents */}
      <Card>
        <SectionHeader title="Documents" action={canCoord ? 'Upload' : undefined} onAction={() => open({ kind: 'doc' }, { category: 'drawing' })} />
        {(p.documents ?? []).length === 0 ? <Muted>No drawings, contracts or permits yet.</Muted> : null}
        {(p.documents ?? []).map((d) => (
          <Row
            key={d.id}
            title={d.title}
            subtitle={[DOC_CATEGORIES.find(([c]) => c === d.category)?.[1], d.original_name].filter(Boolean).join(' · ')}
            onPress={() => run('doc', () => openRemoteFile(`/projects/${p.id}/documents/${d.id}`, d.original_name ?? `document-${d.id}`, 'application/octet-stream'))}
            right={
              canCoord ? (
                <Pressable
                  onPress={async () => {
                    if (await confirm('Remove document?', d.title, 'Remove')) await act('doc-del', () => api.del(`/projects/${p.id}/documents/${d.id}`), 'Document removed.');
                  }}
                >
                  <Text style={{ color: colors.danger }}>Remove</Text>
                </Pressable>
              ) : undefined
            }
          />
        ))}
      </Card>

      {/* Inspections */}
      <Card>
        <SectionHeader title="Inspections & key dates" action={canCoord ? 'Add' : undefined} onAction={() => open({ kind: 'event' }, { type: 'dcd_inspection' })} />
        {(p.events ?? []).length === 0 ? <Muted>No dates booked yet.</Muted> : null}
        {(p.events ?? []).map((e) => (
          <Row
            key={e.id}
            title={e.label}
            subtitle={[e.event_date, e.notes].filter(Boolean).join(' · ')}
            right={
              canCoord ? (
                <Pressable onPress={() => act('ev-del', () => api.del(`/projects/${p.id}/events/${e.id}`), 'Date removed.')}>
                  <Text style={{ color: colors.danger }}>Remove</Text>
                </Pressable>
              ) : undefined
            }
          />
        ))}
      </Card>

      {/* ---- Modals ---- */}
      <FormModal
        visible={modal?.kind === 'correct' || modal?.kind === 'bill'}
        title={modal && 'stage' in modal ? modal.stage.name : ''}
        busy={!!busy}
        onClose={() => setModal(null)}
        onSave={() => {
          if (!modal || !('stage' in modal)) return;
          const s = modal.stage;
          void act(
            'stage',
            async () => {
              if (canEdit && v('percent') !== String(s.percent_complete)) {
                await api.post(`/projects/${p.id}/stages/${s.id}/correct`, { percent: Number(v('percent')), note: v('note') || null });
              }
              if (canBill && money_ && v('billed') !== String(s.billed_amount ?? 0)) {
                await api.put(`/projects/${p.id}/stages/${s.id}/billing`, { billed_amount: Number(v('billed') || 0) });
              }
            },
            'Saved.',
          );
        }}
      >
        {error ? <Banner message={error} /> : null}
        {canEdit ? (
          <>
            <Field label="% complete (0–100)" value={v('percent')} onChangeText={setV('percent')} keyboardType="decimal-pad" />
            <Field label="Note (optional)" value={v('note')} onChangeText={setV('note')} />
          </>
        ) : null}
        {canBill && money_ ? (
          <>
            {modal && 'stage' in modal && modal.stage.contract_value ? <Muted>System value: {money(modal.stage.contract_value)}</Muted> : null}
            <Field label="Total billed to the client so far (AED)" value={v('billed')} onChangeText={setV('billed')} keyboardType="decimal-pad" />
          </>
        ) : null}
      </FormModal>

      <FormModal
        visible={modal?.kind === 'sub'}
        title={modal?.kind === 'sub' && modal.sub ? 'Edit subcontractor' : 'Add subcontractor'}
        busy={!!busy}
        onClose={() => setModal(null)}
        onSave={() => {
          if (modal?.kind !== 'sub') return;
          const body = {
            name: v('name').trim(),
            trade: v('trade') || null,
            contact_name: v('contact_name') || null,
            contact_phone: v('contact_phone') || null,
            ...(canEdit
              ? {
                  contract_value: num('contract_value'),
                  billed_amount: num('billed_amount') ?? 0,
                  project_stage_id: v('system') ? Number(v('system')) : null,
                  start_date: v('start_date') || null,
                  end_date: v('end_date') || null,
                }
              : {}),
          };
          void act(
            'sub',
            () => (modal.sub ? api.put(`/projects/${p.id}/subcontractors/${modal.sub.id}`, body) : api.post(`/projects/${p.id}/subcontractors`, body)),
            modal.sub ? 'Subcontractor updated.' : 'Subcontractor added.',
          );
        }}
      >
        {error ? <Banner message={error} /> : null}
        <Field label="Company name" required value={v('name')} onChangeText={setV('name')} />
        <Field label="Trade" value={v('trade')} onChangeText={setV('trade')} placeholder="HVAC, electrical…" />
        <Field label="Contact person" value={v('contact_name')} onChangeText={setV('contact_name')} />
        <Field label="Phone" value={v('contact_phone')} onChangeText={setV('contact_phone')} keyboardType="phone-pad" />
        {canEdit ? (
          <>
            <PickerField
              label="System they install"
              value={v('system') ? Number(v('system')) : null}
              allowClear
              options={(p.stages ?? []).map((s) => ({ value: s.id, label: s.name }))}
              onChange={(x) => setV('system')(x === null ? '' : String(x))}
            />
            <Field label="Contract value (AED)" value={v('contract_value')} onChangeText={setV('contract_value')} keyboardType="decimal-pad" />
            <Field label="Billed so far (AED)" value={v('billed_amount')} onChangeText={setV('billed_amount')} keyboardType="decimal-pad" />
            <DateField label="Start date" value={v('start_date')} onChange={setV('start_date')} />
            <DateField label="End date" value={v('end_date')} onChange={setV('end_date')} />
          </>
        ) : null}
      </FormModal>

      <FormModal
        visible={modal?.kind === 'remove-sub'}
        title={modal?.kind === 'remove-sub' ? `Remove ${modal.sub.name}?` : ''}
        saveLabel="Remove"
        busy={!!busy}
        onClose={() => setModal(null)}
        onSave={() => {
          if (modal?.kind !== 'remove-sub') return;
          if (!v('reason').trim()) return setError('Give a reason for removing them.');
          void act('remove', () => api.post(`/projects/${p.id}/subcontractors/${modal.sub.id}/remove`, { reason: v('reason'), removed_on: v('removed_on') || null }), 'Subcontractor removed.');
        }}
      >
        {error ? <Banner message={error} /> : null}
        <Muted>They stay in the project history and can be added back.</Muted>
        <Screen.Spacer />
        <Field label="Reason" required value={v('reason')} onChangeText={setV('reason')} />
        <DateField label="Removed on" value={v('removed_on')} onChange={setV('removed_on')} />
      </FormModal>

      <FormModal
        visible={modal?.kind === 'team'}
        title="Project team"
        busy={!!busy}
        onClose={() => setModal(null)}
        onSave={() =>
          act(
            'team',
            () =>
              api.put(`/projects/${p.id}/team`, {
                site_engineer_ids: team.site_engineer ?? [],
                site_supervisor_ids: team.site_supervisor ?? [],
                coordinator_ids: team.coordinator ?? [],
              }),
            'Team updated.',
          )
        }
      >
        {error ? <Banner message={error} /> : null}
        {(
          [
            ['site_engineer', 'Site engineers'],
            ['site_supervisor', 'Site supervisors'],
            ['coordinator', 'Project coordinators'],
          ] as const
        ).map(([role, label]) => (
          <MultiPickerField
            key={role}
            label={label}
            values={team[role] ?? []}
            options={people}
            onChange={(vals) => setTeam((t) => ({ ...t, [role]: vals as number[] }))}
          />
        ))}
      </FormModal>

      <FormModal
        visible={modal?.kind === 'milestone'}
        title="Add payment milestone"
        busy={!!busy}
        onClose={() => setModal(null)}
        onSave={() =>
          act(
            'milestone',
            () => api.post(`/projects/${p.id}/milestones`, { name: v('name'), trigger_percent: num('at'), amount_percent: num('share'), amount: num('amount') }),
            'Milestone added.',
          )
        }
      >
        {error ? <Banner message={error} /> : null}
        <Field label="Name" required value={v('name')} onChangeText={setV('name')} placeholder="Structure complete" />
        <Field label="When progress reaches (%)" required value={v('at')} onChangeText={setV('at')} keyboardType="decimal-pad" />
        <Field label="Payment, % of contract" value={v('share')} onChangeText={setV('share')} keyboardType="decimal-pad" />
        <Field label="Amount (AED)" value={v('amount')} onChangeText={setV('amount')} keyboardType="decimal-pad" />
      </FormModal>

      <FormModal
        visible={modal?.kind === 'doc'}
        title="Upload document"
        saveLabel="Upload"
        busy={!!busy}
        onClose={() => setModal(null)}
        onSave={() => {
          if (!v('fileUri')) return setError('Choose a file first.');
          const body = new FormData();
          body.append('title', v('title'));
          body.append('category', v('category'));
          void act('upload', async () => {
            body.append('file', await uploadField(v('fileUri'), v('fileName'), v('fileType')));
            return api.post(`/projects/${p.id}/documents`, body);
          }, 'Document uploaded.');
        }}
      >
        {error ? <Banner message={error} /> : null}
        <Button label={v('fileName') ? `File: ${v('fileName')}` : 'Choose file'} kind="outline" onPress={uploadDoc} />
        <Field label="Title" required value={v('title')} onChangeText={setV('title')} />
        <PickerField label="Type" value={v('category')} options={DOC_CATEGORIES.map(([value, label]) => ({ value, label }))} onChange={(x) => setV('category')(String(x))} />
      </FormModal>

      <FormModal
        visible={modal?.kind === 'event'}
        title="Add inspection / date"
        busy={!!busy}
        onClose={() => setModal(null)}
        onSave={() => act('event', () => api.post(`/projects/${p.id}/events`, { type: v('type'), event_date: v('date'), notes: v('notes') || null }), 'Date added.')}
      >
        {error ? <Banner message={error} /> : null}
        <PickerField label="What" value={v('type')} options={EVENT_TYPES.map(([value, label]) => ({ value, label }))} onChange={(x) => setV('type')(String(x))} />
        <DateField label="Date" required value={v('date')} onChange={setV('date')} />
        <Field label="Notes" value={v('notes')} onChangeText={setV('notes')} />
      </FormModal>

      <FormModal
        visible={modal?.kind === 'reassign'}
        title="Change Project Manager"
        busy={!!busy}
        onClose={() => setModal(null)}
        onSave={() => act('reassign', () => api.put(`/projects/${p.id}/manager`, { project_manager_id: Number(v('pm')) }), 'Project Manager changed.')}
      >
        {error ? <Banner message={error} /> : null}
        <PickerField label="New Project Manager" value={v('pm') ? Number(v('pm')) : null} options={people} onChange={(x) => setV('pm')(String(x))} />
      </FormModal>

      <FormModal visible={modal?.kind === 'history'} title="Progress history" saveLabel="Close" onClose={() => setModal(null)} onSave={() => setModal(null)}>
        {history.length === 0 ? <Muted>No updates yet.</Muted> : null}
        {history.map((h, i) => (
          <Row
            key={i}
            title={`${h.stage ?? 'Stage'} → ${pct(h.stage_percent)} (project ${pct(h.project_percent)})`}
            subtitle={[h.source === 'manual' ? 'Corrected by PM' : 'Daily report', h.by, h.at?.slice(0, 10), h.note].filter(Boolean).join(' · ')}
          />
        ))}
      </FormModal>
    </Screen>
  );
}
