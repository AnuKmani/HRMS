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
      <Stack.Screen name="attendance-records" options={{ title: 'Attendance history' }} />
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
      <Stack.Screen name="account" options={{ title: 'My account' }} />
      <Stack.Screen name="training-compliance" options={{ title: 'Training compliance' }} />
      <Stack.Screen name="site-activity/index" options={{ title: 'Site activity reports' }} />
      <Stack.Screen name="site-activity/form" options={{ title: 'Activity report' }} />
      <Stack.Screen name="site-activity/[id]" options={{ title: 'Activity report' }} />
      <Stack.Screen name="site-visits" options={{ title: 'Site visits' }} />
      <Stack.Screen name="visit-plans" options={{ title: 'Planned site visits' }} />
      <Stack.Screen name="employees/index" options={{ title: 'Employees' }} />
      <Stack.Screen name="employees/[id]" options={{ title: 'Employee' }} />
      <Stack.Screen name="employees/form" options={{ title: 'Employee' }} />
      <Stack.Screen name="leave-overview/index" options={{ title: 'Leave overview' }} />
      <Stack.Screen name="leave-overview/[employeeId]" options={{ title: 'Employee leave' }} />
      <Stack.Screen name="leave-types" options={{ title: 'Leave types' }} />
      <Stack.Screen name="holidays" options={{ title: 'Holidays' }} />
      <Stack.Screen name="departments" options={{ title: 'Departments' }} />
      <Stack.Screen name="designations" options={{ title: 'Designations' }} />
      <Stack.Screen name="sites/index" options={{ title: 'Sites' }} />
      <Stack.Screen name="sites/form" options={{ title: 'Site' }} />
      <Stack.Screen name="site-assignments" options={{ title: 'Site assignments' }} />
      <Stack.Screen name="timesheets" options={{ title: 'Timesheets' }} />
      <Stack.Screen name="overtime/index" options={{ title: 'Overtime' }} />
      <Stack.Screen name="overtime/new" options={{ title: 'Claim overtime' }} />
      <Stack.Screen name="overtime/[id]" options={{ title: 'Overtime claim' }} />
      <Stack.Screen name="expenses/index" options={{ title: 'Expenses' }} />
      <Stack.Screen name="expenses/new" options={{ title: 'New expense claim' }} />
      <Stack.Screen name="expenses/[id]" options={{ title: 'Expense claim' }} />
      <Stack.Screen name="payroll/index" options={{ title: 'Payroll' }} />
      <Stack.Screen name="payroll/[id]" options={{ title: 'Payroll' }} />
      <Stack.Screen name="salary-slips" options={{ title: 'Payslips' }} />
      <Stack.Screen name="loans/index" options={{ title: 'Loans & advances' }} />
      <Stack.Screen name="loans/new" options={{ title: 'Request a loan' }} />
      <Stack.Screen name="loans/[id]" options={{ title: 'Loan' }} />
      <Stack.Screen name="salary-certificates" options={{ title: 'Salary certificates' }} />
      <Stack.Screen name="documents/index" options={{ title: 'Documents' }} />
      <Stack.Screen name="documents/new" options={{ title: 'Upload document' }} />
      <Stack.Screen name="documents/[id]" options={{ title: 'Document' }} />
      <Stack.Screen name="training/index" options={{ title: 'Training' }} />
      <Stack.Screen name="training/[id]" options={{ title: 'Training' }} />
      <Stack.Screen name="assets/index" options={{ title: 'Assets' }} />
      <Stack.Screen name="assets/form" options={{ title: 'Asset' }} />
      <Stack.Screen name="assets/[id]" options={{ title: 'Asset' }} />
      <Stack.Screen name="onboarding/index" options={{ title: 'Onboarding' }} />
      <Stack.Screen name="onboarding/[employeeId]" options={{ title: 'Onboarding' }} />
      <Stack.Screen name="reports/index" options={{ title: 'Reports' }} />
      <Stack.Screen name="reports/[key]" options={{ title: 'Report' }} />
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
