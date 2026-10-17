import { useEffect, useState } from 'react';
import { Link, NavLink, Navigate, Route, Routes, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { apiRequest, ApiError } from './api.js';
import { getLanguage, setLanguage, t } from './i18n.js';

const dashboardPath = (role) => {
  if (role === 'student') return '/student/dashboard';
  if (role === 'owner') return '/owner/dashboard';
  if (role === 'worker') return '/worker/dashboard';
  if (role === 'admin') return '/admin/price-negotiations';
  return '/unsupported';
};

function addTndAmounts(amount, margin) {
  const toMilliunits = (value) => {
    const [whole, fraction = ''] = String(value).split('.');
    return Number(whole) * 1000 + Number(fraction.padEnd(3, '0').slice(0, 3));
  };
  const total = toMilliunits(amount) + toMilliunits(margin);

  return `${Math.floor(total / 1000)}.${String(total % 1000).padStart(3, '0')}`;
}

function App() {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [connectionError, setConnectionError] = useState('');
  const [language, setCurrentLanguage] = useState(getLanguage);

  function changeLanguage(nextLanguage) {
    setLanguage(nextLanguage);
    setCurrentLanguage(nextLanguage);
  }

  useEffect(() => {
    apiRequest('/api/auth/user').
    then(({ user: authenticatedUser }) => setUser(authenticatedUser)).
    catch((error) => {
      if (error instanceof ApiError && error.status === 401) {
        setUser(null);
      } else {
        setConnectionError(error.message);
      }

    }).
    finally(() => setLoading(false));
  }, []);

  if (loading) {
    return <main className="loading-screen">{t("Checking your session…")}</main>;
  }

  return (
    <div className="app-shell">
      <header className="site-header">
        <Link className="brand" to={user ? dashboardPath(user.role) : '/'} aria-label={t("Sakani home")}>
          <span className="brand-mark">{t("s.")}</span>
          <span>{t("Sakani")}</span>
        </Link>
        <span className="header-note">{t("A place to feel at home")}</span>
        {user ?
        <div className="authenticated-header-actions">
            <MaintenanceNotifications user={user} />
            <LanguageToggle language={language} onChange={changeLanguage} />
            <LogoutButton onLoggedOut={() => setUser(null)} />
          </div> :

        <div className="public-header-actions">
            <LanguageToggle language={language} onChange={changeLanguage} />
            <Link className="header-link" to="/login">{t("Log in")}</Link>
            <Link className="header-register-link" to="/register">{t("Create account")}</Link>
          </div>
        }
      </header>

      <main className="page-content">
        {connectionError && <div className="notice error-notice" role="alert">{connectionError}</div>}
        <Routes>
          <Route
            path="/"
            element={<Navigate to={user ? dashboardPath(user.role) : '/login'} replace />} />
          
          <Route
            path="/register"
            element={user ? <Navigate to={dashboardPath(user.role)} replace /> : <RegisterPage onAuthenticated={setUser} />} />
          
          <Route
            path="/login"
            element={user ? <Navigate to={dashboardPath(user.role)} replace /> : <LoginPage onAuthenticated={setUser} />} />
          
          <Route
            path="/student/dashboard"
            element={<StudentPortal user={user}><StudentDashboard user={user} /></StudentPortal>} />
          
          <Route
            path="/student/houses"
            element={<StudentPortal user={user}><StudentHouseSearchPage /></StudentPortal>} />
          
          <Route
            path="/student/houses/:houseId"
            element={<StudentPortal user={user}><StudentHouseDetailsPage /></StudentPortal>} />
          
          <Route
            path="/student/rental-requests"
            element={<StudentPortal user={user}><StudentRentalRequestsPage /></StudentPortal>} />
          
          <Route
            path="/student/payments"
            element={<StudentPortal user={user}><StudentPaymentsPage /></StudentPortal>} />
          
          <Route
            path="/student/maintenance-requests"
            element={<StudentPortal user={user}><StudentMaintenancePage /></StudentPortal>} />
          
          <Route
            path="/owner/dashboard"
            element={<OwnerDashboard user={user} />} />
          
          <Route
            path="/owner/houses"
            element={<OwnerPortal user={user}><OwnerHousesPage /></OwnerPortal>} />
          
          <Route
            path="/owner/houses/new"
            element={<OwnerPortal user={user}><HouseFormPage /></OwnerPortal>} />
          
          <Route
            path="/owner/houses/:houseId"
            element={<OwnerPortal user={user}><HouseDetailsPage /></OwnerPortal>} />
          
          <Route
            path="/owner/houses/:houseId/edit"
            element={<OwnerPortal user={user}><HouseFormPage /></OwnerPortal>} />
          
          <Route
            path="/owner/rental-requests"
            element={<OwnerPortal user={user}><OwnerRentalRequestsPage /></OwnerPortal>} />
          
          <Route
            path="/owner/maintenance-requests"
            element={<OwnerPortal user={user}><OwnerMaintenancePage /></OwnerPortal>} />
          
          <Route
            path="/owner/subscriptions"
            element={<OwnerPortal user={user}><OwnerSubscriptionsPage /></OwnerPortal>} />
          
          <Route
            path="/owner/settings"
            element={<OwnerPortal user={user}><OwnerPlaceholder title={t("Account settings")} description={t("Account settings will be available in a later step.")} /></OwnerPortal>} />
          
          <Route
            path="/worker/dashboard"
            element={<WorkerDashboard user={user} />} />
          
          <Route
            path="/admin/price-negotiations"
            element={<AdminPriceNegotiationsPage user={user} />} />
          
          <Route
            path="/admin/house-approvals"
            element={<AdminHouseApprovalsPage user={user} />} />
          
          <Route
            path="/worker/inspections"
            element={<WorkerPortal user={user}><WorkerInspectionsPage /></WorkerPortal>} />
          
          <Route
            path="/worker/inspections/:inspectionId"
            element={<WorkerPortal user={user}><WorkerInspectionPage /></WorkerPortal>} />
          
          <Route
            path="/worker/maintenance-requests"
            element={<WorkerPortal user={user}><WorkerMaintenancePage /></WorkerPortal>} />
          
          <Route
            path="/unsupported"
            element={user ? <UnsupportedRolePage /> : <Navigate to="/login" replace />} />
          
          <Route path="*" element={<Navigate to={user ? dashboardPath(user.role) : '/login'} replace />} />
        </Routes>
      </main>
      <footer className="site-footer">{t("Made for finding your next home in Tunisia.")}</footer>
    </div>);

}

function AuthLayout({ eyebrow, title, description, children, footer }) {
  return (
    <section className="auth-layout">
      <div className="auth-aside">
        <div className="aside-doodle" aria-hidden="true">
          <span className="doodle-sun" />
          <span className="doodle-house">{t("⌂")}</span>
          <span className="doodle-line" />
        </div>
        <p className="eyebrow">{t("A better way to settle in")}</p>
        <h2>{t("Find a home")}<br />{t("for your next chapter.")}</h2>
        <p className="aside-copy">{t("Explore student-friendly homes near the places that matter to you.")}</p>
        <div className="location-tag"><span className="location-dot" />{t(" Starting in Tunisia")}</div>
      </div>
      <div className="auth-panel">
        <p className="eyebrow">{eyebrow}</p>
        <h1>{title}</h1>
        <p className="auth-description">{description}</p>
        {children}
        <p className="auth-footer">{footer}</p>
      </div>
    </section>);

}

function PublicLandingPage() {
  return (
    <div className="public-landing">
      <section className="landing-hero" aria-labelledby="landing-title">
        <div className="landing-copy">
          <p className="landing-eyebrow"><span aria-hidden="true" />{t(" Student housing in Tunisia")}</p>
          <h1 id="landing-title">{t("Find a home, ")}<em>{t("not another trip.")}</em></h1>
          <p className="landing-intro">{t("\n          Browse approved student homes, compare the details, and send a rental request online.\n          ")}

          </p>
          <div className="landing-actions">
            <Link className="landing-primary-link" to="/register?role=student">{t("Find student housing ")}<span aria-hidden="true">{t("→")}</span></Link>
            <Link className="landing-secondary-link" to="/register?role=owner">{t("List your property")}</Link>
          </div>
          <p className="landing-note">{t("Inspection reports describe what a worker observed on a particular visit. They are useful information, not a guarantee that a home is defect-free.")}</p>
        </div>
        <div className="landing-visual" aria-label={t("Illustration of a student home and inspection summary")}>
          <div className="landing-sun" />
          <div className="landing-building">
            <div className="landing-roof" />
            <div className="landing-house-body">
              <span className="landing-window landing-window-left" />
              <span className="landing-window landing-window-right" />
              <span className="landing-door" />
            </div>
          </div>
          <div className="landing-visual-caption">
            <span className="landing-check" aria-hidden="true">{t("✓")}</span>
            <span><strong>{t("Make a more informed choice")}</strong><small>{t("Review available inspection details")}</small></span>
          </div>
          <span className="landing-location-tag">{t("Made for student life in Tunisia")}</span>
        </div>
      </section>

      <section className="landing-process" aria-labelledby="landing-process-title">
        <div className="landing-section-heading">
          <p className="eyebrow">{t("A clearer way to rent")}</p>
          <h2 id="landing-process-title">{t("From search to settling in")}</h2>
          <p>{t("A clear path from finding a home to securing your rental.")}</p>
        </div>
        <div className="landing-step-grid">
          <article className="landing-step">
            <span className="landing-step-number">{t("01")}</span>
            <h3>{t("Explore verified homes")}</h3>
            <p>{t("Browse available homes after an administrator approves the listing and its public rent.")}</p>
          </article>
          <article className="landing-step">
            <span className="landing-step-number">{t("02")}</span>
            <h3>{t("Review the details")}</h3>
            <p>{t("Review the home details, price, and inspection information when available.")}</p>
          </article>
          <article className="landing-step">
            <span className="landing-step-number">{t("03")}</span>
            <h3>{t("Request and confirm")}</h3>
            <p>{t("Send a rental request, follow its status, and access maintenance support after moving in. The student service fee is due only after both sides confirm the rental is secured.")}</p>
          </article>
        </div>
      </section>

      <section className="landing-owner-callout">
        <div>
          <p className="eyebrow">{t("For property owners")}</p>
          <h2>{t("Share your home with students looking nearby.")}</h2>
          <p>{t("Submit your listing and manage rental and maintenance requests in one place.")}</p>
        </div>
        <Link className="landing-secondary-link" to="/register?role=owner">{t("Register a property ")}<span aria-hidden="true">{t("→")}</span></Link>
      </section>
    </div>);

}

function RegisterPage({ onAuthenticated }) {
  const [searchParams] = useSearchParams();
  const requestedRole = searchParams.get('role');
  const [form, setForm] = useState({
    name: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: '',
    role: ['student', 'owner'].includes(requestedRole) ? requestedRole : ''
  });
  const [errors, setErrors] = useState({});
  const [requestError, setRequestError] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const navigate = useNavigate();

  function update(event) {
    setForm((current) => ({ ...current, [event.target.name]: event.target.value }));
    setErrors((current) => ({ ...current, [event.target.name]: undefined }));
    setRequestError('');
  }

  async function submit(event) {
    event.preventDefault();
    setSubmitting(true);
    setErrors({});
    setRequestError('');

    try {
      const result = await apiRequest('/api/auth/register', {
        method: 'POST',
        body: JSON.stringify(form)
      });
      if (!result.user || !['student', 'owner'].includes(result.user.role)) {
        throw new ApiError('The server returned an unexpected account response.');
      }
      onAuthenticated(result.user);
      navigate(dashboardPath(result.user.role), { replace: true });
    } catch (error) {
      setErrors(error.errors ?? {});
      setRequestError(error.errors && Object.keys(error.errors).length ? '' : error.message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <AuthLayout
      eyebrow="Your next chapter starts here"
      title={t("Create your account")}
      description={t("Join as a student looking for a place, or an owner sharing one.")}
      footer={<>{t("Already have an account? ")}<Link to="/login">{t("Log in")}</Link></>}>
      
      <form className="auth-form" onSubmit={submit} noValidate>
        <fieldset className="role-picker">
          <legend>{t("I am joining as a…")}</legend>
          <div className="role-options">
            <RoleCard
              value="student"
              selected={form.role === 'student'}
              onChange={update}
              icon="✳"
              title={t("Student")}
              caption={t("I’m looking for a home")} />
            
            <RoleCard
              value="owner"
              selected={form.role === 'owner'}
              onChange={update}
              icon="⌂"
              title={t("Property owner")}
              caption={t("I have a place to share")} />
            
          </div>
          <FieldError errors={errors} name="role" />
        </fieldset>

        <div className="field-row">
          <FormField label={t("Full name")} name="name" autoComplete="name" value={form.name} onChange={update} errors={errors} />
          <FormField label={t("Phone number")} name="phone" type="tel" autoComplete="tel" value={form.phone} onChange={update} errors={errors} />
        </div>
        <FormField label={t("Email address")} name="email" type="email" autoComplete="email" value={form.email} onChange={update} errors={errors} />
        <div className="field-row">
          <FormField label={t("Password")} name="password" type="password" autoComplete="new-password" value={form.password} onChange={update} errors={errors} />
          <FormField label={t("Confirm password")} name="password_confirmation" type="password" autoComplete="new-password" value={form.password_confirmation} onChange={update} errors={errors} />
        </div>
        <RequestError message={requestError} />
        <button className="primary-button" type="submit" disabled={submitting || !form.role}>
          {submitting ? 'Creating your account…' : 'Create account'} <span aria-hidden="true">{t("→")}</span>
        </button>
        <p className="form-note">{t("No property details are needed to create your account.")}</p>
      </form>
    </AuthLayout>);

}

function RoleCard({ value, selected, onChange, icon, title, caption }) {
  return (
    <label className={`role-card${selected ? ' selected' : ''}`}>
      <input type="radio" name="role" value={value} checked={selected} onChange={onChange} required />
      <span className="role-icon" aria-hidden="true">{icon}</span>
      <span className="role-title">{t(title)}</span>
      <span className="role-caption">{t(caption)}</span>
      <span className="radio-indicator" aria-hidden="true" />
    </label>);

}

function LoginPage({ onAuthenticated }) {
  const [form, setForm] = useState({ email: '', password: '' });
  const [errors, setErrors] = useState({});
  const [requestError, setRequestError] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const navigate = useNavigate();

  function update(event) {
    setForm((current) => ({ ...current, [event.target.name]: event.target.value }));
    setErrors((current) => ({ ...current, [event.target.name]: undefined }));
    setRequestError('');
  }

  async function submit(event) {
    event.preventDefault();
    setSubmitting(true);
    setErrors({});
    setRequestError('');

    try {
      const result = await apiRequest('/api/auth/login', {
        method: 'POST',
        body: JSON.stringify(form)
      });
      if (!result.user || !['student', 'owner', 'worker', 'admin'].includes(result.user.role)) {
        throw new ApiError('This account is not available in the student, owner, worker, or staff portal.');
      }
      onAuthenticated(result.user);
      navigate(dashboardPath(result.user.role), { replace: true });
    } catch (error) {
      setErrors(error.errors ?? {});
      setRequestError(error.errors && Object.keys(error.errors).length ? '' : error.message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <AuthLayout
      eyebrow="Welcome back"
      title={t("Log in to Sakani")}
      description={t("Use your email and password to continue to your account.")}
      footer={<>{t("New here? ")}<Link to="/register">{t("Create an account")}</Link></>}>
      
      <form className="auth-form login-form" onSubmit={submit} noValidate>
        <FormField label={t("Email address")} name="email" type="email" autoComplete="email" value={form.email} onChange={update} errors={errors} />
        <FormField label={t("Password")} name="password" type="password" autoComplete="current-password" value={form.password} onChange={update} errors={errors} />
        <RequestError message={requestError} />
        <button className="primary-button" type="submit" disabled={submitting}>
          {submitting ? 'Logging in…' : 'Log in'} <span aria-hidden="true">{t("→")}</span>
        </button>
      </form>
    </AuthLayout>);

}

function FormField({ label, name, id = name, type = 'text', step, autoComplete, value, onChange, errors }) {
  const errorId = `${id}-error`;
  const messages = errors[name] ?? [];

  return (
    <div className="form-field">
      <label htmlFor={name}>{label}</label>
      <input
        id={id}
        name={name}
        type={type}
        step={step}
        autoComplete={autoComplete}
        value={value}
        onChange={onChange}
        aria-invalid={messages.length > 0}
        aria-describedby={messages.length > 0 ? errorId : undefined}
        required />
      
      <FieldError errors={errors} name={name} id={errorId} />
    </div>);

}

function FieldError({ errors, name, id }) {
  const messages = errors[name] ?? [];
  return messages.length ? <p className="field-error" id={id} role="alert">{t(messages[0])}</p> : null;
}

function PhotoPreviews({ files, existing = [] }) {
  const [previews, setPreviews] = useState([]);

  useEffect(() => {
    const nextPreviews = files.map((file) => ({ file, url: URL.createObjectURL(file) }));
    setPreviews(nextPreviews);
    return () => nextPreviews.forEach(({ url }) => URL.revokeObjectURL(url));
  }, [files]);

  if (existing.length === 0 && previews.length === 0) return null;

  return (
    <div className="photo-preview-grid" role="group" aria-label={t("Selected and current photo previews")}>
      {existing.map((photo) =>
      <figure key={`existing-${photo.id}`}>
          <img src={photo.url} alt={photo.caption || 'Existing house photo'} />
          <figcaption>{t("Current photo")}</figcaption>
        </figure>
      )}
      {previews.map(({ file, url }, index) =>
      <figure key={`${file.name}-${file.lastModified}-${index}`}>
          <img src={url} alt={`Preview of ${file.name}`} />
          <figcaption>{file.name}</figcaption>
        </figure>
      )}
    </div>);

}

function RequestError({ message }) {
  return message ? <p className="notice error-notice" role="alert">{t(message)}</p> : null;
}

const ownerNavigation = [
{ to: '/owner/dashboard', label: 'Overview' },
{ to: '/owner/houses', label: 'My houses' },
{ to: '/owner/rental-requests', label: 'Rental requests' },
{ to: '/owner/maintenance-requests', label: 'Maintenance' },
{ to: '/owner/subscriptions', label: 'Billing' }];


function OwnerPortal({ user, children }) {
  if (!user) return <Navigate to="/login" replace />;
  if (user.role !== 'owner') return <Navigate to={dashboardPath(user.role)} replace />;

  return (
    <div className="owner-portal">
      <nav className="owner-nav" aria-label={t("Owner dashboard")}>
        {ownerNavigation.map((item) =>
        <NavLink key={item.to} to={item.to} end={item.to !== '/owner/houses'} className="owner-nav-link">{t(item.label)}</NavLink>
        )}
      </nav>
      {children}
    </div>);

}

function OwnerDashboard({ user }) {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!user) return;
    apiRequest('/api/owner/dashboard').
    then(setData).
    catch((requestError) => setError(requestError.message)).
    finally(() => setLoading(false));
  }, [user]);

  if (!user) return <Navigate to="/login" replace />;
  if (user.role !== 'owner') return <Navigate to={dashboardPath(user.role)} replace />;

  return (
    <OwnerPortal user={user}>
      <section className="owner-content">
        <div className="owner-heading">
          <div>
            <p className="eyebrow">{t("Owner space")}</p>
            <h1>{t("Welcome, ")}{user.name.split(' ')[0]}{t(".")}</h1>
            <p className="auth-description">{t("Here’s an overview of the homes you manage.")}</p>
          </div>
          <Link className="primary-link" to="/owner/houses/new">{t("Add a House ")}<span aria-hidden="true">{t("+")}</span></Link>
        </div>
        {loading && <p className="form-note">{t("Loading your latest information…")}</p>}
        <RequestError message={error} />
        {data &&
        <>
            <div className="owner-stat-grid">
              <OwnerStat label={t("Total houses")} value={data.counts.houses} detail="Registered to your account" />
              <OwnerStat label={t("Available")} value={data.counts.available_houses} detail="Currently marked available" />
              <OwnerStat label={t("Awaiting verification")} value={data.counts.awaiting_verification} detail="Not yet verified" />
              <OwnerStat label={t("Pending rental requests")} value={data.counts.pending_rental_requests} detail="Requests awaiting a response" />
            </div>
            <div className="owner-summary-row">
              <div className="owner-summary-card">
                <p className="eyebrow">{t("Proposed subscription")}</p>
                <strong>{Number(data.owner_subscription_monthly_tnd).toFixed(3)}{t(" TND")}</strong>
                <span>{t("per registered house / month")}</span>
              </div>
              <Link className="owner-summary-card owner-summary-link" to="/owner/subscriptions">
                <p className="eyebrow">{t("Subscription status")}</p>
                {data.subscriptions.length ?
              <strong>{data.subscriptions.length}{t(" recorded")}</strong> :
              <strong>{t("No subscription records")}</strong>}
                <span>{t("No payment is processed by this platform yet.")}</span>
              </Link>
            </div>
          </>
        }
      </section>
    </OwnerPortal>);

}

function OwnerStat({ label, value, detail }) {
  return (
    <article className="owner-stat">
      <span>{t(label)}</span>
      <strong>{value}</strong>
      <small>{detail}</small>
    </article>);

}

function OwnerHousesPage() {
  const [houses, setHouses] = useState([]);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    apiRequest('/api/owner/houses').
    then(({ houses: ownerHouses }) => setHouses(ownerHouses)).
    catch((requestError) => setError(requestError.message)).
    finally(() => setLoading(false));
  }, []);

  return (
    <section className="owner-content">
      <div className="owner-heading">
        <div><p className="eyebrow">{t("Property management")}</p><h1>{t("My Houses")}</h1></div>
        <Link className="primary-link" to="/owner/houses/new">{t("Add a House ")}<span aria-hidden="true">{t("+")}</span></Link>
      </div>
      {loading && <p className="form-note">{t("Loading your houses…")}</p>}
      <RequestError message={error} />
      {!loading && houses.length > 0 &&
      <div className="worker-approval-note">
          <strong>{t("Your house submissions and their review status")}</strong>
          <span>{t("New houses stay here while the team reviews them. Accepted houses are published at the final price set by the administrator.")}</span>
        </div>
      }
      {!loading && !error && houses.length === 0 &&
      <div className="owner-empty-state">
          <span className="empty-house-mark" aria-hidden="true">{t("⌂")}</span>
          <h2>{t("Your first house starts here")}</h2>
          <p>{t("Add a house to share its details and begin the verification process.")}</p>
          <Link className="primary-link" to="/owner/houses/new">{t("Add your first house ")}<span aria-hidden="true">{t("→")}</span></Link>
        </div>
      }
      <div className="house-card-grid">
        {houses.map((house) =>
        <article className="house-card" key={house.id}>
            {house.photos[0] ?
          <img className="house-card-image" src={house.photos[0].url} alt={`Photo of ${house.title}`} /> :

          <div className="house-card-image house-image-placeholder" aria-hidden="true">{t("⌂")}</div>
          }
            <div className="house-card-content">
              <div className="house-card-heading">
                <h2>{house.title}</h2>
                <VerificationBadge status={house.verification_status} />
              </div>
              <p>{house.city}{t(", ")}{house.governorate}</p>
              <div className="house-card-meta">
                <span>{t("Owner request: ")}{Number(house.monthly_rent_tnd).toFixed(3)}{t(" TND / month")}</span>
                {house.public_listing_price_tnd !== null && <span>{t("Public price: ")}{Number(house.public_listing_price_tnd).toFixed(3)}{t(" TND / month")}</span>}
                <span>{house.bedrooms} {t(house.bedrooms === 1 ? 'bedroom' : 'bedrooms')}</span>
              </div>
              <div className="house-card-actions">
                <Link to={`/owner/houses/${house.id}`}>{t("View details")}</Link>
                <Link to={`/owner/houses/${house.id}/edit`}>{t("Edit")}</Link>
              </div>
            </div>
          </article>
        )}
      </div>
    </section>);

}

