import { useEffect, useState } from 'react';
import { NavLink, Navigate } from 'react-router-dom';
import { apiRequest } from '../../api.js';
import { t } from '../../i18n.js';
import { EmptyState, RequestError } from '../../components/common/Feedback.jsx';
import { dashboardPath } from '../../utils/navigation.js';

export default function AdminHousesPage({ user }) {
  const [houses, setHouses] = useState([]);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [loading, setLoading] = useState(true);
  const [deletingHouseId, setDeletingHouseId] = useState(null);

  useEffect(() => {
    if (user?.role !== 'admin') return;
    apiRequest('/api/admin/houses').
    then(({ houses: listings }) => setHouses(listings)).
    catch((requestError) => setError(requestError.message)).
    finally(() => setLoading(false));
  }, [user]);

  if (!user) return <Navigate to="/login" replace />;
  if (user.role !== 'admin') return <Navigate to={dashboardPath(user.role)} replace />;

  async function removeHouse(house) {
    const confirmation = house.owner_deletion_requested_at
      ? t('Approve this owner deletion request and permanently delete the house and all linked records? This cannot be undone.')
      : t('Permanently delete this house and all linked rental, payment, inspection, maintenance records, and photos? This cannot be undone.');
    if (!window.confirm(confirmation)) return;

    setDeletingHouseId(house.id);
    setError('');
    setNotice('');
    try {
      const result = await apiRequest(`/api/admin/houses/${house.id}`, { method: 'DELETE' });
      setHouses((current) => current.filter((listing) => listing.id !== house.id));
      setNotice(result.message);
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setDeletingHouseId(null);
    }
  }

  return (
    <div className="owner-portal">
      <nav className="owner-nav" aria-label={t("Administrator dashboard")}>
        <NavLink className="owner-nav-link" to="/admin/house-approvals">{t("Inspection reviews")}</NavLink>
        <NavLink className="owner-nav-link" to="/admin/price-negotiations">{t("Submissions & pricing")}</NavLink>
        <NavLink className="owner-nav-link" to="/admin/move-out-reports">{t("Move-out reports")}</NavLink>
        <NavLink className="owner-nav-link" to="/admin/houses">
          {t("All house posts")}
          {houses.filter((house) => house.owner_deletion_requested_at).length > 0
            ? ` (${houses.filter((house) => house.owner_deletion_requested_at).length} ${t("deletion requests")})`
            : ''}
        </NavLink>
      </nav>
      <section className="owner-content">
        <div className="owner-heading">
          <div>
            <p className="eyebrow">{t("Internal staff · private")}</p>
            <h1>{t("All house posts")}</h1>
            <p className="auth-description">{t("Review and remove any owner-submitted house post.")}</p>
          </div>
        </div>
        {loading && <p className="form-note">{t("Loading house posts…")}</p>}
        <RequestError message={error} />
        {notice && <div className="notice success-notice" role="status">{t(notice)}</div>}
        {!loading && !error && houses.length === 0 &&
          <EmptyState title={t("No house posts")} description={t("Owner-submitted house posts will appear here.")} />}
        <div className="negotiation-list">
          {houses.map((house) =>
            <article className="negotiation-card" key={house.id}>
              <div className="negotiation-card-heading">
                <div>
                  <h2>{house.title}</h2>
                  <p>{house.city}, {house.governorate}</p>
                </div>
                <span className={`verification-badge status-${house.verification_status}`}>
                  {t(house.verification_status.replaceAll('_', ' '))}
                </span>
              </div>
              {house.owner_deletion_requested_at &&
                <p className="notice pending-deletion-notice" role="status">{t("Owner deletion request awaiting approval.")}</p>
              }
              {house.photos?.length > 0 &&
                <div className="house-card-gallery" aria-label={t("Photos of {house}").replace('{house}', house.title)}>
                  {house.photos.map((photo, index) =>
                    <img key={photo.id} src={photo.url} alt={photo.caption || `${t("Photo")} ${index + 1} ${t("of")} ${house.title}`} loading="lazy" />
                  )}
                </div>
              }
              <div className="house-card-meta">
                <span>{t("Monthly rent")}: {house.public_listing_price_tnd ?? house.monthly_rent_tnd} TND</span>
                <span>{house.bedrooms} {t("bedrooms")}</span>
                <span>{t("Owner")}: {house.owner?.name ?? t("Unknown")}</span>
                <span>{t("Photos")}: {house.photo_count}</span>
              </div>
              <div className="house-card-actions">
                <button
                  className="danger-button"
                  type="button"
                  disabled={deletingHouseId === house.id}
                  onClick={() => removeHouse(house)}>
                  {deletingHouseId === house.id
                    ? t("Deleting…")
                    : house.owner_deletion_requested_at
                      ? t("Approve deletion and remove")
                      : t("Delete post")}
                </button>
              </div>
            </article>
          )}
        </div>
      </section>
    </div>);
}
