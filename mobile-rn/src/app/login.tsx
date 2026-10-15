import { useState } from 'react';
import { Alert, KeyboardAvoidingView, Platform, Pressable, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { Banner, Button, Field, colors } from '../components/ui';
import { api, ApiError } from '../lib/api';
import { useAuth } from '../lib/auth';
import { ALLOW_SERVER_SETTING, apiUrl, normaliseServerAddress, saveServerAddress, serverLabel } from '../lib/config';

export default function LoginScreen() {
  const { login } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [server, setServer] = useState(apiUrl());
  const [editingServer, setEditingServer] = useState(false);
  const [serverInput, setServerInput] = useState(serverLabel(apiUrl()));
  const [forgot, setForgot] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);

  async function submit() {
    setError(null);
    setNotice(null);
    setFieldErrors({});
    if (!email.trim() || !password) {
      setFieldErrors({
        ...(email.trim() ? {} : { email: 'Enter your email.' }),
        ...(password ? {} : { password: 'Enter your password.' }),
      });
      return;
    }
    setBusy(true);
    try {
      await login(email, password);
    } catch (e) {
      const err = e as ApiError;
      setError(err.message);
      setFieldErrors(err.errors ?? {});
    } finally {
      setBusy(false);
    }
  }

  async function askHr() {
    setError(null);
    if (!email.trim()) {
      setFieldErrors({ email: 'Enter the email of your work account.' });
      return;
    }
    setBusy(true);
    try {
      await api.post('/auth/forgot-password', { email: email.trim() });
      setNotice('HR has been asked to reset your password. They will give you a temporary one.');
      setForgot(false);
    } catch (e) {
      setError((e as ApiError).firstMessage);
    } finally {
      setBusy(false);
    }
  }

  async function testAndSave() {
    const url = normaliseServerAddress(serverInput);
    try {
      const res = await fetch(`${url}/client-settings`, { headers: { Accept: 'application/json' } });
      if (res.status >= 500) throw new Error();
    } catch {
      Alert.alert('No answer', `Nothing answered at ${serverLabel(url)}. Check the address and that the server is running.`);
      return;
    }
    setServer(await saveServerAddress(serverInput));
    setEditingServer(false);
  }

  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: colors.primaryDark }}>
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1, justifyContent: 'center' }}>
        <View style={styles.brand}>
          <Text style={styles.logo}>HRMS</Text>
          <Text style={styles.tagline}>{forgot ? 'Forgot your password?' : 'Sign in to continue'}</Text>
        </View>
        <View style={styles.sheet}>
          {error ? <Banner message={error} /> : null}
          {notice ? <Banner message={notice} tone="success" /> : null}
          <Field
            testID="login-email"
            label="Email"
            value={email}
            onChangeText={setEmail}
            autoCapitalize="none"
            autoComplete="email"
            keyboardType="email-address"
            error={fieldErrors.email}
          />
          {forgot ? (
            <>
              <Text style={{ color: colors.muted, marginBottom: 14 }}>
                HR will be asked to reset your password and give you a temporary one.
              </Text>
              <Button testID="ask-hr" label="Ask HR to reset my password" onPress={askHr} busy={busy} />
              <Button label="Back to sign in" kind="outline" onPress={() => setForgot(false)} />
            </>
          ) : (
            <>
              <Field
                testID="login-password"
                label="Password"
                value={password}
                onChangeText={setPassword}
                secureTextEntry
                error={fieldErrors.password}
                onSubmitEditing={submit}
              />
              <Button testID="login-submit" label="Sign in" onPress={submit} busy={busy} />
              <Pressable onPress={() => setForgot(true)}>
                <Text style={styles.link}>Forgot password?</Text>
              </Pressable>
            </>
          )}

          {ALLOW_SERVER_SETTING ? (
            editingServer ? (
              <View style={{ marginTop: 16 }}>
                <Field
                  label="Server address"
                  value={serverInput}
                  onChangeText={setServerInput}
                  autoCapitalize="none"
                  placeholder="192.168.1.164:8000"
                />
                <Button label="Test and save" kind="outline" onPress={testAndSave} />
              </View>
            ) : (
              <Pressable onPress={() => setEditingServer(true)} style={{ marginTop: 16 }}>
                <Text style={styles.server}>Server: {serverLabel(server)} · Change</Text>
              </Pressable>
            )
          ) : null}
        </View>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  brand: { alignItems: 'center', marginBottom: 24 },
  logo: { color: '#fff', fontSize: 36, fontWeight: '800', letterSpacing: 2 },
  tagline: { color: '#C7D2FE', marginTop: 6, fontSize: 16 },
  sheet: { backgroundColor: colors.bg, marginHorizontal: 16, borderRadius: 20, padding: 20 },
  link: { color: colors.primary, textAlign: 'center', fontWeight: '600', marginTop: 4 },
  server: { color: colors.muted, textAlign: 'center' },
});