function VerificationBadge({ status }) {
  const labels = {
    verified: 'Verified',
    rejected: 'Submission rejected',
    pending_negotiation: 'Awaiting admin review',
    negotiation_in_progress: 'Negotiation in progress',
    pending_verification: 'Price agreed · inspection pending',
    inspection_assigned: 'Inspection assigned',
    inspection_completed: 'Awaiting admin decision',
    corrections_required: 'Corrections required',
    owner_accepted: 'Published · owner accepted',
    owner_refused: 'Published · price adjusted',
    admin_priced: 'Published'
  };
  const label = t(labels[status] || 'Awaiting verification');

  return <span className={`verification-badge status-${status}`}>{label}</span>;
}

function HouseDetailsPage() {
  const { houseId } = useParams();
  const [house, setHouse] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    apiRequest(`/api/owner/houses/${houseId}`).
    then(({ house: ownerHouse }) => setHouse(ownerHouse)).
    catch((requestError) => setError(requestError.message)).
    finally(() => setLoading(false));
  }, [houseId]);

  return (
    <section className="owner-content">
      <div className="owner-heading">
        <div><p className="eyebrow">{t("My Houses")}</p><h1>{t("House details")}</h1></div>
        {house && <Link className="primary-link" to={`/owner/houses/${house.id}/edit`}>{t("Edit details")}</Link>}
      </div>
      {loading && <p className="form-note">{t("Loading house details…")}</p>}
      <RequestError message={error} />
      {house &&
      <article className="house-details-card">
          <div className="house-card-heading"><h2>{house.title}</h2><VerificationBadge status={house.verification_status} /></div>
          <p className="house-detail-location">{house.city}{t(", ")}{house.governorate}{house.address ? ` · ${house.address}` : ''}</p>
          <p>{house.description || t('No description provided.')}</p>
          <dl className="house-detail-list">
            <div><dt>{t("Original requested rent")}</dt><dd>{Number(house.monthly_rent_tnd).toFixed(3)}{t(" TND")}</dd></div>
            {house.agreed_owner_rent_tnd !== null && <div><dt>{t("Agreed owner rent")}</dt><dd>{Number(house.agreed_owner_rent_tnd).toFixed(3)}{t(" TND")}</dd></div>}
            {house.public_listing_price_tnd !== null && <div><dt>{t("Public listing price")}</dt><dd>{Number(house.public_listing_price_tnd).toFixed(3)}{t(" TND / month")}</dd></div>}
            <div><dt>{t("Bedrooms")}</dt><dd>{house.bedrooms}</dd></div>
            <div><dt>{t("Furnishing")}</dt><dd>{t(house.furnishing_status.replaceAll('_', ' '))}</dd></div>
            <div><dt>{t("Availability")}</dt><dd>{t(house.available ? 'Available' : 'Unavailable')}</dd></div>
          </dl>
          {house.photos.length > 0 &&
        <div className="house-photo-grid">
              {house.photos.map((photo) => <img key={photo.id} src={photo.url} alt={photo.caption || `Photo of ${house.title}`} />)}
            </div>
        }
        </article>
      }
    </section>);

}

