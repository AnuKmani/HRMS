/** Every module tile on the dashboard: who sees it, and where it lives. */
export type ModuleTile = {
  label: string;
  hint: string;
  path: string;
  icon: string;
  section: 'My day' | 'Projects & sites' | 'People' | 'Pay & money' | 'Company';
  visible: (can: (p: string) => boolean, hasEmployee: boolean) => boolean;
};

export const MODULES: ModuleTile[] = [
  { label: 'Attendance', hint: 'Check in and out', path: '/attendance', icon: '⏱', section: 'My day', visible: (_, e) => e },
  { label: 'Site visits', hint: 'Start / site out', path: '/site-visits', icon: '📍', section: 'My day', visible: (c, e) => e && c('site_visits.create') },
  { label: 'Leave', hint: 'Apply, approve, track', path: '/leave', icon: '🏖', section: 'My day', visible: (c) => c('leave.view') },
  { label: 'Leave balances', hint: 'What is left this year', path: '/leave-balances', icon: '📊', section: 'My day', visible: (c) => c('leave.balance.view') },
  { label: 'Holidays', hint: 'The holiday calendar', path: '/holidays', icon: '🎉', section: 'My day', visible: () => true },
  { label: 'Planned site visits', hint: 'Visits planned for you', path: '/visit-plans', icon: '🗓', section: 'Projects & sites', visible: (c) => c('site_visit_plans.view') || c('site_visit_plans.manage') },

  { label: 'Project dashboard', hint: 'Progress, value, billing', path: '/project-dashboard', icon: '📈', section: 'Projects & sites', visible: (c) => c('projects.dashboard.view') },
  { label: 'Projects', hint: 'Work in flight', path: '/projects', icon: '🏗', section: 'Projects & sites', visible: (c) => c('projects.view') },
  { label: 'Project progress', hint: 'Progress and payments due', path: '/project-progress', icon: '💹', section: 'Projects & sites', visible: (c) => c('projects.progress.view') && !c('projects.view') },
  { label: 'Daily site reports', hint: 'One per site per day', path: '/site-reports', icon: '📝', section: 'Projects & sites', visible: (c) => c('daily_site_reports.view') },

  { label: 'Employees', hint: 'Everyone in the directory', path: '/employees', icon: '👥', section: 'People', visible: (c) => c('employees.view') },
  { label: 'Leave overview', hint: "Everyone's leave left", path: '/leave-overview', icon: '🗂', section: 'People', visible: (c) => c('leave.balance.manage') },
  { label: 'Leave types', hint: 'Days per year, unlimited', path: '/leave-types', icon: '⚙️', section: 'People', visible: (c) => c('leave.manage') },
];

export const SECTION_ORDER: ModuleTile['section'][] = ['My day', 'Projects & sites', 'People', 'Pay & money', 'Company'];
