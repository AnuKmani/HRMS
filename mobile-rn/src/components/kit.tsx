import { useMemo, useState, type ReactNode } from 'react';
import { Alert, FlatList, Modal, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { Button, colors } from './ui';

export type Option = { value: number | string; label: string; hint?: string | null };

/** A field that opens a searchable list to pick one value. */
export function PickerField({
  label,
  value,
  options,
  onChange,
  placeholder = 'Choose…',
  error,
  required,
  testID,
  allowClear,
}: {
  label: string;
  value: number | string | null | undefined;
  options: Option[];
  onChange: (value: number | string | null) => void;
  placeholder?: string;
  error?: string;
  required?: boolean;
  testID?: string;
  allowClear?: boolean;
}) {
  const [open, setOpen] = useState(false);
  const chosen = options.find((o) => o.value === value);
  return (
    <View style={{ marginBottom: 14 }}>
      <Text style={styles.label}>
        {label}
        {required ? ' *' : ''}
      </Text>
      <Pressable testID={testID} onPress={() => setOpen(true)} style={[styles.box, error ? { borderColor: colors.danger } : null]}>
        <Text style={{ fontSize: 16, color: chosen ? colors.text : '#9CA3AF' }}>{chosen?.label ?? placeholder}</Text>
      </Pressable>
      {error ? <Text style={{ color: colors.danger, marginTop: 4 }}>{error}</Text> : null}
      <OptionSheet
        visible={open}
        title={label}
        options={options}
        selected={value === null || value === undefined ? [] : [value]}
        onClose={() => setOpen(false)}
        onPick={(v) => {
          onChange(v);
          setOpen(false);
        }}
        onClear={allowClear ? () => { onChange(null); setOpen(false); } : undefined}
      />
    </View>
  );
}

/** Tick several values. */
export function MultiPickerField({
  label,
  values,
  options,
  onChange,
  placeholder = 'Choose…',
}: {
  label: string;
  values: (number | string)[];
  options: Option[];
  onChange: (values: (number | string)[]) => void;
  placeholder?: string;
}) {
  const [open, setOpen] = useState(false);
  const names = options.filter((o) => values.includes(o.value)).map((o) => o.label);
  return (
    <View style={{ marginBottom: 14 }}>
      <Text style={styles.label}>{label}</Text>
      <Pressable onPress={() => setOpen(true)} style={styles.box}>
        <Text style={{ fontSize: 16, color: names.length ? colors.text : '#9CA3AF' }}>{names.length ? names.join(', ') : placeholder}</Text>
      </Pressable>
      <OptionSheet
        visible={open}
        title={label}
        options={options}
        selected={values}
        multi
        onClose={() => setOpen(false)}
        onPick={(v) => onChange(values.includes(v) ? values.filter((x) => x !== v) : [...values, v])}
      />
    </View>
  );
}

function OptionSheet({
  visible,
  title,
  options,
  selected,
  multi,
  onClose,
  onPick,
  onClear,
}: {
  visible: boolean;
  title: string;
  options: Option[];
  selected: (number | string)[];
  multi?: boolean;
  onClose: () => void;
  onPick: (value: number | string) => void;
  onClear?: () => void;
}) {
  const [query, setQuery] = useState('');
  const shown = useMemo(() => {
    const q = query.trim().toLowerCase();
    return q ? options.filter((o) => `${o.label} ${o.hint ?? ''}`.toLowerCase().includes(q)) : options;
  }, [options, query]);
  return (
    <Modal visible={visible} animationType="slide" onRequestClose={onClose}>
      <SafeAreaView style={{ flex: 1, backgroundColor: colors.bg }}>
        <View style={styles.sheetHeader}>
          <Text style={{ flex: 1, fontSize: 18, fontWeight: '700', color: colors.text }}>{title}</Text>
          <Pressable onPress={onClose}>
            <Text style={{ color: colors.primary, fontWeight: '700', fontSize: 16 }}>{multi ? 'Done' : 'Close'}</Text>
          </Pressable>
        </View>
        <TextInput placeholder="Search" value={query} onChangeText={setQuery} style={[styles.box, { marginHorizontal: 16 }]} placeholderTextColor="#9CA3AF" />
        {onClear ? (
          <Pressable onPress={onClear} style={styles.option}>
            <Text style={{ color: colors.muted }}>— None —</Text>
          </Pressable>
        ) : null}
        <FlatList
          data={shown}
          keyExtractor={(o) => String(o.value)}
          renderItem={({ item }) => {
            const on = selected.includes(item.value);
            return (
              <Pressable onPress={() => onPick(item.value)} style={[styles.option, on && { backgroundColor: '#EEF2FF' }]}>
                <View style={{ flex: 1 }}>
                  <Text style={{ fontSize: 16, color: colors.text, fontWeight: on ? '700' : '400' }}>{item.label}</Text>
                  {item.hint ? <Text style={{ color: colors.muted }}>{item.hint}</Text> : null}
                </View>
                {on ? <Text style={{ color: colors.primary, fontSize: 18 }}>✓</Text> : null}
              </Pressable>
            );
          }}
          ListEmptyComponent={<Text style={{ padding: 24, color: colors.muted, textAlign: 'center' }}>Nothing found.</Text>}
        />
      </SafeAreaView>
    </Modal>
  );
}

/** Label / value lines for a detail page. */
export function KeyValues({ rows }: { rows: [string, ReactNode][] }) {
  return (
    <View>
      {rows.map(([k, v]) => (
        <View key={k} style={styles.kv}>
          <Text style={styles.kvKey}>{k}</Text>
          {typeof v === 'string' || typeof v === 'number' || v === null || v === undefined ? (
            <Text style={styles.kvValue}>{v === null || v === undefined || v === '' ? '—' : String(v)}</Text>
          ) : (
            <View style={{ flex: 1 }}>{v}</View>
          )}
        </View>
      ))}
    </View>
  );
}

/** Pill tabs across the top of a list. */
export function Tabs<T extends string>({ value, options, onChange }: { value: T; options: [T, string][]; onChange: (v: T) => void }) {
  return (
    <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 14 }}>
      {options.map(([v, label]) => (
        <Pressable
          key={v}
          testID={`tab-${v}`}
          onPress={() => onChange(v)}
          style={{
            paddingHorizontal: 14,
            paddingVertical: 8,
            borderRadius: 20,
            borderWidth: 1,
            borderColor: colors.primary,
            backgroundColor: value === v ? colors.primary : '#fff',
          }}
        >
          <Text style={{ color: value === v ? '#fff' : colors.primary, fontWeight: '600' }}>{label}</Text>
        </Pressable>
      ))}
    </View>
  );
}