function HouseFormPage() {
  const { houseId } = useParams();
  const editing = Boolean(houseId);
  const navigate = useNavigate();
  const [form, setForm] = useState({
    title: '', description: '', governorate: '', city: '', address: '',
    monthly_rent_tnd: '', bedrooms: '1', furnishing_status: 'unknown', available: '1'
  });
  const [existingHouse, setExistingHouse] = useState(null);
  const [photos, setPhotos] = useState([]);
  const [errors, setErrors] = useState({});
  const [requestError, setRequestError] = useState('');
  const [loading, setLoading] = useState(editing);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!editing) return;
    apiRequest(`/api/owner/houses/${houseId}`).
    then(({ house }) => {
      setExistingHouse(house);
      setForm({
        title: house.title,
        description: house.description ?? '',
        governorate: house.governorate,
        city: house.city,
        address: house.address ?? '',
        monthly_rent_tnd: house.monthly_rent_tnd,
        bedrooms: String(house.bedrooms),
        furnishing_status: house.furnishing_status,
        available: house.available ? '1' : '0'
      });
    }).
    catch((error) => setRequestError(error.message)).
    finally(() => setLoading(false));
  }, [editing, houseId]);

  function update(event) {
    setForm((current) => ({ ...current, [event.target.name]: event.target.value }));
    setErrors((current) => ({ ...current, [event.target.name]: undefined }));
    setRequestError('');
  }

  async function submit(event) {
    event.preventDefault();
    setSubmitting(true);
    setErrors({});
    setRequestError('');
    if (!editing && photos.length < 4) {
      setErrors({ photos: ['Choose at least four house photos before submitting.'] });
      setSubmitting(false);
      return;
    }
    if (!editing && photos.length > 8) {
      setErrors({ photos: ['Choose no more than eight house photos.'] });
      setSubmitting(false);
      return;
    }

    const data = new FormData();
    Object.entries(form).forEach(([key, value]) => data.append(key, value));
    photos.forEach((photo) => data.append('photos[]', photo));
    const path = editing ? `/api/owner/houses/${houseId}` : '/api/owner/houses';
    if (editing) data.append('_method', 'PUT');

    try {
      await apiRequest(path, { method: 'POST', body: data });
      navigate('/owner/houses', { replace: true });
    } catch (error) {
      setErrors(error.errors ?? {});
      setRequestError(error.errors && Object.keys(error.errors).length ? '' : error.message);
    } finally {
      setSubmitting(false);
    }
  }

  if (loading) {
    return <section className="owner-content"><p className="form-note">{t("Loading house details…")}</p></section>;
  }

  return (
    <section className="owner-content">
      <div className="owner-heading">
        <div><p className="eyebrow">{t("Property management")}</p><h1>{editing ? 'Edit House' : 'Add a House'}</h1></div>
        <Link className="secondary-link" to="/owner/houses">{t("Back to My Houses")}</Link>
      </div>
      <RequestError message={requestError} />
      {existingHouse &&
      <div className="verification-notice">{t("\n          Verification status: ")}
        <VerificationBadge status={existingHouse.verification_status} />
          <span>{t("Owners cannot mark a house as verified.")}</span>
        </div>
      }
      <form className="house-form-card" onSubmit={submit} noValidate>
        <FormField label={t("House title")} name="title" value={form.title} onChange={update} errors={errors} />
        <div className="form-field">
          <label htmlFor="description">{t("Description")}</label>
          <textarea id="description" name="description" rows="4" value={form.description} onChange={update} aria-invalid={Boolean(errors.description?.length)} />
          <FieldError errors={errors} name="description" />
        </div>
        <div className="field-row">
          <FormField label={t("Governorate")} name="governorate" value={form.governorate} onChange={update} errors={errors} />
          <FormField label={t("City")} name="city" value={form.city} onChange={update} errors={errors} />
        </div>
        <FormField label={t("Address or approximate location (optional)")} name="address" value={form.address} onChange={update} errors={errors} />
        <div className="field-row">
          <FormField label={t("Original requested monthly rent (TND)")} name="monthly_rent_tnd" type="number" value={form.monthly_rent_tnd} onChange={update} errors={errors} />
          <FormField label={t("Bedrooms")} name="bedrooms" type="number" value={form.bedrooms} onChange={update} errors={errors} />
        </div>
        <div className="field-row">
          <SelectField label={t("Furnishing status")} name="furnishing_status" value={form.furnishing_status} onChange={update} errors={errors} options={[
          ['unknown', 'Not specified'], ['furnished', 'Furnished'], ['unfurnished', 'Unfurnished'], ['partially_furnished', 'Partially furnished']]
          } />
          <SelectField label={t("Availability")} name="available" value={form.available} onChange={update} errors={errors} options={[
          ['1', 'Available'], ['0', 'Unavailable']]
          } />
        </div>
        <div className="form-field">
          <label htmlFor="photos">{t("House photos (")}{editing ? 'up to 8 total' : '4 to 8 required'}{t(", JPG/PNG/WebP, 5 MB each)")}</label>
          {!editing && <p className="form-note">{t("Selected: ")}{photos.length}{t(" / 8. At least four photos are required to submit your house for review.")}</p>}
          {existingHouse?.photos.length > 0 && <p className="form-note">{existingHouse.photos.length}{t(" photo(s) already uploaded.")}</p>}
          <input id="photos" name="photos" type="file" accept="image/jpeg,image/png,image/webp" multiple onChange={(event) => {
            setPhotos(Array.from(event.target.files ?? []));
            setErrors((current) => ({ ...current, photos: undefined, 'photos.0': undefined }));
          }} aria-invalid={Boolean(errors.photos?.length || errors['photos.0']?.length)} />
          <PhotoPreviews files={photos} existing={existingHouse?.photos ?? []} />
          <FieldError errors={errors} name="photos" />
          <FieldError errors={errors} name="photos.0" />
        </div>
        {existingHouse && <p className="form-note">{t("Saving keeps the current review status. New photos are added to existing photos.")}</p>}
        {!editing && <p className="form-note house-pricing-policy">{t("An administrator will call with a 5% rent-reduction suggestion. If you accept, your rent becomes 95% of your request and students see your original price. If you refuse, your rent stays the same and the public price increases by 5%, rounded to the nearest 10 TND.")}</p>}
        <button className="primary-button" type="submit" disabled={submitting}>
          {submitting ? 'Saving house…' : editing ? 'Save changes' : 'Submit house for admin review'}
          <span aria-hidden="true">{t("→")}</span>
        </button>
      </form>
    </section>);

}

function SelectField({ label, name, value, onChange, errors, options }) {
  return (
    <div className="form-field">
      <label htmlFor={name}>{label}</label>
      <select id={name} name={name} value={value} onChange={onChange} aria-invalid={Boolean(errors[name]?.length)} required>
        {options.map(([optionValue, optionLabel]) => <option key={optionValue} value={optionValue}>{t(optionLabel)}</option>)}
      </select>
      <FieldError errors={errors} name={name} />
    </div>);

}

function OwnerSubscriptionsPage() {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    apiRequest('/api/owner/dashboard').
    then(setData).
    catch((requestError) => setError(requestError.message)).
    finally(() => setLoading(false));
  }, []);

  return (
    <section className="owner-content">
      <div className="owner-heading"><div><p className="eyebrow">{t("Billing information")}</p><h1>{t("Subscription Information")}</h1></div></div>
      {loading && <p className="form-note">{t("Loading subscription information…")}</p>}
      <RequestError message={error} />
      {data &&
      <>
          <article className="subscription-price-card">
            <p className="eyebrow">{t("Proposed owner subscription")}</p>
            <strong>{Number(data.owner_subscription_monthly_tnd).toFixed(3)}{t(" TND")}</strong>
            <span>{t("per registered house per month")}</span>
            <p>{t("Current total for your ")}{data.subscriptions.length}{t(" subscribed houses: ")}<strong>{Number(data.monthly_subscription_total_tnd).toFixed(3)}{t(" TND / month")}</strong>{t(". No payment provider is configured, so no money is collected.")}</p>
          </article>
          <h2 className="section-subheading">{t("Recorded subscriptions")}</h2>
          {data.subscriptions.length === 0 ?
        <div className="owner-empty-state compact-empty"><p>{t("No subscription records exist yet.")}</p></div> :

        <div className="subscription-list">
              {data.subscriptions.map((subscription) =>
          <article className="subscription-row" key={subscription.id}>
                  <div><strong>{subscription.house?.title ?? 'House'}</strong><span>{Number(subscription.monthly_price_tnd).toFixed(3)}{t(" TND / month")}</span></div>
                  <div>                  <span>{t("Status: ")}{t(subscription.status.replaceAll('_', ' '))}</span><span>{t("Last payment: ")}{t(subscription.payment_status.replaceAll('_', ' '))}</span></div>
                  <div><span>{t("Next renewal: ")}{subscription.next_renewal_on ?? 'Not scheduled'}</span></div>
                  <PaymentHistory payments={subscription.payments} />
                  {subscription.payment_status !== 'paid' &&
            <PreparePaymentButton
              path={`/api/owner/subscriptions/${subscription.id}/payment`}
              label={t("Prepare subscription payment")} />

            }
                </article>
          )}
            </div>
        }
        </>
      }
    </section>);

}

function OwnerMaintenancePage() {
  const [requests, setRequests] = useState([]);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [refresh, setRefresh] = useState(0);

  useEffect(() => {
    apiRequest('/api/owner/maintenance-requests').
    then(({ maintenance_requests: items }) => setRequests(items)).
    catch((requestError) => setError(requestError.message)).
    finally(() => setLoading(false));
  }, [refresh]);

  return (
    <section className="owner-content">
      <div className="owner-heading">
        <div><p className="eyebrow">{t("Property care")}</p><h1>{t("Maintenance requests")}</h1><p className="auth-description">{t("Review tenant reports and confirm costs before repair work proceeds.")}</p></div>
      </div>
      <div className="fee-notice"><strong>{t("Repair costs are separate from the house subscription.")}</strong><span>{t("The platform does not promise coverage or charge you automatically. Review each estimate and responsibility before approving it.")}</span></div>
      {loading && <p className="form-note">{t("Loading maintenance requests…")}</p>}
      <RequestError message={error} />
      {!loading && !error && requests.length === 0 && <EmptyState title={t("No maintenance requests")} description={t("Tenant reports about your houses will appear here.")} />}
      <div className="maintenance-request-list">
        {requests.map((item) => <OwnerMaintenanceCard key={item.id} item={item} onChanged={() => setRefresh((value) => value + 1)} />)}
      </div>
    </section>);

}

