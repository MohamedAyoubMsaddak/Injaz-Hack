export const dashboardPath = (role) => {
  if (role === 'student') return '/student/houses';
  if (role === 'owner') return '/owner/dashboard';
  if (role === 'worker') return '/worker/dashboard';
  if (role === 'admin') return '/admin/price-negotiations';
  return '/unsupported';
};
