import { Pressable, Text, TextInput, View } from 'react-native';

import { colors } from './ui';

export type Column = { key: string; label: string; numeric?: boolean; flex?: number };

/** A small editable table: one line per item, add / remove lines. */
export function RowsEditor({
  title,
  columns,
  rows,
  onChange,
  addLabel = '+ Add line',
}: {
  title: string;
  columns: Column[];
  rows: Record<string, string>[];
  onChange: (rows: Record<string, string>[]) => void;
  addLabel?: string;
}) {
  const set = (i: number, k: string, v: string) => onChange(rows.map((r, j) => (j === i ? { ...r, [k]: v } : r)));
  return (
    <View style={{ marginBottom: 14 }}>
      <Text style={{ fontWeight: '600', color: colors.text, marginBottom: 6, fontSize: 15 }}>{title}</Text>
      {rows.map((r, i) => (
        <View key={i} style={{ flexDirection: 'row', gap: 6, marginBottom: 6, alignItems: 'center' }}>
          {columns.map((c) => (
            <TextInput
              key={c.key}
              value={r[c.key] ?? ''}
              onChangeText={(t) => set(i, c.key, t)}
              placeholder={c.label}
              placeholderTextColor="#9CA3AF"
              keyboardType={c.numeric ? 'decimal-pad' : 'default'}
              style={{ flex: c.flex ?? 1, backgroundColor: '#fff', borderWidth: 1, borderColor: colors.border, borderRadius: 10, paddingHorizontal: 10, paddingVertical: 9, fontSize: 15, color: colors.text }}
            />
          ))}
          <Pressable onPress={() => onChange(rows.filter((_, j) => j !== i))}>
            <Text style={{ color: colors.danger, fontSize: 18 }}>✕</Text>
          </Pressable>
        </View>
      ))}
      <Pressable onPress={() => onChange([...rows, {}])}>
        <Text style={{ color: colors.primary, fontWeight: '700' }}>{addLabel}</Text>
      </Pressable>
    </View>
  );
}