function OwnerMaintenanceCard({ item, onChanged }) {
  const [note, setNote] = useState('');
  const [dispute, setDispute] = useState('');
  const [covered, setCovered] = useState(item.covered_by_service === true);
  const [responsibility, setResponsibility] = useState(item.cost_responsibility || 'undetermined');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  async function send(path, body) {
    setBusy(true);
    setError('');
    setErrors({});
    setNotice('');
    try {
      const result = await apiRequest(path, { method: 'POST', body: JSON.stringify(body) });
      setNotice(result.message);
      setNote('');
      setDispute('');
      onChanged();
    } catch (requestError) {
      setErrors(requestError.errors ?? {});
      setError(requestError.message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <MaintenanceRequestCard item={item} audience="owner">
      {item.status === 'awaiting_owner_response' && item.estimated_repair_cost_tnd === null &&
      <button className="primary-button" type="button" disabled={busy} onClick={() => send(`/api/owner/maintenance-requests/${item.id}/acknowledge`, {})}>{t("Confirm receipt")}</button>
      }
      {item.status === 'reported' && <p className="student-muted">{t("You confirmed receipt. An administrator can assign a worker for this governorate.")}</p>}
      {item.assigned_worker && <p className="student-muted">{t("Assigned worker: ")}<strong>{item.assigned_worker.name}</strong></p>}
      {item.status === 'awaiting_owner_response' && item.estimated_repair_cost_tnd !== null &&
      <form className="maintenance-owner-form" onSubmit={(event) => {event.preventDefault();send(`/api/owner/maintenance-requests/${item.id}/estimate-decision`, { decision: 'approve', covered_by_service: covered, cost_responsibility: responsibility, note });}}>
          <strong>{t("Estimate awaiting your review: ")}{Number(item.estimated_repair_cost_tnd).toFixed(3)}{t(" TND")}</strong>
          <label className="checkbox-field"><input type="checkbox" checked={covered} onChange={(event) => setCovered(event.target.checked)} />{t(" Covered by the agreed service policy")}</label>
          <label className="form-field"><span>{t("Who should pay?")}</span>
            <select value={responsibility} onChange={(event) => setResponsibility(event.target.value)}>
              <option value="undetermined">{t("Not yet determined")}</option><option value="owner">{t("Owner")}</option><option value="student">{t("Student")}</option><option value="platform">{t("Platform")}</option><option value="shared">{t("Shared")}</option>
            </select>
          </label>
          <label className="form-field"><span>{t("Optional note")}</span><textarea value={note} onChange={(event) => setNote(event.target.value)} maxLength="5000" /></label>
          <div className="maintenance-actions">
            <button className="primary-button" type="submit" disabled={busy}>{t("Approve estimate")}</button>
            <button className="secondary-link" type="button" disabled={busy} onClick={() => send(`/api/owner/maintenance-requests/${item.id}/estimate-decision`, { decision: 'reject', covered_by_service: covered, cost_responsibility: responsibility, note })}>{t("Do not approve")}</button>
          </div>
        </form>
      }
      {item.status === 'awaiting_confirmation' &&
      <div className="maintenance-owner-form">
          <label className="form-field"><span>{t("If work is not complete, explain what remains")}</span><textarea value={dispute} onChange={(event) => setDispute(event.target.value)} minLength="5" maxLength="5000" /></label>
          <div className="maintenance-actions">
            <button className="primary-button" type="button" disabled={busy} onClick={() => send(`/api/owner/maintenance-requests/${item.id}/confirm-completion`, {})}>{t("Confirm repair completed")}</button>
            <button className="secondary-link" type="button" disabled={busy || dispute.trim().length < 5} onClick={() => send(`/api/owner/maintenance-requests/${item.id}/dispute-completion`, { note: dispute })}>{t("Dispute completion")}</button>
          </div>
        </div>
      }
      {!['completed', 'rejected_with_explanation'].includes(item.status) && item.status !== 'awaiting_owner_response' &&
      <form className="maintenance-owner-form" onSubmit={(event) => {event.preventDefault();send(`/api/owner/maintenance-requests/${item.id}/notes`, { note });}}>
          <label className="form-field"><span>{t("Send a response or note")}</span><textarea required minLength="2" maxLength="5000" value={note} onChange={(event) => setNote(event.target.value)} /></label>
          <button className="secondary-link" type="submit" disabled={busy || note.trim().length < 2}>{t("Add note")}</button>
        </form>
      }
      {notice && <p className="notice success-notice" role="status">{notice}</p>}
      <RequestError message={error} />
    </MaintenanceRequestCard>);

}

function OwnerPlaceholder({ title, description }) {
  return (
    <section className="owner-content">
      <div className="owner-heading"><div><p className="eyebrow">{t("Owner space")}</p><h1>{title}</h1></div></div>
      <div className="owner-empty-state compact-empty">
        <h2>{t("Coming in a later step")}</h2>
        <p>{description}</p>
      </div>
    </section>);

}

const maintenanceStatusLabels = {
  awaiting_owner_response: 'Awaiting owner response',
  reported: 'Reported',
  assigned: 'Assigned',
  visit_scheduled: 'Visit scheduled',
  in_progress: 'In progress',
  awaiting_confirmation: 'Awaiting owner confirmation',
  completed: 'Completed',
  rejected_with_explanation: 'Rejected with explanation'
};

const maintenanceCategoryLabels = {
  plumbing: 'Plumbing',
  electricity: 'Electricity',
  furniture: 'Furniture',
  doors_windows: 'Doors and windows',
  appliances: 'Appliances',
  structural_damage: 'Structural damage',
  other: 'Other'
};

function MaintenanceRequestCard({ item, audience, children }) {
  return (
    <article className="maintenance-request-card">
      <div className="maintenance-request-heading">
        <div>
          <p className="eyebrow">{item.house?.title || 'Rented house'}{t(" · ")}{item.house?.city}{t(", ")}{item.house?.governorate}</p>
          <h2>{t(maintenanceCategoryLabels[item.category] || item.category)}</h2>
        </div>
        <span className={`request-status status-${item.status}`}>{t(maintenanceStatusLabels[item.status] || item.status.replaceAll('_', ' '))}</span>
      </div>
      <p className="maintenance-description">{item.description}</p>
      <div className="maintenance-facts">
        <span className={`priority-label priority-${item.priority}`}>{t(item.priority)}{t(" priority")}</span>
        <span>{t("Reported ")}{formatDate(item.reported_at)}</span>
        {item.student && <span>{t("Tenant: ")}{item.student.name}{t(" · ")}{item.student.phone || item.student.email}</span>}
        {item.assigned_worker && <span>{t("Worker: ")}{item.assigned_worker.name}</span>}
        {item.estimated_repair_cost_tnd !== null && <span>{t("Estimate: ")}{Number(item.estimated_repair_cost_tnd).toFixed(3)}{t(" TND")}</span>}
        {item.repair_cost_tnd !== null && <span>{t("Actual cost: ")}{Number(item.repair_cost_tnd).toFixed(3)}{t(" TND")}</span>}
        {item.covered_by_service !== null && <span>{t("Service coverage: ")}{t(item.covered_by_service ? 'Covered' : 'Not covered')}</span>}
        {item.cost_responsibility && <span>{t("Payment responsibility: ")}{t(item.cost_responsibility)}</span>}
        {item.owner_approved_at && <span>{t("Estimate approved by owner")}</span>}
        {item.resolved_at && <span>{t("Repair submitted ")}{formatDate(item.resolved_at)}</span>}
      </div>
      {item.rejection_reason && <p className="maintenance-rejection"><strong>{t("Reason:")}</strong> {item.rejection_reason}</p>}
      {item.repair_outcome && <p className="maintenance-outcome"><strong>{t("Repair outcome:")}</strong> {item.repair_outcome}</p>}
      {item.photos.length > 0 &&
      <div className="maintenance-photo-grid">
          {item.photos.map((photo) => <a key={photo.id} href={photo.url} target="_blank" rel="noreferrer"><img src={photo.url} alt={`${t(photo.type === 'evidence' ? 'Repair evidence' : 'Reported issue')} ${t('for')} ${item.house?.title || t('rented house')}`} /><span>{t(photo.type === 'evidence' ? 'Repair evidence' : 'Reported issue')}</span></a>)}
        </div>
      }
      {item.visits.length > 0 &&
      <section className="maintenance-timeline">
          <h3>{t("Visits and work notes")}</h3>
          {item.visits.map((visit, index) =>
        <article key={`${visit.visited_at || visit.scheduled_at}-${index}`}>
              <strong>{visit.visited_at ? `${t('Visited')} ${formatDate(visit.visited_at)}` : `${t('Scheduled')} ${formatDate(visit.scheduled_at)}`}{visit.worker ? ` · ${visit.worker}` : ''}</strong>
              {visit.notes && <p>{visit.notes}</p>}
            </article>
        )}
        </section>
      }
      {item.updates.length > 0 &&
      <section className="maintenance-timeline">
          <h3>{t("Request history")}</h3>
          {item.updates.map((update, index) =>
        <article key={`${update.created_at}-${index}`}>
              <strong>{update.actor || 'System'}{t(" · ")}{formatDate(update.created_at)}</strong>
              <p>{update.body || t(update.event_type.replaceAll('_', ' '))}</p>
            </article>
        )}
        </section>
      }
      <div className="maintenance-card-actions">{children}</div>
    </article>);

}

function WorkerMaintenancePage() {
  const [requests, setRequests] = useState([]);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [refresh, setRefresh] = useState(0);

  useEffect(() => {
    apiRequest('/api/worker/maintenance-requests').
    then(({ maintenance_requests: items }) => setRequests(items)).
    catch((requestError) => setError(requestError.message)).
    finally(() => setLoading(false));
  }, [refresh]);

  return (
    <section className="owner-content">
      <div className="owner-heading"><div><p className="eyebrow">{t("Assigned service work")}</p><h1>{t("Maintenance requests")}</h1><p className="auth-description">{t("Only requests assigned to you in your authorized governorate are shown.")}</p></div></div>
      {loading && <p className="form-note">{t("Loading assigned maintenance…")}</p>}
      <RequestError message={error} />
      {!loading && !error && requests.length === 0 && <EmptyState title={t("No assigned maintenance requests")} description={t("An administrator assigns requests to workers authorized for the house governorate.")} />}
      <div className="maintenance-request-list">
        {requests.map((item) => <WorkerMaintenanceCard key={item.id} item={item} onChanged={() => setRefresh((value) => value + 1)} />)}
      </div>
    </section>);

}

function WorkerMaintenanceCard({ item, onChanged }) {
  const [action, setAction] = useState(item.status === 'assigned' ? 'schedule_visit' : 'start_work');
  const [notes, setNotes] = useState('');
  const [scheduledAt, setScheduledAt] = useState('');
  const [estimatedCost, setEstimatedCost] = useState('');
  const [actualCost, setActualCost] = useState('');
  const [covered, setCovered] = useState(item.covered_by_service ?? false);
  const [responsibility, setResponsibility] = useState(item.cost_responsibility || 'undetermined');
  const [outcome, setOutcome] = useState('');
  const [photos, setPhotos] = useState([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  async function submit(event) {
    event.preventDefault();
    setBusy(true);
    setError('');
    setNotice('');
    const body = new FormData();
    body.set('action', action);
    if (notes) body.set('notes', notes);
    if (scheduledAt) body.set('scheduled_at', new Date(scheduledAt).toISOString());
    if (estimatedCost !== '') body.set('estimated_repair_cost_tnd', estimatedCost);
    if (actualCost !== '') body.set('actual_repair_cost_tnd', actualCost);
    if (action === 'submit_estimate') {
      body.set('covered_by_service', covered ? '1' : '0');
      body.set('cost_responsibility', responsibility);
    }
    if (outcome) body.set('repair_outcome', outcome);
    photos.forEach((photo) => body.append('photos[]', photo));

    try {
      const result = await apiRequest(`/api/worker/maintenance-requests/${item.id}/updates`, { method: 'POST', body });
      setNotice(result.message);
      setNotes('');
      setPhotos([]);
      onChanged();
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setBusy(false);
    }
  }

  const canSchedule = ['assigned', 'visit_scheduled'].includes(item.status);
  const hasRecordedVisit = item.visits?.some((visit) => visit.visited_at);
  const canRecordVisit = ['assigned', 'visit_scheduled'].includes(item.status);
  const canStartWork = ['assigned', 'visit_scheduled'].includes(item.status) &&
  hasRecordedVisit && (
  !item.owner_approval_required || item.estimated_repair_cost_tnd !== null && item.owner_approved_at);
  const canEstimate = ['assigned', 'visit_scheduled', 'in_progress'].includes(item.status);
  const canSubmit = item.status === 'in_progress';

  const actionable = ['assigned', 'visit_scheduled', 'in_progress'].includes(item.status);

  return (
    <MaintenanceRequestCard item={item} audience="worker">
      {actionable &&
      <form className="maintenance-worker-form" onSubmit={submit}>
          <label className="form-field"><span>{t("Update")}</span>
            <select value={action} onChange={(event) => setAction(event.target.value)}>
              {canSchedule && <option value="schedule_visit">{t("Schedule a visit")}</option>}
              {canRecordVisit && <option value="record_visit">{t("Record completed visit")}</option>}
              {canStartWork && <option value="start_work">{t("Start approved repair")}</option>}
              {canEstimate && <option value="submit_estimate">{t("Submit or update cost estimate")}</option>}
              {canSubmit && <option value="submit_for_confirmation">{t("Submit repair for owner confirmation")}</option>}
              {['assigned', 'visit_scheduled', 'in_progress'].includes(item.status) && <option value="reject">{t("Reject with explanation")}</option>}
            </select>
          </label>
          {action === 'schedule_visit' && <label className="form-field"><span>{t("Visit date and time")}</span><input type="datetime-local" required value={scheduledAt} onChange={(event) => setScheduledAt(event.target.value)} /></label>}
          {action === 'submit_estimate' &&
        <>
              <label className="form-field"><span>{t("Estimated repair cost (TND)")}</span><input type="number" min="0" step="0.001" required value={estimatedCost} onChange={(event) => setEstimatedCost(event.target.value)} /></label>
              <label className="checkbox-field"><input type="checkbox" checked={covered} onChange={(event) => setCovered(event.target.checked)} />{t(" Proposed as covered by the agreed service policy")}</label>
              <label className="form-field"><span>{t("Proposed payment responsibility")}</span>
                <select value={responsibility} onChange={(event) => setResponsibility(event.target.value)}>
                  <option value="undetermined">{t("Not yet determined")}</option><option value="owner">{t("Owner")}</option><option value="student">{t("Student")}</option><option value="platform">{t("Platform")}</option><option value="shared">{t("Shared")}</option>
                </select>
              </label>
            </>
        }
          {action === 'submit_for_confirmation' &&
        <>
              <label className="form-field"><span>{t("Actual repair cost (TND), if known")}</span><input type="number" min="0" step="0.001" value={actualCost} onChange={(event) => setActualCost(event.target.value)} /></label>
              <label className="form-field"><span>{t("Repair outcome")}</span><textarea required minLength="3" value={outcome} maxLength="5000" onChange={(event) => setOutcome(event.target.value)} /></label>
              <label className="form-field"><span>{t("Evidence photos (JPG/PNG/WebP, 5 MB each)")}</span><input type="file" accept="image/jpeg,image/png,image/webp" multiple onChange={(event) => setPhotos(Array.from(event.target.files ?? []))} /><PhotoPreviews files={photos} /></label>
            </>
        }
          {!['schedule_visit'].includes(action) && <label className="form-field"><span>{action === 'reject' ? 'Reason (required)' : action === 'record_visit' ? 'Visit findings' : 'Visit or repair notes'}</span><textarea required minLength="3" maxLength="10000" value={notes} onChange={(event) => setNotes(event.target.value)} /></label>}
          {action !== 'submit_for_confirmation' && <label className="form-field"><span>{t("Evidence photos (optional)")}</span><input type="file" accept="image/jpeg,image/png,image/webp" multiple onChange={(event) => setPhotos(Array.from(event.target.files ?? []))} /><PhotoPreviews files={photos} /></label>}
          <button className="primary-button" type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save maintenance update'}</button>
        </form>
      }
      {item.status === 'awaiting_owner_response' && <p className="student-muted">{t("Waiting for the owner to review the estimate or respond before more work can continue.")}</p>}
      {notice && <p className="notice success-notice" role="status">{notice}</p>}
      <RequestError message={error} />
    </MaintenanceRequestCard>);

}

const workerNavigation = [
{ to: '/worker/dashboard', label: 'Overview' },
{ to: '/worker/inspections', label: 'Inspections' },
{ to: '/worker/maintenance-requests', label: 'Maintenance' }];


const inspectionChecklist = [
['description_match', 'Does the house match its advertised description?'],
['electricity', 'Is the electricity functioning?'],
['water', 'Is the water supply functioning?'],
['doors_windows', 'Are doors and windows in acceptable condition?'],
['bathroom_kitchen', 'Are the bathroom and kitchen usable?'],
['furniture', 'Is advertised furniture present and in acceptable condition?'],
['leaks_damage', 'Are there visible signs of leaks or damage?'],
['rooms_facilities', 'Are the advertised rooms and facilities present?']];


function WorkerPortal({ user, children }) {
  if (!user) return <Navigate to="/login" replace />;
  if (user.role !== 'worker') return <Navigate to={dashboardPath(user.role)} replace />;

  return (
    <div className="owner-portal">
      <nav className="owner-nav" aria-label={t("Worker dashboard")}>
        {workerNavigation.map((item) =>
        <NavLink key={item.to} to={item.to} end={item.to !== '/worker/inspections'} className="owner-nav-link">{t(item.label)}</NavLink>
        )}
      </nav>
      {children}
    </div>);

}

function WorkerDashboard({ user }) {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!user) return;
    apiRequest('/api/worker/dashboard').
    then(setData).
    catch((requestError) => setError(requestError.message)).
    finally(() => setLoading(false));
  }, [user]);

  if (!user) return <Navigate to="/login" replace />;
  if (user.role !== 'worker') return <Navigate to={dashboardPath(user.role)} replace />;

  return (
    <WorkerPortal user={user}>
      <section className="owner-content">
        <div className="owner-heading">
          <div>
            <p className="eyebrow">{t("Worker space")}{user.governorate ? ` · ${user.governorate}` : ''}</p>
            <h1>{t("Welcome, ")}{user.name.split(' ')[0]}{t(".")}</h1>
            <p className="auth-description">{t("Review your assigned property visits and inspection reports.")}</p>
          </div>
          <Link className="primary-link" to="/worker/inspections">{t("My Inspections ")}<span aria-hidden="true">{t("→")}</span></Link>
        </div>
        {loading && <p className="form-note">{t("Loading your assigned work…")}</p>}
        <RequestError message={error} />
        {data &&
        <div className="owner-stat-grid worker-stat-grid">
            <OwnerStat label={t("Assigned inspections")} value={data.counts.assigned_inspections} detail="All inspections assigned to you" />
            <OwnerStat label={t("Pending inspections")} value={data.counts.pending_inspections} detail="Awaiting your visit and report" />
            <OwnerStat label={t("Completed inspections")} value={data.counts.completed_inspections} detail="Submitted for administrator review" />
            <OwnerStat label={t("Houses awaiting verification")} value={data.counts.houses_awaiting_verification} detail={`In ${user.governorate || 'your service area'}`} />
            <OwnerStat label={t("Maintenance requests")} value={data.counts.assigned_maintenance_requests} detail="Assigned to you" />
          </div>
        }
        <div className="worker-approval-note">
          <strong>{t("Verification decisions")}</strong>
          <span>{t("Inspection reports are reviewed by an authorized administrator. Submitting a report does not verify a house.")}</span>
        </div>
      </section>
    </WorkerPortal>);

}

function AdminPriceNegotiationsPage({ user }) {
  const [houses, setHouses] = useState([]);
  const [listingMargin, setListingMargin] = useState('20.000');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (user?.role !== 'admin') return;
    apiRequest('/api/admin/price-negotiations').
    then(({ houses: pendingHouses, listing_margin_tnd: margin }) => {
      setHouses(pendingHouses);
      setListingMargin(margin);
    }).
    catch((requestError) => setError(requestError.message)).
    finally(() => setLoading(false));
  }, [user]);

  if (!user) return <Navigate to="/login" replace />;
  if (user.role !== 'admin') return <Navigate to={dashboardPath(user.role)} replace />;

  function updateHouse(updatedHouse) {
    setHouses((current) => current.map((house) =>
    house.id === updatedHouse.id ? updatedHouse : house
    ));
  }

  return (
    <div className="owner-portal">
      <nav className="owner-nav" aria-label={t("Administrator dashboard")}>
        <NavLink className="owner-nav-link" to="/admin/house-approvals">{t("Inspection reviews")}</NavLink>
        <NavLink className="owner-nav-link" to="/admin/price-negotiations">{t("Submissions & pricing")}</NavLink>
      </nav>
      <section className="owner-content">
        <div className="owner-heading">
          <div>
            <p className="eyebrow">{t("Internal staff · private")}</p>
            <h1>{t("House submissions")}</h1>
            <p className="auth-description"><strong>{houses.filter((house) => house.negotiation_status === 'pending').length}</strong>{t(" submissions need review. Call the owner, record their answer, and the public price is calculated automatically.")}</p>
          </div>
        </div>
        {loading && <p className="form-note">{t("Loading pending houses…")}</p>}
        <RequestError message={error} />
        {!loading && !error && houses.length === 0 &&
        <EmptyState title={t("No houses awaiting review")} description={t("New owner submissions will appear here until their price and inspection workflow is complete.")} />
        }
        <div className="negotiation-list">
          {houses.map((house) =>
          <AdminNegotiationCard key={house.id} house={house} listingMargin={listingMargin} onUpdated={updateHouse} />
          )}
        </div>
      </section>
    </div>);

}

