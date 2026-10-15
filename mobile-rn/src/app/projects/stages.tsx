import { Stack, useLocalSearchParams, useRouter } from 'expo-router';
import { useEffect, useState } from 'react';
import { Pressable, Text, TextInput, View } from 'react-native';

import { Banner, Button, Loading, Muted, Screen, colors } from '../../components/ui';
import { api, ApiError } from '../../lib/api';
import { getProject, money, pct } from '../../lib/projects';

type RowState = { id?: number; name: string; value: string; weight: string };

const TEMPLATE: [string, number][] = [
  ['Mobilisation', 5],
  ['Foundation', 15],
  ['Structure', 35],
  ['MEP', 20],
  ['Finishing', 20],
  ['Testing & handover', 5],
];

/** Systems (HVAC, Electrical…) with values — or stages with weights. */
export default function SystemsEditor() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const router = useRouter();
  const [rows, setRows] = useState<RowState[] | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    getProject(id)
      .then((p) =>
        setRows(
          p.stages?.length
            ? p.stages.map((s) => ({ id: s.id, name: s.name, value: s.contract_value ? String(s.contract_value) : '', weight: String(s.weight) }))
            : TEMPLATE.map(([name, w]) => ({ name, value: '', weight: String(w) })),
        ),
      )
      .catch((e) => setError((e as ApiError).message));
  }, [id]);

  if (!rows) return error ? <Screen><Banner message={error} /></Screen> : <Loading />;

  const byValue = rows.every((r) => Number(r.value) > 0);
  const valueTotal = rows.reduce((s, r) => s + (Number(r.value) || 0), 0);
  const weightTotal = rows.reduce((s, r) => s + (Number(r.weight) || 0), 0);
  const valid = rows.every((r) => r.name.trim()) && (byValue || Math.abs(weightTotal - 100) < 0.01);
  const update = (i: number, k: keyof RowState, v: string) => setRows((rs) => rs!.map((r, j) => (j === i ? { ...r, [k]: v } : r)));

  async function save() {
    setBusy(true);
    setError(null);
    try {
      await api.put(`/projects/${id}/stages`, {
        stages: rows!.map((r) => ({
          ...(r.id ? { id: r.id } : {}),
          name: r.name.trim(),
          contract_value: r.value ? Number(r.value) : null,
          ...(byValue ? {} : { weight: Number(r.weight) || 0 }),
        })),
      });
      router.back();
    } catch (e) {
      setError((e as ApiError).firstMessage);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen>
      <Stack.Screen options={{ title: 'Systems / stages' }} />
      <Muted>Give each system its contract value and the weights are worked out for you. Without values, weights must add up to 100.</Muted>
      <Screen.Spacer />
      {error ? <Banner message={error} /> : null}
      {rows.map((r, i) => (
        <View key={i} style={{ flexDirection: 'row', gap: 6, marginBottom: 8, alignItems: 'center' }}>
          <TextInput value={r.name} onChangeText={(t) => update(i, 'name', t)} placeholder="System / stage" style={[box, { flex: 3 }]} />
          <TextInput value={r.value} onChangeText={(t) => update(i, 'value', t)} placeholder="Value" keyboardType="decimal-pad" style={[box, { flex: 3 }]} />
          {byValue ? (
            <Text style={{ flex: 2, textAlign: 'center', color: colors.text }}>{pct(valueTotal ? (Number(r.value) / valueTotal) * 100 : 0)}</Text>
          ) : (
            <TextInput value={r.weight} onChangeText={(t) => update(i, 'weight', t)} placeholder="%" keyboardType="decimal-pad" style={[box, { flex: 2 }]} />
          )}
          <Pressable disabled={rows.length === 1} onPress={() => setRows((rs) => rs!.filter((_, j) => j !== i))}>
            <Text style={{ fontSize: 18, color: colors.danger }}>✕</Text>
          </Pressable>
        </View>
      ))}
      <View style={{ flexDirection: 'row', alignItems: 'center', marginVertical: 8 }}>
        <Pressable onPress={() => setRows((rs) => [...rs!, { name: '', value: '', weight: '0' }])} style={{ flex: 1 }}>
          <Text style={{ color: colors.primary, fontWeight: '700' }}>+ Add system / stage</Text>
        </Pressable>
        <Text style={{ fontWeight: '700', color: valid ? colors.success : colors.danger }}>
          {byValue ? `Project value ${money(valueTotal)}` : `Total ${pct(weightTotal)}`}
        </Text>
      </View>
      <Button label="Save" onPress={save} busy={busy} disabled={!valid} />
    </Screen>
  );
}

const box = { backgroundColor: '#fff', borderWidth: 1, borderColor: colors.border, borderRadius: 10, paddingHorizontal: 10, paddingVertical: 10, fontSize: 15, color: colors.text } as const;
