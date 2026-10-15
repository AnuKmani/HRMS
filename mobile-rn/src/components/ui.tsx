import type { ReactNode } from 'react';
import {
  ActivityIndicator,
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
  type TextInputProps,
  type ViewStyle,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

export const colors = {
  primary: '#4F46E5',
  primaryDark: '#1E1B4B',
  bg: '#F5F6FB',
  card: '#FFFFFF',
  text: '#111827',
  muted: '#6B7280',
  border: '#E5E7EB',
  danger: '#B91C1C',
  dangerBg: '#FEE2E2',
  success: '#15803D',
  successBg: '#DCFCE7',
  warning: '#B45309',
  warningBg: '#FEF3C7',
};

/** A scrolling page that stays clear of the phone's bars. Pull to refresh. */
export function Screen({
  children,
  refreshing = false,
  onRefresh,
  padded = true,
}: {
  children: ReactNode;
  refreshing?: boolean;
  onRefresh?: () => void;
  padded?: boolean;
}) {
  return (
    <SafeAreaView style={styles.safe} edges={['bottom', 'left', 'right']}>
      <ScrollView
        contentContainerStyle={padded ? styles.content : undefined}
        keyboardShouldPersistTaps="handled"
        refreshControl={onRefresh ? <RefreshControl refreshing={refreshing} onRefresh={onRefresh} /> : undefined}
      >
        {children}
      </ScrollView>
    </SafeAreaView>
  );
}

/** Vertical space between blocks. */
Screen.Spacer = function Spacer({ size = 14 }: { size?: number }) {
  return <View style={{ height: size }} />;
};

export function Card({ children, style }: { children: ReactNode; style?: ViewStyle }) {
  return <View style={[styles.card, style]}>{children}</View>;
}

export function Title({ children }: { children: ReactNode }) {
  return <Text style={styles.title}>{children}</Text>;
}

export function Muted({ children }: { children: ReactNode }) {
  return <Text style={styles.muted}>{children}</Text>;
}

export function Button({
  label,
  onPress,
  kind = 'primary',
  busy = false,
  disabled = false,
  testID,
}: {
  label: string;
  onPress: () => void;
  kind?: 'primary' | 'outline' | 'danger';
  busy?: boolean;
  disabled?: boolean;
  testID?: string;
}) {
  const off = disabled || busy;
  return (
    <Pressable
      testID={testID}
      accessibilityRole="button"
      onPress={off ? undefined : onPress}
      style={({ pressed }) => [
        styles.button,
        kind === 'primary' && styles.buttonPrimary,
        kind === 'outline' && styles.buttonOutline,
        kind === 'danger' && styles.buttonDanger,
        (pressed || off) && { opacity: 0.6 },
      ]}
    >
      {busy ? (
        <ActivityIndicator color={kind === 'primary' ? '#fff' : colors.primary} />
      ) : (
        <Text
          style={[
            styles.buttonText,
            kind === 'primary' && { color: '#fff' },
            kind === 'outline' && { color: colors.primary },
            kind === 'danger' && { color: colors.danger },
          ]}
        >
          {label}
        </Text>
      )}
    </Pressable>
  );
}

export function Field({
  label,
  error,
  required,
  ...input
}: TextInputProps & { label: string; error?: string; required?: boolean }) {
  return (
    <View style={{ marginBottom: 14 }}>
      <Text style={styles.label}>
        {label}
        {required ? ' *' : ''}
      </Text>
      <TextInput
        placeholderTextColor="#9CA3AF"
        {...input}
        style={[styles.input, error ? { borderColor: colors.danger } : null, input.multiline && { minHeight: 90, textAlignVertical: 'top' }]}
      />
      {error ? <Text style={styles.error}>{error}</Text> : null}
    </View>
  );
}

export function Banner({ message, tone = 'error' }: { message: string; tone?: 'error' | 'success' | 'warning' }) {
  const bg = tone === 'error' ? colors.dangerBg : tone === 'success' ? colors.successBg : colors.warningBg;
  const fg = tone === 'error' ? colors.danger : tone === 'success' ? colors.success : colors.warning;
  return (
    <View style={[styles.banner, { backgroundColor: bg }]}>
      <Text style={{ color: fg, fontSize: 15 }}>{message}</Text>
    </View>
  );
}

export function Chip({ label, tone = 'neutral' }: { label: string; tone?: 'neutral' | 'success' | 'danger' | 'warning' | 'info' }) {
  const map = {
    neutral: ['#E5E7EB', '#374151'],
    success: [colors.successBg, colors.success],
    danger: [colors.dangerBg, colors.danger],
    warning: [colors.warningBg, colors.warning],
    info: ['#E0E7FF', colors.primary],
  } as const;
  const [bg, fg] = map[tone];
  return (
    <View style={[styles.chip, { backgroundColor: bg }]}>
      <Text style={{ color: fg, fontWeight: '600', fontSize: 12 }}>{label}</Text>
    </View>
  );
}

export function Loading() {
  return (
    <View style={{ padding: 40, alignItems: 'center' }}>
      <ActivityIndicator size="large" color={colors.primary} />
    </View>
  );
}

export function Empty({ message }: { message: string }) {
  return (
    <View style={{ padding: 32, alignItems: 'center' }}>
      <Text style={styles.muted}>{message}</Text>
    </View>
  );
}

/** A tappable list row. */
export function Row({
  title,
  subtitle,
  right,
  onPress,
  testID,
}: {
  title: string;
  subtitle?: string;
  right?: ReactNode;
  onPress?: () => void;
  testID?: string;
}) {
  return (
    <Pressable testID={testID} onPress={onPress} style={({ pressed }) => [styles.row, pressed && onPress && { backgroundColor: '#F3F4F6' }]}>
      <View style={{ flex: 1 }}>
        <Text style={styles.rowTitle}>{title}</Text>
        {subtitle ? <Text style={styles.muted}>{subtitle}</Text> : null}
      </View>
      {right}
    </Pressable>
  );
}

export function ProgressBar({ value }: { value: number }) {
  const pct = Math.max(0, Math.min(100, value));
  return (
    <View style={styles.barTrack}>
      <View style={[styles.barFill, { width: `${pct}%` }]} />
    </View>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.bg },
  content: { padding: 16, paddingBottom: 32 },
  card: {
    backgroundColor: colors.card,
    borderRadius: 14,
    padding: 16,
    marginBottom: 14,
    borderWidth: 1,
    borderColor: colors.border,
  },
  title: { fontSize: 18, fontWeight: '700', color: colors.text, marginBottom: 8 },
  muted: { color: colors.muted, fontSize: 14 },
  label: { fontWeight: '600', color: colors.text, marginBottom: 6, fontSize: 15 },
  input: {
    backgroundColor: '#fff',
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 12,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 16,
    color: colors.text,
  },
  error: { color: colors.danger, marginTop: 4 },
  button: {
    borderRadius: 14,
    paddingVertical: 14,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: 10,
  },
  buttonPrimary: { backgroundColor: colors.primary },
  buttonOutline: { borderWidth: 1, borderColor: colors.primary, backgroundColor: '#fff' },
  buttonDanger: { borderWidth: 1, borderColor: colors.danger, backgroundColor: '#fff' },
  buttonText: { fontSize: 16, fontWeight: '700' },
  banner: { borderRadius: 12, padding: 14, marginBottom: 14 },
  chip: { borderRadius: 12, paddingHorizontal: 10, paddingVertical: 4, alignSelf: 'flex-start' },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 12,
    borderBottomWidth: StyleSheet.hairlineWidth,
    borderBottomColor: colors.border,
    gap: 8,
  },
  rowTitle: { fontSize: 16, fontWeight: '600', color: colors.text },
  barTrack: { height: 8, borderRadius: 4, backgroundColor: '#E5E7EB', overflow: 'hidden', marginVertical: 6 },
  barFill: { height: 8, backgroundColor: colors.primary },
});