function AdminHouseApprovalsPage({ user }) {
  const [inspections, setInspections] = useState([]);
  const [pendingSubmissionCount, setPendingSubmissionCount] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (user?.role !== 'admin') return;
    Promise.all([
    apiRequest('/api/admin/inspection-reviews'),
    apiRequest('/api/admin/price-negotiations')]
    ).
    then(([{ inspections: pendingReviews }, { houses }]) => {
      setInspections(pendingReviews);
      setPendingSubmissionCount(houses.filter((house) => house.negotiation_status === 'pending').length);
    }).
    catch((requestError) => setError(requestError.message)).
    finally(() => setLoading(false));
  }, [user]);

  if (!user) return <Navigate to="/login" replace />;
  if (user.role !== 'admin') return <Navigate to={dashboardPath(user.role)} replace />;

  function removeReviewed(inspectionId) {
    setInspections((current) => current.filter((inspection) => inspection.id !== inspectionId));
  }

  return (
    <div className="owner-portal">
      <nav className="owner-nav" aria-label={t("Administrator dashboard")}>
        <NavLink className="owner-nav-link" to="/admin/house-approvals">{t("Inspection reviews")}</NavLink>
        <NavLink className="owner-nav-link" to="/admin/price-negotiations">{t("\n          Submissions & pricing")}
          {pendingSubmissionCount > 0 ? ` (${pendingSubmissionCount})` : ''}
        </NavLink>
      </nav>
      <section className="owner-content">
        <Link className="admin-pending-submissions" to="/admin/price-negotiations">
          <span>
            <strong>{t("New house submissions")}</strong>
            <span>{pendingSubmissionCount === null ?
              'Check submissions awaiting your decision.' :
              pendingSubmissionCount > 0 ?
              `${pendingSubmissionCount} submission${pendingSubmissionCount === 1 ? '' : 's'} waiting for acceptance or rejection.` :
              'No new submissions are waiting for a decision.'}</span>
          </span>
          <span className="admin-pending-action">{t("Review submissions →")}</span>
        </Link>
        <div className="owner-heading">
          <div>
            <p className="eyebrow">{t("Internal staff · private")}</p>
            <h1>{t("House listing approvals")}</h1>
            <p className="auth-description">{t("Review completed inspections, then approve, reject, or request corrections before a house is shown to students.")}</p>
          </div>
        </div>
        {loading && <p className="form-note">{t("Loading inspection reports…")}</p>}
        <RequestError message={error} />
        {!loading && !error && inspections.length === 0 &&
        <EmptyState title={t("No inspections awaiting review")} description={t("A house appears here after its negotiated price is set and a worker submits the inspection report.")} />
        }
        <div className="inspection-list">
          {inspections.map((inspection) =>
          <AdminHouseApprovalCard
            key={inspection.id}
            inspection={inspection}
            onReviewed={() => removeReviewed(inspection.id)} />

          )}
        </div>
      </section>
    </div>);

}

function AdminHouseApprovalCard({ inspection, onReviewed }) {
  const [reviewNotes, setReviewNotes] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const checklist = Object.entries(inspection.condition_checklist ?? {});

  async function decide(decision) {
    setBusy(true);
    setError('');
    setNotice('');
    try {
      const result = await apiRequest(`/api/admin/inspections/${inspection.id}/decision`, {
        method: 'POST',
        body: JSON.stringify({ decision, review_notes: reviewNotes })
      });
      setNotice(result.message);
      onReviewed();
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <article className="inspection-card admin-approval-card">
      <div className="negotiation-card-heading">
        <div>
          <p className="eyebrow">{t("House #")}{inspection.house.id}{t(" · inspection #")}{inspection.id}</p>
          <h2>{inspection.house.title}</h2>
          <p>{inspection.house.city}{t(", ")}{inspection.house.governorate}</p>
        </div>
        <VerificationBadge status="inspection_completed" />
      </div>
      <div className="negotiation-owner-contact">
        <strong>{t("Owner: ")}{inspection.owner?.name}</strong>
        <span>{inspection.owner?.phone || 'No phone number recorded'}</span>
        <span>{inspection.worker?.name ? `Inspected by ${inspection.worker.name}` : 'Worker details unavailable'}</span>
        <span>{t("Completed ")}{formatDate(inspection.completed_at)}</span>
        <strong>{inspection.house.public_listing_price_tnd === null ?
          'Listing price not available' :
          `${Number(inspection.house.public_listing_price_tnd).toFixed(3)} TND/month`}</strong>
      </div>
      <div className="approval-report">
        <div>
          <h3>{t("Worker recommendation")}</h3>
          <p className={`recommendation-${inspection.recommendation}`}>{t(inspection.recommendation.replaceAll('_', ' '))}</p>
        </div>
        <div>
          <h3>{t("Inspection notes")}</h3>
          <p>{inspection.notes || 'No notes provided.'}</p>
        </div>
        {inspection.defects_found &&
        <div>
            <h3>{t("Defects reported")}</h3>
            <p>{inspection.defects_found}</p>
          </div>
        }
      </div>
      {checklist.length > 0 &&
      <dl className="negotiation-prices">
          {checklist.map(([item, status]) =>
        <div key={item}><dt>{t(item.replaceAll('_', ' '))}</dt><dd>{t(status.replaceAll('_', ' '))}</dd></div>
        )}
        </dl>
      }
      {inspection.photos.length > 0 &&
      <div className="approval-photo-grid">
          {inspection.photos.map((photo) =>
        <a key={photo.id} href={photo.url} target="_blank" rel="noreferrer">
              <img src={photo.url} alt={photo.caption || `Inspection photo for ${inspection.house.title}`} />
            </a>
        )}
        </div>
      }
      <label className="form-field">
        <span>{t("Review note (optional)")}</span>
        <textarea maxLength="10000" value={reviewNotes} onChange={(event) => setReviewNotes(event.target.value)} />
      </label>
      <RequestError message={error} />
      <div className="approval-actions">
        <button
          className="primary-button"
          type="button"
          disabled={busy || inspection.recommendation !== 'approve'}
          title={inspection.recommendation !== 'approve' ? t("The worker must recommend approval before verification.") : undefined}
          onClick={() => decide('verified')}>{t("\n          Approve listing\n        ")}


        </button>
        <button className="secondary-link" type="button" disabled={busy} onClick={() => decide('corrections_required')}>{t("\n          Request corrections\n        ")}

        </button>
        <button className="secondary-link reject-approval" type="button" disabled={busy} onClick={() => decide('rejected')}>{t("\n          Reject listing\n        ")}

        </button>
      </div>
      {notice && <p className="notice success-notice" role="status">{notice}</p>}
    </article>);

}

function AdminNegotiationCard({ house, listingMargin, onUpdated }) {
  const [status, setStatus] = useState('negotiation_in_progress');
  const [proposed, setProposed] = useState(house.proposed_owner_rent_tnd ?? '');
  const [agreed, setAgreed] = useState(house.agreed_owner_rent_tnd ?? house.original_owner_rent_tnd);
  const [notes, setNotes] = useState(house.negotiation_notes ?? '');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [errors, setErrors] = useState({});
  const [notice, setNotice] = useState('');
  const agreedPublicPrice = agreed === '' ? null : addTndAmounts(agreed, listingMargin);

  async function save(nextStatus) {
    setBusy(true);
    setError('');
    setNotice('');
    try {
      const result = await apiRequest(`/api/admin/houses/${house.id}/price-negotiation`, {
        method: 'PATCH',
        body: JSON.stringify({
          negotiation_status: nextStatus,
          proposed_owner_rent_tnd: proposed === '' ? null : proposed,
          ...(nextStatus === 'price_agreed' ? { agreed_owner_rent_tnd: agreed } : {}),
          negotiation_notes: notes
        })
      });
      onUpdated(result.house);
      setNotice(result.message);
      setStatus(nextStatus);
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setBusy(false);
    }
  }

  async function reviewSubmission(decision) {
    setBusy(true);
    setError('');
    setErrors({});
    setNotice('');
    try {
      const result = await apiRequest(`/api/admin/houses/${house.id}/submission-decision`, {
        method: 'POST',
        body: JSON.stringify({ decision })
      });
      onUpdated(result.house);
      setNotice(result.message);
    } catch (requestError) {
      setErrors(requestError.errors ?? {});
      setError(requestError.message);
    } finally {
      setBusy(false);
    }
  }

  const agreementRecorded = house.negotiation_status === 'price_agreed';
  const waitingForSubmissionDecision = house.negotiation_status === 'pending';
  const submissionRejected = house.negotiation_status === 'rejected';
  const publishedByAdmin = ['admin_priced', 'owner_accepted', 'owner_refused'].includes(house.negotiation_status) &&
  house.verification_status === 'verified';

  return (
    <article className="negotiation-card">
      <div className="negotiation-card-heading">
        <div>
          <p className="eyebrow">{t("House #")}{house.id}</p>
          <h2>{house.title}</h2>
          <p>{house.city}{t(", ")}{house.governorate}</p>
        </div>
        <VerificationBadge status={house.verification_status} />
      </div>
      <div className="negotiation-owner-contact">
        <strong>{house.owner?.name}</strong>
        <span>{house.owner?.phone || 'No phone number recorded'}</span>
        <span>{house.owner?.email}</span>
      </div>
      <dl className="negotiation-prices">
        <div><dt>{t("Original requested rent")}</dt><dd>{Number(house.original_owner_rent_tnd).toFixed(3)}{t(" TND/month")}</dd></div>
        {house.proposed_owner_rent_tnd !== null && <div><dt>{t("Proposed owner rent")}</dt><dd>{Number(house.proposed_owner_rent_tnd).toFixed(3)}{t(" TND/month")}</dd></div>}
        {house.agreed_owner_rent_tnd !== null && <div><dt>{t("Agreed owner rent")}</dt><dd>{Number(house.agreed_owner_rent_tnd).toFixed(3)}{t(" TND/month")}</dd></div>}
        {house.public_listing_price_tnd !== null && <div><dt>{t("Calculated public price")}</dt><dd>{Number(house.public_listing_price_tnd).toFixed(3)}{t(" TND/month")}</dd></div>}
        <div><dt>{t("Latest contact")}</dt><dd>{formatDate(house.negotiation_last_contact_at)}</dd></div>
        <div><dt>{t("Owner photos")}</dt><dd>{house.photo_count ?? house.photos.length}{t(" / 4 minimum")}</dd></div>
      </dl>
      {house.photos.length > 0 &&
      <div className="approval-photo-grid">
          {house.photos.map((photo) =>
        <a key={photo.id} href={photo.url} target="_blank" rel="noreferrer">
              <img src={photo.url} alt={photo.caption || `Photo of ${house.title}`} />
            </a>
        )}
        </div>
      }
      {waitingForSubmissionDecision ?
      <div className="submission-review-actions">
          <p>{t("After speaking with the owner by phone, record their answer. The system calculates the public rent automatically.")}</p>
          <div className="negotiation-prices">
            <div><dt>{t("Suggested owner rent after 5% reduction")}</dt><dd>{Number(house.suggested_owner_rent_tnd).toFixed(3)}{t(" TND/month")}</dd></div>
            <div><dt>{t("If accepted · student listing")}</dt><dd>{Number(house.original_owner_rent_tnd).toFixed(3)}{t(" TND/month")}</dd></div>
            <div><dt>{t("If refused · student listing (+5%, rounded)")}</dt><dd>{Number(house.refusal_public_price_tnd).toFixed(3)}{t(" TND/month")}</dd></div>
          </div>
          <RequestError message={error} />
          <div className="approval-actions">
            <button className="primary-button" type="button" disabled={busy || house.photo_count < 4} onClick={() => reviewSubmission('owner_accepted')}>
              {busy ? 'Publishing…' : 'Owner accepts · publish'}
            </button>
            <button className="secondary-link" type="button" disabled={busy || house.photo_count < 4} onClick={() => reviewSubmission('owner_refused')}>{t("\n              Owner refuses · add 5% and publish\n            ")}

          </button>
            <button className="secondary-link reject-approval" type="button" disabled={busy} onClick={() => reviewSubmission('reject')}>{t("\n              Reject submission\n            ")}

          </button>
          </div>
        </div> :
      submissionRejected ?
      <p className="notice error-notice" role="status">{t("This submission was rejected. It is not available to students.")}</p> :
      publishedByAdmin ?
      <p className="worker-approval-note">
          {house.negotiation_status === 'owner_accepted' ?
        `Owner accepted the 5% reduction. Agreed owner rent: ${Number(house.agreed_owner_rent_tnd).toFixed(3)} TND/month.` :
        house.negotiation_status === 'owner_refused' ?
        'Owner refused the reduction. The public rent was increased by 5% and rounded to the nearest ten TND.' :
        'This listing has been published.'}
          {' '}{t("Students see ")}{Number(house.public_listing_price_tnd).toFixed(3)}{t(" TND/month.\n        ")}
      </p> :
      agreementRecorded ?
      <p className="worker-approval-note">{t("Price agreement is recorded. The house proceeds through the existing inspection and verification process.")}</p> :

      <form className="negotiation-form" onSubmit={(event) => {event.preventDefault();save(status);}}>
          <div className="field-row">
            <label className="form-field">
              <span>{t("Negotiation status")}</span>
              <select value={status} onChange={(event) => setStatus(event.target.value)}>
                <option value="negotiation_in_progress">{t("Phone negotiation in progress")}</option>
                <option value="no_agreement">{t("No agreement yet")}</option>
                <option value="price_agreed">{t("Owner confirmed final rent by phone")}</option>
              </select>
            </label>
            <FormField
            label={t("Proposed owner rent (TND), if any")}
            name="proposed_owner_rent_tnd"
            id={`proposed-owner-rent-${house.id}`}
            type="number"
            step="0.001"
            value={proposed}
            onChange={(event) => setProposed(event.target.value)}
            errors={errors} />
          
          </div>
          {status === 'price_agreed' &&
        <>
              <FormField
            label={t("Final agreed owner rent (TND)")}
            name="agreed_owner_rent_tnd"
            id={`agreed-owner-rent-${house.id}`}
            type="number"
            step="0.001"
            value={agreed}
            onChange={(event) => setAgreed(event.target.value)}
            errors={errors} />
          
              <p className="form-note">{t("\n                Calculated public price: ")}
            {agreedPublicPrice === null ? 'enter the agreed owner rent' : `${agreedPublicPrice} TND/month`}{t(".\n                The system adds the configured Sakani margin.\n              ")}

          </p>
            </>
        }
          <label className="form-field">
            <span>{t("Private notes about the phone call")}</span>
            <textarea maxLength="10000" value={notes} onChange={(event) => setNotes(event.target.value)} />
          </label>
          <RequestError message={error} />
          <button className="primary-button" type="submit" disabled={busy}>
            {busy ? 'Saving negotiation…' : status === 'price_agreed' ? 'Confirm price agreement' : 'Save negotiation update'}
          </button>
        </form>
      }
      {notice && <p className="notice success-notice" role="status">{notice}</p>}
    </article>);

}

function WorkerInspectionsPage() {
  const [inspections, setInspections] = useState([]);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    apiRequest('/api/worker/inspections').
    then(({ inspections: assignedInspections }) => setInspections(assignedInspections)).
    catch((requestError) => setError(requestError.message)).
    finally(() => setLoading(false));
  }, []);

  return (
    <section className="owner-content">
      <div className="owner-heading">
        <div><p className="eyebrow">{t("Assigned property visits")}</p><h1>{t("My Inspections")}</h1></div>
      </div>
      {loading && <p className="form-note">{t("Loading your inspections…")}</p>}
      <RequestError message={error} />
      {!loading && !error && inspections.length === 0 &&
      <div className="owner-empty-state compact-empty">
          <h2>{t("No inspections assigned")}</h2>
          <p>{t("New assignments for your service area will appear here.")}</p>
        </div>
      }
      <div className="inspection-list">
        {inspections.map((inspection) =>
        <article className="inspection-card" key={inspection.id}>
            <div className="house-card-heading">
              <h2>{inspection.house.title}</h2>
              <span className={`verification-badge ${inspection.status === 'completed' ? 'status-verified' : ''}`}>
                {inspection.status === 'completed' ? 'Report submitted' : 'Inspection assigned'}
              </span>
            </div>
            <p>{inspection.house.city}{t(", ")}{inspection.house.governorate}{inspection.house.address ? ` · ${inspection.house.address}` : ''}</p>
            <div className="inspection-contact">
              <span>{t("Owner: ")}{inspection.owner.name}</span>
              <a href={`mailto:${inspection.owner.email}`}>{inspection.owner.email}</a>
              {inspection.owner.phone && <a href={`tel:${inspection.owner.phone}`}>{inspection.owner.phone}</a>}
            </div>
            <p className="inspection-scheduled">{t("Scheduled: ")}{formatDateTime(inspection.scheduled_at)}</p>
            <Link className="secondary-link" to={`/worker/inspections/${inspection.id}`}>
              {inspection.status === 'completed' ? 'View report' : 'Open inspection form'}
            </Link>
          </article>
        )}
      </div>
    </section>);

}

