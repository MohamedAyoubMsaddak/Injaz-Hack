import { t } from '../../i18n.js';

export function RequestError({ message }) {
  return message ? <p className="notice error-notice" role="alert">{t(message)}</p> : null;
}

export function EmptyState({ title, description }) {
  return <div className="owner-empty-state compact-empty"><h2>{title}</h2><p>{description}</p></div>;
}
