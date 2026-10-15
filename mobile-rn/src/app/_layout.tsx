import { Stack, useRouter, useSegments } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { useEffect } from 'react';
import { View } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';

import { Loading, colors } from '../components/ui';
import { AuthProvider, useAuth } from '../lib/auth';

/** Sends people where they belong: login, set-your-password, or the app. */
function Gate() {
  const { ready, user } = useAuth();
  const segments = useSegments();
  const router = useRouter();

  useEffect(() => {
    if (!ready) return;
    const first = segments[0] as string | undefined;
    if (!user) {
      if (first !== 'login') router.replace('/login');
    } else if (user.must_change_password) {
      if (first !== 'change-password') router.replace('/change-password');
    } else if (first === 'login' || first === 'change-password') {
      router.replace('/');
    }
  }, [ready, user, segments, router]);

  if (!ready) {
    return (
      <View style={{ flex: 1, justifyContent: 'center', backgroundColor: colors.bg }}>
        <Loading />
      </View>
    );
  }

  return (
    <Stack
      screenOptions={{
        headerStyle: { backgroundColor: colors.primary },
        headerTintColor: '#fff',
        headerTitleStyle: { fontWeight: '700' },
        contentStyle: { backgroundColor: colors.bg },
      }}
    >
      <Stack.Screen name="index" options={{ headerShown: false }} />
      <Stack.Screen name="login" options={{ headerShown: false }} />
      <Stack.Screen name="change-password" options={{ title: 'Set your password', headerBackVisible: false }} />
      <Stack.Screen name="attendance" options={{ title: 'Attendance' }} />
      <Stack.Screen name="notifications" options={{ title: 'Notifications' }} />
      <Stack.Screen name="leave/index" options={{ title: 'Leave' }} />
      <Stack.Screen name="leave/new" options={{ title: 'Apply for leave' }} />
      <Stack.Screen name="leave/[id]" options={{ title: 'Leave request' }} />
      <Stack.Screen name="leave-balances" options={{ title: 'My leave balances' }} />
      <Stack.Screen name="projects/index" options={{ title: 'Projects' }} />
      <Stack.Screen name="projects/[id]" options={{ title: 'Project' }} />
      <Stack.Screen name="projects/form" options={{ title: 'Project' }} />
      <Stack.Screen name="projects/stages" options={{ title: 'Systems / stages' }} />
      <Stack.Screen name="project-dashboard" options={{ title: 'Project dashboard' }} />
      <Stack.Screen name="project-progress/index" options={{ title: 'Project progress' }} />
      <Stack.Screen name="project-progress/[id]" options={{ title: 'Project progress' }} />
      <Stack.Screen name="site-reports/index" options={{ title: 'Daily site reports' }} />
      <Stack.Screen name="site-reports/form" options={{ title: 'Daily report' }} />
      <Stack.Screen name="site-reports/[id]" options={{ title: 'Daily report' }} />
      <Stack.Screen name="site-visits" options={{ title: 'Site visits' }} />
      <Stack.Screen name="visit-plans" options={{ title: 'Planned site visits' }} />
    </Stack>
  );
}

export default function RootLayout() {
  return (
    <SafeAreaProvider>
      <AuthProvider>
        <StatusBar style="light" />
        <Gate />
      </AuthProvider>
    </SafeAreaProvider>
  );
}