function WorkerInspectionPage() {
  const { inspectionId } = useParams();
  const navigate = useNavigate();
  const [inspection, setInspection] = useState(null);
  const [checklist, setChecklist] = useState(
    Object.fromEntries(inspectionChecklist.map(([key]) => [key, '']))
  );
  const [notes, setNotes] = useState('');
  const [defects, setDefects] = useState('');
  const [recommendation, setRecommendation] = useState('');
  const [photos, setPhotos] = useState([]);
  const [errors, setErrors] = useState({});
  const [requestError, setRequestError] = useState('');
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    apiRequest(`/api/worker/inspections/${inspectionId}`).
    then(({ inspection: report }) => {
      setInspection(report);
      if (report.condition_checklist) setChecklist(report.condition_checklist);
      if (report.notes) setNotes(report.notes);
      if (report.defects_found) setDefects(report.defects_found);
      if (report.recommendation) setRecommendation(report.recommendation);
    }).
    catch((error) => setRequestError(error.message)).
    finally(() => setLoading(false));
  }, [inspectionId]);

  async function submit(event) {
    event.preventDefault();
    setSubmitting(true);
    setErrors({});
    setRequestError('');

    const data = new FormData();
    Object.entries(checklist).forEach(([key, value]) => data.append(`condition_checklist[${key}]`, value));
    data.append('notes', notes);
    data.append('defects_found', defects);
    data.append('recommendation', recommendation);
    photos.forEach((photo) => data.append('photos[]', photo));

    try {
      const result = await apiRequest(`/api/worker/inspections/${inspectionId}/complete`, {
        method: 'POST',
        body: data
      });
      setInspection(result.inspection);
    } catch (error) {
      setErrors(error.errors ?? {});
      setRequestError(error.errors && Object.keys(error.errors).length ? '' : error.message);
    } finally {
      setSubmitting(false);
    }
  }

  if (loading) return <section className="owner-content"><p className="form-note">{t("Loading inspection…")}</p></section>;

  if (!inspection) {
    return (
      <section className="owner-content">
        <div className="owner-heading"><h1>{t("Inspection unavailable")}</h1></div>
        <RequestError message={requestError} />
        <Link className="secondary-link" to="/worker/inspections">{t("Back to My Inspections")}</Link>
      </section>);

  }

  const readOnly = inspection.status === 'completed';

  return (
    <section className="owner-content">
      <div className="owner-heading">
        <div><p className="eyebrow">{t("My Inspections · ")}{inspection.house.city}</p><h1>{inspection.house.title}</h1></div>
        <Link className="secondary-link" to="/worker/inspections">{t("Back to My Inspections")}</Link>
      </div>
      <article className="inspection-detail-card">
        <div className="house-card-heading">
          <span>{inspection.house.city}{t(", ")}{inspection.house.governorate}{inspection.house.address ? ` · ${inspection.house.address}` : ''}</span>
          <VerificationBadge status={inspection.house.verification_status} />
        </div>
        <p>{t("Owner: ")}{inspection.owner.name}{t(" · ")}<a href={`mailto:${inspection.owner.email}`}>{inspection.owner.email}</a></p>
        <p>{t("Inspection scheduled for: ")}{formatDateTime(inspection.scheduled_at)}</p>
        {inspection.completed_at && <p>{t("Report submitted: ")}{formatDateTime(inspection.completed_at)}</p>}
        {inspection.reviewed_at && <p>{t("Administrator reviewed this report on ")}{formatDateTime(inspection.reviewed_at)}{t(".")}</p>}
      </article>
      {readOnly ?
      <div className="inspection-detail-card">
          <h2>{t("Submitted inspection report")}</h2>
          <InspectionChecklistView checklist={inspection.condition_checklist} />
          <p><strong>{t("Notes:")}</strong> {inspection.notes}</p>
          <p><strong>{t("Defects:")}</strong> {inspection.defects_found || 'None reported'}</p>
          <p><strong>{t("Recommendation:")}</strong> {inspection.recommendation.replaceAll('_', ' ')}</p>
          {inspection.review_notes && <p><strong>{t("Review notes:")}</strong> {inspection.review_notes}</p>}
          {inspection.photos.length > 0 && <div className="house-photo-grid">{inspection.photos.map((photo) => <img key={photo.id} src={photo.url} alt={t("Property inspection")} />)}</div>}
        </div> :

      <form className="house-form-card inspection-form" onSubmit={submit} noValidate>
          <div>
            <h2>{t("On-site inspection checklist")}</h2>
            <p className="form-note">{t("Visit the physical property and record what you observe.")}</p>
          </div>
          <FieldError errors={errors} name="condition_checklist" />
          {inspectionChecklist.map(([key, label]) =>
        <div className="checklist-row" key={key}>
              <span>{label}</span>
              <div className="checklist-options">
                {[
            ['good', 'Good'],
            ['problem_found', 'Problem found'],
            ['not_applicable', 'Not applicable']].
            map(([value, optionLabel]) =>
            <label key={value}>
                    <input
                type="radio"
                name={`checklist-${key}`}
                value={value}
                checked={checklist[key] === value}
                onChange={() => setChecklist((current) => ({ ...current, [key]: value }))}
                required />
              
                    {t(optionLabel)}
                  </label>
            )}
              </div>
              <FieldError errors={errors} name={`condition_checklist.${key}`} />
            </div>
        )}
          <div className="form-field">
            <label htmlFor="inspection-notes">{t("Inspection notes")}</label>
            <textarea id="inspection-notes" value={notes} onChange={(event) => setNotes(event.target.value)} rows="4" required />
            <FieldError errors={errors} name="notes" />
          </div>
          <div className="form-field">
            <label htmlFor="inspection-defects">{t("Defects found")}</label>
            <textarea id="inspection-defects" value={defects} onChange={(event) => setDefects(event.target.value)} rows="3" />
            <FieldError errors={errors} name="defects_found" />
          </div>
          <SelectField
          label={t("Final recommendation")}
          name="recommendation"
          value={recommendation}
          onChange={(event) => setRecommendation(event.target.value)}
          errors={errors}
          options={[
          ['', 'Choose a recommendation'],
          ['approve', 'Approve'],
          ['reject', 'Reject'],
          ['require_corrections', 'Require corrections']]
          } />
        
          <div className="form-field">
            <label htmlFor="inspection-photos">{t("On-site photos or defect photos (JPG/PNG/WebP, 5 MB each, up to 8)")}</label>
            <input id="inspection-photos" type="file" accept="image/jpeg,image/png,image/webp" multiple onChange={(event) => setPhotos(Array.from(event.target.files ?? []))} />
            <PhotoPreviews files={photos} />
            <FieldError errors={errors} name="photos" />
            <FieldError errors={errors} name="photos.0" />
          </div>
          <RequestError message={requestError} />
          <p className="worker-approval-note">{t("Submitting records your inspection and recommendation. An administrator makes the final verification decision.")}</p>
          <button className="primary-button" type="submit" disabled={submitting}>
            {submitting ? 'Submitting report…' : 'Submit inspection report'} <span aria-hidden="true">{t("→")}</span>
          </button>
        </form>
      }
    </section>);

}

function InspectionChecklistView({ checklist }) {
  return (
    <ul className="submitted-checklist">
      {inspectionChecklist.map(([key, label]) =>
      <li key={key}><span>{t(label)}</span><strong>{t((checklist?.[key] ?? 'not recorded').replaceAll('_', ' '))}</strong></li>
      )}
    </ul>);

}

const studentNavigation = [
{ to: '/student/dashboard', label: 'Overview' },
{ to: '/student/houses', label: 'Browse homes' },
{ to: '/student/rental-requests', label: 'Requests' },
{ to: '/student/payments', label: 'Fees' },
{ to: '/student/maintenance-requests', label: 'Maintenance' }];


function StudentPortal({ user, children }) {
  if (!user) return <Navigate to="/login" replace />;
  if (user.role !== 'student') return <Navigate to={dashboardPath(user.role)} replace />;

  return (
    <div className="owner-portal student-portal">
      <nav className="owner-nav" aria-label={t("Student dashboard")}>
        {studentNavigation.map((item) =>
        <NavLink key={item.to} to={item.to} end={item.to !== '/student/houses'} className="owner-nav-link">{t(item.label)}</NavLink>
        )}
      </nav>
      {children}
    </div>);

}

function StudentDashboard({ user }) {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [refresh, setRefresh] = useState(0);
  const [location, setLocation] = useState({ governorate: '', city: '' });
  const navigate = useNavigate();

  useEffect(() => {
    setLoading(true);
    apiRequest('/api/student/dashboard').
    then(setData).
    catch((requestError) => setError(requestError.message)).
    finally(() => setLoading(false));
  }, [refresh]);

  function search(event) {
    event.preventDefault();
    const query = new URLSearchParams();
    Object.entries(location).forEach(([key, value]) => {
      if (value.trim()) query.set(key, value.trim());
    });
    navigate(`/student/houses${query.toString() ? `?${query}` : ''}`);
  }

  return (
    <section className="owner-content student-content">
      <div className="owner-heading">
        <div>
          <p className="eyebrow">{t("Student space")}</p>
          <h1>{t("Welcome, ")}{user.name.split(' ')[0]}{t(".")}</h1>
          <p className="auth-description">{t("Find an approved home and manage your rental requests in one place.")}</p>
        </div>
      </div>
      {loading && <p className="form-note">{t("Loading your dashboard…")}</p>}
      <RequestError message={error} />
      {data &&
      <>
          <form className="student-search-card" onSubmit={search}>
            <div>
              <p className="eyebrow">{t("Start your search")}</p>
              <h2>{t("Find a verified home")}</h2>
            </div>
            <div className="student-search-fields">
              <label className="form-field">
                <span>{t("Governorate")}</span>
                <input value={location.governorate} onChange={(event) => setLocation((current) => ({ ...current, governorate: event.target.value }))} placeholder={t("e.g. Tunis")} />
              </label>
              <label className="form-field">
                <span>{t("City")}</span>
                <input value={location.city} onChange={(event) => setLocation((current) => ({ ...current, city: event.target.value }))} placeholder={t("e.g. Ariana")} />
              </label>
              <button className="primary-button" type="submit">{t("Search homes ")}<span aria-hidden="true">{t("→")}</span></button>
            </div>
          </form>

          <div className="fee-notice">
            <strong>{t("Student service fee: ")}{Number(data.student_service_fee_tnd).toFixed(3)}{t(" TND")}</strong>
            <span>{t("Only due after you and the owner both confirm that the rental is secured. No payment is taken before then. ")}<Link to="/student/payments">{t("View payment records")}</Link></span>
          </div>

          <section className="student-section">
            <div className="student-section-heading">
              <div><p className="eyebrow">{t("Approved and available")}</p><h2>{t("Recently listed homes")}</h2></div>
              <Link className="secondary-link" to="/student/houses">{t("See all homes")}</Link>
            </div>
            {data.recent_houses.length ?
          <div className="house-card-grid">{data.recent_houses.map((house) => <MarketplaceHouseCard key={house.id} house={house} />)}</div> :
          <EmptyState title={t("No verified homes yet")} description={t("New homes will appear here after an administrator approves their inspection.")} />}
          </section>

          <section className="student-section">
            <div className="student-section-heading">
              <div><p className="eyebrow">{t("Keep track")}</p><h2>{t("Active rental requests")}</h2></div>
              <Link className="secondary-link" to="/student/rental-requests">{t("Request history")}</Link>
            </div>
            {data.active_requests.length ?
          <div className="rental-request-list">{data.active_requests.map((rentalRequest) =>
            <RentalRequestCard key={rentalRequest.id} request={rentalRequest} audience="student" onChanged={() => setRefresh((value) => value + 1)} />
            )}</div> :
          <EmptyState title={t("No active requests")} description={t("When you request a visit or express interest in a home, its status will appear here.")} />}
          </section>

          <section className="student-section">
            <div className="student-section-heading">
              <div><p className="eyebrow">{t("Past activity")}</p><h2>{t("Rental request history")}</h2></div>
            </div>
            {data.request_history.length ?
          <div className="rental-request-list">{data.request_history.map((rentalRequest) =>
            <RentalRequestCard key={rentalRequest.id} request={rentalRequest} audience="student" onChanged={() => setRefresh((value) => value + 1)} />
            )}</div> :
          <p className="student-muted">{t("Completed, rejected, and cancelled requests will be kept here.")}</p>}
          </section>

          {data.maintenance_requests.length > 0 &&
        <section className="student-section">
              <div className="student-section-heading"><div><p className="eyebrow">{t("Your rented home")}</p><h2>{t("Maintenance updates")}</h2></div></div>
              <div className="maintenance-summary-list">
                {data.maintenance_requests.map((item) =>
            <article className="maintenance-summary" key={item.id}>
                    <div><strong>{item.description}</strong><span>{t(item.priority)}{t(" priority · ")}{t(item.status.replaceAll('_', ' '))}</span></div>
                    <time>{formatDate(item.reported_at)}</time>
                  </article>
            )}
              </div>
            </section>
        }

          <section id="account" className="student-account-card">
            <div><p className="eyebrow">{t("Account information")}</p><h2>{data.account.name}</h2></div>
            <dl><div><dt>{t("Email")}</dt><dd>{data.account.email}</dd></div><div><dt>{t("Phone")}</dt><dd>{data.account.phone || 'Not provided'}</dd></div></dl>
          </section>
        </>
      }
    </section>);

}

