import { useEffect, useState } from 'react';
import { Image, Text, View } from 'react-native';

import { DateField } from '../components/DateField';
import { FormModal, PickerField, Tabs, type Option } from '../components/kit';
import { Banner, Card, Chip, Empty, Loading, Muted, Row, Screen, Title, colors } from '../components/ui';
import { api, getToken, type Page } from '../lib/api';
import { useAuth } from '../lib/auth';
import { apiUrl } from '../lib/config';
import { MONTH_NAMES, money, prettyDate, timeOf, ymd } from '../lib/format';
import { useLoad } from '../lib/hooks';
import { employeeOptions } from '../lib/people';

type Day = {
  id: number;
  employee?: { full_name?: string } | null;
  site?: { name: string } | null;
  attendance_date: string;
  check_in_at?: string | null;
  check_out_at?: string | null;
  check_in_distance?: string | number | null;
  check_in_accuracy?: string | number | null;
  check_in_purpose?: string | null;
  has_selfie?: boolean;
  working_minutes?: number;
  late_minutes?: number;
  overtime_minutes?: number;
  status: string;
  source?: string;
};
type Earnings = {
  month: string;
  available: boolean;
  currency: string;
  daily_rate: number | null;
  days_worked: number;
  total: number | null;
  note?: string;
  days: { date: string; status: string; pay: number }[];
};

const hm = (m?: number) => (m ? `${Math.floor(m / 60)}h ${m % 60}m` : '—');

/** Attendance day by day (with the check-in photo), and what the month's work is worth. */
export default function AttendanceRecords() {
  const { can, user } = useAuth();
  const seeOthers = can('attendance.manage') || can('employees.view');
  const [tab, setTab] = useState<'days' | 'earnings'>('days');
  const first = new Date();
  first.setDate(1);
  const [from, setFrom] = useState(ymd(first));
  const [to, setTo] = useState(ymd(new Date()));
  const [employeeId, setEmployeeId] = useState<number | null>(user?.employee?.id ?? null);
  const [people, setPeople] = useState<Option[] | null>(null);
  const [photo, setPhoto] = useState<Day | null>(null);
  const now = new Date();
  const [month, setMonth] = useState(`${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`);

  const records = useLoad(
    () =>
      tab === 'days'
        ? api.get<Page<Day>>('/attendance', { date_from: from, date_to: to, employee_id: employeeId ?? undefined, per_page: 100 }).then((p) => p.items)
        : Promise.resolve([] as Day[]),
    [tab, from, to, employeeId],
  );
  const earnings = useLoad(() => (tab === 'earnings' ? api.get<Earnings>('/attendance/my-earnings', { month }) : Promise.resolve(null)), [tab, month]);

  useEffect(() => {
    if (seeOthers) employeeOptions().then(setPeople, () => setPeople([]));
  }, [seeOthers]);

  const months = Array.from({ length: 12 }, (_, i) => {
    const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
    return { value: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`, label: `${MONTH_NAMES[d.getMonth()]} ${d.getFullYear()}` };
  });
  const totalWorked = (records.data ?? []).reduce((s, r) => s + (r.working_minutes ?? 0), 0);

  return (
    <Screen onRefresh={tab === 'days' ? records.reload : earnings.reload}>
      <Tabs value={tab} onChange={setTab} options={[['days', 'Attendance'], ['earnings', 'My earnings']]} />

      {tab === 'days' ? (
        <>
          {seeOthers ? (
            <PickerField label="Employee" allowClear value={employeeId} options={people ?? []} onChange={(v) => setEmployeeId((v as number) ?? null)} placeholder="Everyone you can see" />
          ) : null}
          <DateField label="From" value={from} onChange={setFrom} />
          <DateField label="To" value={to} onChange={setTo} />
          {records.error ? <Banner message={records.error} /> : null}
          {records.loading ? (
            <Loading />
          ) : !records.data?.length ? (
            <Empty message="No attendance in this period." />
          ) : (
            <Card>
              <Muted>
                {records.data.length} day(s) · worked {hm(totalWorked)}
              </Muted>
              {records.data.map((r) => (
                <Row
                  key={r.id}
                  title={`${employeeId === null && r.employee?.full_name ? `${r.employee.full_name} · ` : ''}${prettyDate(r.attendance_date)}`}
                  subtitle={[
                    `${timeOf(r.check_in_at)} – ${timeOf(r.check_out_at)}`,
                    r.working_minutes ? hm(r.working_minutes) : null,
                    r.late_minutes ? `late ${r.late_minutes} min` : null,
                    r.site?.name,
                    r.check_in_purpose,
                    r.check_in_distance != null ? `${Math.round(Number(r.check_in_distance))} m from site` : null,
                    r.source === 'offline' ? 'recorded offline' : null,
                  ]
                    .filter(Boolean)
                    .join(' · ')}
                  right={<Chip label={r.status.replace(/_/g, ' ')} tone={r.status === 'present' ? 'success' : r.status === 'late' ? 'warning' : 'neutral'} />}
                  onPress={r.has_selfie ? () => setPhoto(r) : undefined}
                />
              ))}
              <Muted>Tap a day to see the check-in photo.</Muted>
            </Card>
          )}
        </>
      ) : (
        <>
          <PickerField label="Month" value={month} options={months} onChange={(v) => setMonth(String(v))} />
          {earnings.error ? <Banner message={earnings.error} /> : null}
          {earnings.loading ? (
            <Loading />
          ) : !earnings.data?.available ? (
            <Empty message="Your salary is not set up yet, so earnings cannot be estimated. Ask HR." />
          ) : (
            <>
              <Card>
                <Title>{money(earnings.data.total, earnings.data.currency)}</Title>
                <Muted>
                  {earnings.data.days_worked} day(s) worked × {money(earnings.data.daily_rate, earnings.data.currency)} per day
                </Muted>
                {earnings.data.note ? <Muted>{earnings.data.note}</Muted> : null}
              </Card>
              <Card>
                {earnings.data.days.map((d) => (
                  <View key={d.date} style={{ flexDirection: 'row', paddingVertical: 6 }}>
                    <Text style={{ flex: 1, color: colors.text }}>{prettyDate(d.date)}</Text>
                    <Text style={{ color: colors.text }}>{money(d.pay, earnings.data?.currency)}</Text>
                  </View>
                ))}
              </Card>
            </>
          )}
        </>
      )}

      <FormModal visible={!!photo} title={photo ? `Check-in · ${prettyDate(photo.attendance_date)}` : ''} saveLabel="Close" onSave={() => setPhoto(null)} onClose={() => setPhoto(null)}>
        {photo ? (
          <>
            <Image
              source={{ uri: `${apiUrl()}/attendance/${photo.id}/selfie`, headers: { Authorization: `Bearer ${getToken() ?? ''}` } }}
              style={{ width: '100%', aspectRatio: 3 / 4, borderRadius: 12, backgroundColor: '#eee' }}
              resizeMode="cover"
            />
            <Muted>
              {timeOf(photo.check_in_at)}
              {photo.site ? ` · ${photo.site.name}` : ''}
              {photo.check_in_accuracy != null ? ` · GPS ±${Math.round(Number(photo.check_in_accuracy))} m` : ''}
            </Muted>
          </>
        ) : null}
      </FormModal>
    </Screen>
  );
}

