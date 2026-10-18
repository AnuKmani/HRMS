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
  { label: 'Attendance history', hint: 'Past days, photos, earnings', path: '/attendance-records', icon: '📅', section: 'My day', visible: (c, e) => e || c('attendance.manage') },
  { label: 'Site visits', hint: 'Start / site out', path: '/site-visits', icon: '📍', section: 'My day', visible: (c, e) => e && c('site_visits.create') },
  { label: 'Leave', hint: 'Apply, approve, track', path: '/leave', icon: '🏖', section: 'My day', visible: (c) => c('leave.view') },
  { label: 'Leave balances', hint: 'What is left this year', path: '/leave-balances', icon: '📊', section: 'My day', visible: (c) => c('leave.balance.view') },
  { label: 'My payslips', hint: 'Download or request', path: '/salary-slips', icon: '💵', section: 'Pay & money', visible: (c, e) => c('salary_slips.view') && (e || c('salary_slips.manage')) },
  { label: 'Holidays', hint: 'The holiday calendar', path: '/holidays', icon: '🎉', section: 'My day', visible: () => true },
  { label: 'Timesheets', hint: 'Hours worked per day', path: '/timesheets', icon: '🕒', section: 'My day', visible: (c) => c('timesheets.view') },
  { label: 'Overtime', hint: 'Claim and approve extra hours', path: '/overtime', icon: '⏳', section: 'My day', visible: (c) => c('overtime.view') },
  { label: 'Planned site visits', hint: 'Visits planned for you', path: '/visit-plans', icon: '🗓', section: 'Projects & sites', visible: (c) => c('site_visit_plans.view') || c('site_visit_plans.manage') },

  { label: 'Project dashboard', hint: 'Progress, value, billing', path: '/project-dashboard', icon: '📈', section: 'Projects & sites', visible: (c) => c('projects.dashboard.view') },
  { label: 'Projects', hint: 'Work in flight', path: '/projects', icon: '🏗', section: 'Projects & sites', visible: (c) => c('projects.view') },
  { label: 'Project progress', hint: 'Progress and payments due', path: '/project-progress', icon: '💹', section: 'Projects & sites', visible: (c) => c('projects.progress.view') && !c('projects.view') },
  { label: 'Sites', hint: 'Locations and check-in radius', path: '/sites', icon: '📌', section: 'Projects & sites', visible: (c) => c('sites.view') },
  { label: 'Site assignments', hint: 'Who works where', path: '/site-assignments', icon: '🧭', section: 'Projects & sites', visible: (c) => c('assignments.view') },
  { label: 'Daily site reports', hint: 'One per site per day', path: '/site-reports', icon: '📝', section: 'Projects & sites', visible: (c) => c('daily_site_reports.view') },
  { label: 'Site activity reports', hint: 'What you did at a site, with photos', path: '/site-activity', icon: '🦺', section: 'Projects & sites', visible: (c) => c('site_activity_reports.view') },

  { label: 'Employees', hint: 'Everyone in the directory', path: '/employees', icon: '👥', section: 'People', visible: (c) => c('employees.view') },
  { label: 'Leave overview', hint: "Everyone's leave left", path: '/leave-overview', icon: '🗂', section: 'People', visible: (c) => c('leave.balance.manage') },
  { label: 'Leave types', hint: 'Days per year, unlimited', path: '/leave-types', icon: '⚙️', section: 'People', visible: (c) => c('leave.manage') },
  { label: 'Documents', hint: 'IDs, visas, expiry', path: '/documents', icon: '🪪', section: 'People', visible: (c) => c('documents.view') },
  { label: 'Training', hint: 'Courses and certificates', path: '/training', icon: '🎓', section: 'People', visible: (c) => c('training.view') },
  { label: 'Onboarding', hint: 'New joiners checklist', path: '/onboarding', icon: '🧩', section: 'People', visible: (c) => c('onboarding.view') },
  { label: 'Assets', hint: 'Who holds what', path: '/assets', icon: '💻', section: 'Company', visible: (c) => c('assets.view') },
  { label: 'Reports', hint: 'Run and export', path: '/reports', icon: '📑', section: 'Company', visible: (c) => c('reports.view') },
  { label: 'Departments', hint: 'Company departments', path: '/departments', icon: '🏢', section: 'Company', visible: (c) => c('departments.view') },
  { label: 'Designations', hint: 'Job titles', path: '/designations', icon: '🏷', section: 'Company', visible: (c) => c('designations.view') },

  { label: 'Payroll', hint: 'Run, review, lock', path: '/payroll', icon: '🏦', section: 'Pay & money', visible: (c) => c('payroll.view') && (c('payroll.process') || c('payroll.manage')) },
  { label: 'Loans & advances', hint: 'Request and repay', path: '/loans', icon: '🤝', section: 'Pay & money', visible: (c) => c('loans.view') },
  { label: 'Salary certificates', hint: 'For banks, visas', path: '/salary-certificates', icon: '📄', section: 'Pay & money', visible: (c) => c('salary_certificates.view') },
  { label: 'Expenses', hint: 'Claims with receipts', path: '/expenses', icon: '🧾', section: 'Pay & money', visible: (c) => c('expenses.view') },
];

export const SECTION_ORDER: ModuleTile['section'][] = ['My day', 'Projects & sites', 'People', 'Pay & money', 'Company'];
