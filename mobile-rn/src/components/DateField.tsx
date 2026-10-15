import DateTimePicker, { DateTimePickerAndroid } from '@react-native-community/datetimepicker';
import { useState } from 'react';
import { Platform, Pressable, StyleSheet, Text, View } from 'react-native';

import { prettyDate, ymd } from '../lib/format';
import { colors } from './ui';

/** A date as `YYYY-MM-DD`, chosen with the phone's own date picker. */
export function DateField({
  label,
  value,
  onChange,
  error,
  testID,
  required,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  error?: string;
  testID?: string;
  required?: boolean;
}) {
  const [showIos, setShowIos] = useState(false);
  const current = value ? new Date(`${value}T00:00:00`) : new Date();

  function open() {
    if (Platform.OS === 'android') {
      DateTimePickerAndroid.open({
        value: current,
        mode: 'date',
        onChange: (event, date) => {
          if (event.type === 'set' && date) onChange(ymd(date));
        },
      });
    } else {
      setShowIos((s) => !s);
    }
  }

  return (
    <View style={{ marginBottom: 14 }}>
      <Text style={styles.label}>
        {label}
        {required ? ' *' : ''}
      </Text>
      <Pressable testID={testID} onPress={open} style={[styles.box, error ? { borderColor: colors.danger } : null]}>
        <Text style={{ fontSize: 16, color: value ? colors.text : '#9CA3AF' }}>{value ? prettyDate(value) : 'Choose a date'}</Text>
      </Pressable>
      {showIos ? (
        <DateTimePicker
          value={current}
          mode="date"
          display="inline"
          onChange={(_, date) => {
            if (date) onChange(ymd(date));
            setShowIos(false);
          }}
        />
      ) : null}
      {error ? <Text style={{ color: colors.danger, marginTop: 4 }}>{error}</Text> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  label: { fontWeight: '600', color: colors.text, marginBottom: 6, fontSize: 15 },
  box: { backgroundColor: '#fff', borderWidth: 1, borderColor: colors.border, borderRadius: 12, paddingHorizontal: 14, paddingVertical: 14 },
});