/** A card heading with an optional action on the right. */
export function SectionHeader({ title, action, onAction }: { title: string; action?: string; onAction?: () => void }) {
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', marginBottom: 8 }}>
      <Text style={{ flex: 1, fontSize: 17, fontWeight: '700', color: colors.text }}>{title}</Text>
      {action && onAction ? (
        <Pressable onPress={onAction}>
          <Text style={{ color: colors.primary, fontWeight: '700' }}>{action}</Text>
        </Pressable>
      ) : null}
    </View>
  );
}

/** Yes / no question before something that cannot easily be undone. */
export function confirm(title: string, message: string, yes = 'Yes'): Promise<boolean> {
  return new Promise((resolve) =>
    Alert.alert(title, message, [
      { text: 'Cancel', style: 'cancel', onPress: () => resolve(false) },
      { text: yes, style: 'destructive', onPress: () => resolve(true) },
    ]),
  );
}

/** A small modal form: fields plus Save / Cancel. */
export function FormModal({
  visible,
  title,
  children,
  onSave,
  onClose,
  busy,
  saveLabel = 'Save',
}: {
  visible: boolean;
  title: string;
  children: ReactNode;
  onSave: () => void;
  onClose: () => void;
  busy?: boolean;
  saveLabel?: string;
}) {
  return (
    <Modal visible={visible} animationType="slide" onRequestClose={onClose}>
      <SafeAreaView style={{ flex: 1, backgroundColor: colors.bg }}>
        <View style={styles.sheetHeader}>
          <Text style={{ flex: 1, fontSize: 18, fontWeight: '700', color: colors.text }}>{title}</Text>
          <Pressable onPress={onClose}>
            <Text style={{ color: colors.primary, fontWeight: '700', fontSize: 16 }}>Close</Text>
          </Pressable>
        </View>
        <FlatList
          data={[0]}
          keyExtractor={() => 'f'}
          keyboardShouldPersistTaps="handled"
          contentContainerStyle={{ padding: 16 }}
          renderItem={() => (
            <View>
              {children}
              <Button label={saveLabel} onPress={onSave} busy={busy} />
            </View>
          )}
        />
      </SafeAreaView>
    </Modal>
  );
}

const styles = StyleSheet.create({
  label: { fontWeight: '600', color: colors.text, marginBottom: 6, fontSize: 15 },
  box: { backgroundColor: '#fff', borderWidth: 1, borderColor: colors.border, borderRadius: 12, paddingHorizontal: 14, paddingVertical: 13, fontSize: 16, color: colors.text, marginBottom: 8 },
  sheetHeader: { flexDirection: 'row', alignItems: 'center', padding: 16 },
  option: { flexDirection: 'row', alignItems: 'center', paddingHorizontal: 16, paddingVertical: 14, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: colors.border },
  kv: { flexDirection: 'row', paddingVertical: 7, borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: colors.border },
  kvKey: { width: 130, color: colors.muted },
  kvValue: { flex: 1, color: colors.text },
});