function formatDate(value) {
  return value ? new Date(value).toLocaleDateString(getLanguage() === 'fr' ? 'fr-TN' : 'en-TN') : t('Date not available');
}

function EmptyState({ title, description }) {
  return <div className="owner-empty-state compact-empty"><h2>{title}</h2><p>{description}</p></div>;
}

function MarketplaceHouseCard({ house }) {
  return (
    <article className="house-card marketplace-house-card">
      <Link to={`/student/houses/${house.id}`} aria-label={`View ${house.title}`}>
        {house.photos[0] ?
        <img className="house-card-image" src={house.photos[0].url} alt={`Photo of ${house.title}`} /> :
        <div className="house-card-image house-image-placeholder" aria-hidden="true">{t("⌂")}</div>}
      </Link>
      <div className="house-card-content">
        <div className="house-card-heading"><h2>{house.title}</h2><span className="verification-badge status-verified">{t("Verified")}</span></div>
        <p>{house.city}{t(", ")}{house.governorate}</p>
        <div className="house-card-meta"><span>{Number(house.monthly_rent_tnd).toFixed(3)}{t(" TND / month")}</span><span>{house.bedrooms}{t(" bedrooms")}</span></div>
        <div className="house-card-actions"><Link to={`/student/houses/${house.id}`}>{t("View details")}</Link><span>{t("Inspected ")}{formatDate(house.inspection?.date)}</span></div>
      </div>
    </article>);

}

function StudentHouseSearchPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const queryString = searchParams.toString();
  const [filters, setFilters] = useState({});
  const [listing, setListing] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    setFilters({
      governorate: searchParams.get('governorate') ?? '',
      city: searchParams.get('city') ?? '',
      max_rent: searchParams.get('max_rent') ?? '',
      min_bedrooms: searchParams.get('min_bedrooms') ?? '',
      furnishing_status: searchParams.get('furnishing_status') ?? '',
      available: searchParams.get('available') ?? '1'
    });
    setLoading(true);
    setError('');
    apiRequest(`/api/student/houses${queryString ? `?${queryString}` : ''}`).
    then(({ houses }) => setListing(houses)).
    catch((requestError) => setError(requestError.message)).
    finally(() => setLoading(false));
  }, [queryString, searchParams]);

  function changeFilter(event) {
    setFilters((current) => ({ ...current, [event.target.name]: event.target.value }));
  }

  function submit(event) {
    event.preventDefault();
    const query = new URLSearchParams();
    Object.entries(filters).forEach(([key, value]) => {
      if (value !== '' && !(key === 'available' && value === '1')) query.set(key, value);
    });
    setSearchParams(query);
  }

  function goToPage(page) {
    const query = new URLSearchParams(queryString);
    query.set('page', String(page));
    setSearchParams(query);
  }

  return (
    <section className="owner-content student-content">
      <div className="owner-heading">
        <div><p className="eyebrow">{t("Student housing")}</p><h1>{t("Find a verified home")}</h1><p className="auth-description">{t("Browse available houses that passed an inspection and administrator review.")}</p></div>
      </div>
      <form className="house-form-card student-filter-card" onSubmit={submit}>
        <div className="student-filter-grid">
          <label className="form-field"><span>{t("Governorate")}</span><input name="governorate" value={filters.governorate ?? ''} onChange={changeFilter} /></label>
          <label className="form-field"><span>{t("City")}</span><input name="city" value={filters.city ?? ''} onChange={changeFilter} /></label>
          <label className="form-field"><span>{t("Maximum monthly rent (TND)")}</span><input name="max_rent" type="number" min="1" step="0.001" value={filters.max_rent ?? ''} onChange={changeFilter} /></label>
          <label className="form-field"><span>{t("Minimum bedrooms")}</span><input name="min_bedrooms" type="number" min="1" step="1" value={filters.min_bedrooms ?? ''} onChange={changeFilter} /></label>
          <label className="form-field"><span>{t("Furnishing")}</span>
            <select name="furnishing_status" value={filters.furnishing_status ?? ''} onChange={changeFilter}>
              <option value="">{t("Any")}</option><option value="furnished">{t("Furnished")}</option><option value="unfurnished">{t("Unfurnished")}</option><option value="partially_furnished">{t("Partially furnished")}</option><option value="unknown">{t("Not specified")}</option>
            </select>
          </label>
          <label className="form-field"><span>{t("Availability")}</span>
            <select name="available" value={filters.available ?? '1'} onChange={changeFilter}><option value="1">{t("Available homes")}</option><option value="0">{t("Unavailable homes")}</option></select>
          </label>
        </div>
        <button className="primary-button" type="submit">{t("Apply filters ")}<span aria-hidden="true">{t("→")}</span></button>
      </form>
      {loading && <p className="form-note">{t("Loading verified homes…")}</p>}
      <RequestError message={error} />
      {!loading && !error && listing &&
      <>
          {listing.data.length ?
        <div className="house-card-grid marketplace-results">{listing.data.map((house) => <MarketplaceHouseCard key={house.id} house={house} />)}</div> :
        <EmptyState title={t("No homes match these filters")} description={t("Try increasing your rent limit or removing a filter.")} />}
          {listing.last_page > 1 &&
        <div className="pagination-controls">
              <button className="secondary-link" type="button" disabled={!listing.prev_page_url} onClick={() => goToPage(listing.current_page - 1)}>{t("Previous")}</button>
              <span>{t("Page ")}{listing.current_page}{t(" of ")}{listing.last_page}</span>
              <button className="secondary-link" type="button" disabled={!listing.next_page_url} onClick={() => goToPage(listing.current_page + 1)}>{t("Next")}</button>
            </div>
        }
        </>
      }
    </section>);

}

function StudentHouseDetailsPage() {
  const { houseId } = useParams();
  const [house, setHouse] = useState(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [fee, setFee] = useState(null);

  useEffect(() => {
    Promise.all([
    apiRequest(`/api/student/houses/${houseId}`),
    apiRequest('/api/student/dashboard')]
    ).
    then(([houseResult, dashboard]) => {
      setHouse(houseResult.house);
      setFee(dashboard.student_service_fee_tnd);
    }).
    catch((requestError) => setError(requestError.message)).
    finally(() => setLoading(false));
  }, [houseId]);

  async function requestHouse() {
    setSubmitting(true);
    setError('');
    setNotice('');
    try {
      const result = await apiRequest('/api/student/rental-requests', {
        method: 'POST',
        body: JSON.stringify({ house_id: house.id })
      });
      setNotice(result.message);
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <section className="owner-content student-content">
      <div className="owner-heading"><div><p className="eyebrow">{t("Verified home")}</p><h1>{t("House details")}</h1></div><Link className="secondary-link" to="/student/houses">{t("Back to search")}</Link></div>
      {loading && <p className="form-note">{t("Loading house details…")}</p>}
      <RequestError message={error} />
      {notice && <p className="notice success-notice" role="status">{notice} <Link to="/student/rental-requests">{t("View your requests")}</Link></p>}
      {house &&
      <article className="house-details-card marketplace-details">
          <div className="house-card-heading"><h2>{house.title}</h2><span className="verification-badge status-verified">{t("Verified by administrator")}</span></div>
          <p className="house-detail-location">{house.city}{t(", ")}{house.governorate}{house.address ? ` · ${house.address}` : ''}</p>
          <p>{house.description || 'No description provided.'}</p>
          <dl className="house-detail-list">
            <div><dt>{t("Monthly rent")}</dt><dd>{Number(house.monthly_rent_tnd).toFixed(3)}{t(" TND")}</dd></div>
            <div><dt>{t("Bedrooms")}</dt><dd>{house.bedrooms}</dd></div>
            <div><dt>{t("Furnishing")}</dt><dd>{t(house.furnishing_status.replaceAll('_', ' '))}</dd></div>
            <div><dt>{t("Availability")}</dt><dd>{house.available ? 'Available' : 'Not currently available'}</dd></div>
            <div><dt>{t("Owner")}</dt><dd>{house.owner.name}</dd></div>
            <div><dt>{t("Verified on")}</dt><dd>{formatDate(house.verified_at)}</dd></div>
            <div><dt>{t("Latest inspection")}</dt><dd>{formatDate(house.inspection?.date)}</dd></div>
          </dl>
          {house.photos.length > 0 && <div className="house-photo-grid">{house.photos.map((photo) => <img key={photo.id} src={photo.url} alt={photo.caption || `Photo of ${house.title}`} />)}</div>}
          <section className="public-inspection">
            <p className="eyebrow">{t("Inspection summary")}</p>
            {house.inspection ?
          <>
                <ul className="submitted-checklist">
                  {inspectionChecklist.map(([key, label]) =>
              <li key={key}><span>{t(label)}</span><strong>{t((house.inspection.checklist?.[key] ?? 'not recorded').replaceAll('_', ' '))}</strong></li>
              )}
                </ul>
                <div className="public-defects"><strong>{t("Reported defects")}</strong><p>{house.inspection.defects_found || 'No defects were reported.'}</p></div>
              </> :
          <p className="student-muted">{t("No inspection summary is available.")}</p>}
          </section>
          <div className="student-request-action">
            <div><strong>{t("Student service fee: ")}{fee === null ? '…' : `${Number(fee).toFixed(3)} TND`}</strong><span>{t("Due only after you and the owner both confirm the rental is secured. Requesting a visit is free.")}</span></div>
            <button className="primary-button" type="button" disabled={!house.available || submitting || fee === null} onClick={requestHouse}>
              {submitting ? 'Sending request…' : house.available ? 'Request a visit / express interest' : 'Currently unavailable'} <span aria-hidden="true">{t("→")}</span>
            </button>
          </div>
        </article>
      }
    </section>);

}

function StudentRentalRequestsPage() {
  const [requests, setRequests] = useState([]);
  const [fee, setFee] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [refresh, setRefresh] = useState(0);

  useEffect(() => {
    setLoading(true);
    apiRequest('/api/student/rental-requests').
    then(({ rental_requests: items, student_service_fee_tnd: serviceFee }) => {
      setRequests(items);
      setFee(serviceFee);
    }).
    catch((requestError) => setError(requestError.message)).
    finally(() => setLoading(false));
  }, [refresh]);

  return (
    <section className="owner-content student-content">
      <div className="owner-heading"><div><p className="eyebrow">{t("Your housing journey")}</p><h1>{t("My rental requests")}</h1><p className="auth-description">{t("Track owner responses and confirm when a rental is actually secured.")}</p></div></div>
      {fee !== null && <div className="fee-notice"><strong>{t("Student service fee: ")}{Number(fee).toFixed(3)}{t(" TND")}</strong><span>{t("It becomes due only after both you and the owner confirm the rental is secured. No payment has been processed.")}</span></div>}
      {loading && <p className="form-note">{t("Loading your requests…")}</p>}
      <RequestError message={error} />
      {!loading && !error && requests.length === 0 && <EmptyState title={t("No rental requests yet")} description={t("Search verified homes and request a visit to get started.")} />}
      <div className="rental-request-list">
        {requests.map((rentalRequest) =>
        <RentalRequestCard key={rentalRequest.id} request={rentalRequest} audience="student" onChanged={() => setRefresh((value) => value + 1)} />
        )}
      </div>
    </section>);

}

function StudentPaymentsPage() {
  const [data, setData] = useState(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [refresh, setRefresh] = useState(0);

  useEffect(() => {
    setLoading(true);
    apiRequest('/api/student/payments').
    then(setData).
    catch((requestError) => setError(requestError.message)).
    finally(() => setLoading(false));
  }, [refresh]);

  return (
    <section className="owner-content student-content">
      <div className="owner-heading"><div><p className="eyebrow">{t("Payment records")}</p><h1>{t("Payments & fees")}</h1><p className="auth-description">{t("Your 20 TND service fee is due only after you and the owner both confirm that the rental is secured.")}</p></div></div>
      {loading && <p className="form-note">{t("Loading your payment records…")}</p>}
      <RequestError message={error} />
      {data && <div className="fee-notice"><strong>{t("Service fee: ")}{Number(data.student_service_fee_tnd).toFixed(3)}{t(" TND")}</strong><span>{data.provider_message}</span></div>}
      {data && data.payments.length === 0 && !loading &&
      <EmptyState title={t("No service fees are due")} description={t("A fee record appears here only after both you and the owner confirm that a rental was secured.")} />
      }
      <div className="payment-record-list">
        {data?.payments.map((payment) =>
        <article className="payment-record" key={payment.id}>
            <div className="payment-record-heading">
              <div><p className="eyebrow">{t("Student service fee · Rental ")}{payment.rental_request?.reference}</p><h2>{payment.house?.title ?? 'Rental house'}</h2></div>
              <span className={`payment-status payment-status-${payment.status}`}>{t(payment.status.replaceAll('_', ' '))}</span>
            </div>
            <p>{payment.house?.city}{t(", ")}{payment.house?.governorate}{t(" · ")}{Number(payment.amount).toFixed(3)} {payment.currency}</p>
            <p>{t("Reference: ")}<code>{payment.reference}</code></p>
            <p>{t("Recorded: ")}{formatDate(payment.created_at)}{payment.paid_at ? ` · Confirmed paid ${formatDate(payment.paid_at)}` : ''}</p>
            {payment.failure_reason && <p className="payment-failure">{payment.failure_reason}</p>}
            {payment.status !== 'paid' &&
          <PreparePaymentButton
            path={`/api/student/rental-requests/${payment.rental_request.id}/payment`}
            label={t("Prepare service-fee payment")}
            onPrepared={() => setRefresh((value) => value + 1)} />

          }
          </article>
        )}
      </div>
    </section>);

}

function PaymentHistory({ payments = [] }) {
  return (
    <details className="payment-history">
      <summary>{t("Payment history (")}{payments.length}{t(")")}</summary>
      {payments.length ?
      <ul>
          {payments.map((payment) =>
        <li key={payment.id}>
              <span>{payment.billing_period ?? formatDate(payment.created_at)}{t(" · ")}{Number(payment.amount).toFixed(3)} {payment.currency}</span>
              <strong className={`payment-status payment-status-${payment.status}`}>{t(payment.status.replaceAll('_', ' '))}</strong>
              {payment.reference && <small>{t("Ref: ")}{payment.reference}</small>}
              {payment.paid_at && <small>{t("Payment confirmed ")}{formatDate(payment.paid_at)}</small>}
              {payment.failed_at && <small>{t("Failure recorded ")}{formatDate(payment.failed_at)}</small>}
              {payment.refunded_at && <small>{t("Refund recorded ")}{formatDate(payment.refunded_at)}</small>}
              {payment.failure_reason && <small className="payment-failure">{payment.failure_reason}</small>}
            </li>
        )}
        </ul> :
      <p className="student-muted">{t("No payment records yet.")}</p>}
    </details>);

}

function PreparePaymentButton({ path, label, onPrepared }) {
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  async function prepare() {
    setBusy(true);
    setError('');
    setMessage('');
    try {
      const result = await apiRequest(path, { method: 'POST' });
      setMessage(result.message);
      onPrepared?.();
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="prepare-payment">
      <button className="secondary-link" type="button" disabled={busy} onClick={prepare}>{busy ? 'Preparing…' : label}</button>
      {message && <p className="notice success-notice" role="status">{message}</p>}
      <RequestError message={error} />
    </div>);

}

function StudentMaintenancePage() {
  const [data, setData] = useState({ eligible_houses: [], maintenance_requests: null });
  const [houseId, setHouseId] = useState('');
  const [category, setCategory] = useState('plumbing');
  const [description, setDescription] = useState('');
  const [priority, setPriority] = useState('normal');
  const [reportedAt, setReportedAt] = useState(new Date().toISOString().slice(0, 10));
  const [photos, setPhotos] = useState([]);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [refresh, setRefresh] = useState(0);

  useEffect(() => {
    setLoading(true);
    apiRequest('/api/student/maintenance-requests').
    then(setData).
    catch((requestError) => setError(requestError.message)).
    finally(() => setLoading(false));
  }, [refresh]);

  async function submit(event) {
    event.preventDefault();
    setSubmitting(true);
    setError('');
    setNotice('');
    const body = new FormData();
    body.set('house_id', houseId);
    body.set('category', category);
    body.set('description', description);
    body.set('priority', priority);
    if (reportedAt) body.set('reported_at', `${reportedAt}T12:00:00`);
    photos.forEach((photo) => body.append('photos[]', photo));

    try {
      const result = await apiRequest('/api/student/maintenance-requests', { method: 'POST', body });
      setNotice(result.message);
      setDescription('');
      setPhotos([]);
      setRefresh((value) => value + 1);
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <section className="owner-content student-content">
      <div className="owner-heading"><div><p className="eyebrow">{t("Tenant support")}</p><h1>{t("Maintenance requests")}</h1><p className="auth-description">{t("Report issues only for a rental confirmed by both you and the owner.")}</p></div></div>
      {loading && <p className="form-note">{t("Loading your rented homes and maintenance history…")}</p>}
      <RequestError message={error} />
      {notice && <p className="notice success-notice" role="status">{notice}</p>}
      {!loading && data.eligible_houses.length === 0 &&
      <EmptyState title={t("No confirmed rented homes")} description={t("You can report maintenance problems here once a rental has been confirmed by both you and the owner.")} />
      }
      {data.eligible_houses.length > 0 &&
      <form className="house-form-card maintenance-student-form" onSubmit={submit}>
          <h2>{t("Report a problem")}</h2>
          <label className="form-field"><span>{t("Rented house")}</span>
            <select required value={houseId} onChange={(event) => setHouseId(event.target.value)}>
              <option value="">{t("Choose your rented house")}</option>
              {data.eligible_houses.map((house) => <option key={house.id} value={house.id}>{house.title}{t(" · ")}{house.city}{t(", ")}{house.governorate}</option>)}
            </select>
          </label>
          <div className="student-filter-grid">
            <label className="form-field"><span>{t("Problem category")}</span>
              <select value={category} onChange={(event) => setCategory(event.target.value)}>
                {Object.entries(maintenanceCategoryLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
              </select>
            </label>
            <label className="form-field"><span>{t("Priority")}</span>
              <select value={priority} onChange={(event) => setPriority(event.target.value)}>
                <option value="low">{t("Low")}</option><option value="normal">{t("Normal")}</option><option value="high">{t("High")}</option><option value="urgent">{t("Urgent")}</option>
              </select>
            </label>
            <label className="form-field"><span>{t("Date reported")}</span><input type="date" max={new Date().toISOString().slice(0, 10)} value={reportedAt} onChange={(event) => setReportedAt(event.target.value)} /></label>
          </div>
          <label className="form-field"><span>{t("Describe the problem")}</span><textarea required minLength="10" maxLength="10000" value={description} onChange={(event) => setDescription(event.target.value)} /></label>
          <label className="form-field"><span>{t("Photos (optional, JPG/PNG/WebP, up to 5 MB each)")}</span><input type="file" accept="image/jpeg,image/png,image/webp" multiple onChange={(event) => setPhotos(Array.from(event.target.files ?? []))} /><PhotoPreviews files={photos} /></label>
          <button className="primary-button" type="submit" disabled={submitting || !houseId}>{submitting ? 'Submitting report…' : 'Submit maintenance request'} <span aria-hidden="true">{t("→")}</span></button>
        </form>
      }
      {!loading && data.maintenance_requests?.data?.length > 0 &&
      <section className="student-section">
          <div className="student-section-heading"><div><p className="eyebrow">{t("Follow progress")}</p><h2>{t("Your reported issues")}</h2></div></div>
          <div className="maintenance-request-list">
            {data.maintenance_requests.data.map((item) => <MaintenanceRequestCard key={item.id} item={item} audience="student" />)}
          </div>
        </section>
      }
      {!loading && data.maintenance_requests?.data?.length === 0 && data.eligible_houses.length > 0 && <EmptyState title={t("No reported issues")} description={t("Maintenance requests you submit for a confirmed rental will appear here.")} />}
    </section>);

}

function OwnerRentalRequestsPage() {
  const [requests, setRequests] = useState([]);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [refresh, setRefresh] = useState(0);

  useEffect(() => {
    setLoading(true);
    apiRequest('/api/owner/rental-requests').
    then(({ rental_requests: items }) => setRequests(items)).
    catch((requestError) => setError(requestError.message)).
    finally(() => setLoading(false));
  }, [refresh]);

  return (
    <section className="owner-content">
      <div className="owner-heading"><div><p className="eyebrow">{t("House management")}</p><h1>{t("Rental requests")}</h1><p className="auth-description">{t("Respond only to requests for your own houses.")}</p></div></div>
      {loading && <p className="form-note">{t("Loading rental requests…")}</p>}
      <RequestError message={error} />
      {!loading && !error && requests.length === 0 && <EmptyState title={t("No requests received")} description={t("Student requests for your houses will appear here.")} />}
      <div className="rental-request-list">
        {requests.map((rentalRequest) =>
        <RentalRequestCard key={rentalRequest.id} request={rentalRequest} audience="owner" onChanged={() => setRefresh((value) => value + 1)} />
        )}
      </div>
    </section>);

}

function RentalRequestCard({ request, audience, onChanged }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const owner = audience === 'owner';

  async function action(path, body) {
    setBusy(true);
    setError('');
    setNotice('');
    try {
      const result = await apiRequest(path, { method: 'POST', body: JSON.stringify(body) });
      setNotice(result.message);
      onChanged();
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setBusy(false);
    }
  }

  const root = owner ? '/api/owner/rental-requests' : '/api/student/rental-requests';
  const confirmPath = `${root}/${request.id}/confirm-secured`;

  return (
    <article className="rental-request-card">
      <div className="rental-request-heading">
        <div><p className="eyebrow">{owner ? 'Student request' : 'House request'}</p><h2>{request.house?.title || 'House no longer available'}</h2><span>{request.house?.city}{t(", ")}{request.house?.governorate}{t(" · ")}{request.house ? `${Number(request.house.monthly_rent_tnd).toFixed(3)} TND / month` : ''}</span></div>
        <span className={`request-status status-${request.status}`}>{t(request.status.replaceAll('_', ' '))}</span>
      </div>
      <p className="student-muted">{t("Requested ")}{formatDate(request.requested_at)}</p>
      {owner && request.student && <div className="student-contact"><strong>{request.student.name}</strong><span>{request.student.email}{t(" · ")}{request.student.phone || 'No phone provided'}</span></div>}
      {request.status === 'accepted' &&
      <div className="confirmation-summary">
          <span>{t("Student confirmation: ")}{request.student_confirmed_secured ? 'confirmed' : 'waiting'}</span>
          <span>{t("Owner confirmation: ")}{request.owner_confirmed_secured ? 'confirmed' : 'waiting'}</span>
        </div>
      }
      {request.status === 'completed' &&
      <p className="fee-due-message">{t("Rental secured. The ")}{Number(request.service_fee_tnd).toFixed(3)}{t(" TND student service fee is ")}{request.service_fee_payment_status === 'paid' ? 'paid' : 'due'}{t("; this platform has not processed a payment.")}</p>
      }
      {notice && <p className="notice success-notice" role="status">{notice}</p>}
      <RequestError message={error} />
      <div className="rental-request-actions">
        {owner && request.status === 'pending' && <>
          <button className="primary-button" type="button" disabled={busy} onClick={() => action(`${root}/${request.id}/decision`, { decision: 'accepted' })}>{t("Accept request")}</button>
          <button className="secondary-link" type="button" disabled={busy} onClick={() => action(`${root}/${request.id}/decision`, { decision: 'rejected' })}>{t("Reject")}</button>
        </>}
        {owner && request.status === 'accepted' && !request.owner_confirmed_secured &&
        <button className="primary-button" type="button" disabled={busy} onClick={() => action(confirmPath, {})}>{t("Confirm rental secured")}</button>
        }
        {!owner && request.status === 'pending' &&
        <button className="secondary-link" type="button" disabled={busy} onClick={() => action(`${root}/${request.id}/cancel`, {})}>{t("Cancel request")}</button>
        }
        {!owner && request.status === 'accepted' && !request.student_confirmed_secured &&
        <button className="primary-button" type="button" disabled={busy} onClick={() => action(confirmPath, {})}>{t("Confirm rental secured")}</button>
        }
      </div>
    </article>);

}

function UnsupportedRolePage() {
  return (
    <section className="dashboard-card" role="status">
      <p className="eyebrow">{t("Account not available")}</p>
      <h1>{t("This portal is for students, property owners, and workers.")}</h1>
      <p className="auth-description">{t("Your account role does not have access to a supported dashboard.")}</p>
    </section>);

}

function LogoutButton({ onLoggedOut }) {
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  async function logout() {
    setSubmitting(true);
    setError('');
    try {
      await apiRequest('/api/auth/logout', { method: 'POST' });
      onLoggedOut();
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="logout-area">
      <button className="text-button" type="button" onClick={logout} disabled={submitting}>
        {submitting ? 'Logging out…' : 'Log out'}
      </button>
      {error && <RequestError message={error} />}
    </div>);

}

function LanguageToggle({ language, onChange }) {
  const nextLanguage = language === 'en' ? 'fr' : 'en';
  const label = nextLanguage === 'fr' ? 'Passer en français' : 'Switch to English';

  return (
    <button
      className="language-toggle"
      type="button"
      aria-label={label}
      title={label}
      onClick={() => onChange(nextLanguage)}
    >
      {nextLanguage === 'fr' ? 'FR' : 'EN'}
    </button>
  );
}

function MaintenanceNotifications({ user }) {
  const [notifications, setNotifications] = useState([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [error, setError] = useState('');
  const [refresh, setRefresh] = useState(0);

  useEffect(() => {
    if (!user) return;
    apiRequest('/api/maintenance-notifications').
    then((data) => {
      setNotifications(data.notifications);
      setUnreadCount(data.unread_count);
    }).
    catch((requestError) => setError(requestError.message));
  }, [refresh, user]);

  async function markRead(notificationId) {
    setError('');
    try {
      await apiRequest(`/api/maintenance-notifications/${notificationId}/read`, { method: 'POST' });
      setRefresh((value) => value + 1);
    } catch (requestError) {
      setError(requestError.message);
    }
  }

  return (
    <details className="notification-menu">
      <summary aria-label={`Maintenance notifications, ${unreadCount} unread`}>{t("\n        Notifications")}
        {unreadCount > 0 && <span className="notification-count">{unreadCount}</span>}
      </summary>
      <div className="notification-popover">
        {error && <RequestError message={error} />}
        {notifications.length === 0 && <p className="student-muted">{t("No maintenance notifications yet.")}</p>}
        {notifications.map((notification) =>
        <article className={`notification-item ${notification.read_at ? '' : 'notification-unread'}`} key={notification.id}>
            <p>{notification.message}</p>
            <div><time>{formatDate(notification.created_at)}</time>{!notification.read_at && <button className="text-button" type="button" onClick={() => markRead(notification.id)}>{t("Mark read")}</button>}</div>
          </article>
        )}
      </div>
    </details>);

}

export default App;
