/**
 * Estate picker — shown when the Laravel runtime reports several memberships
 * and no usable selection (149 §21).
 *
 * The API resolves exactly one estate per request from `X-Tenant-Id`. Sending
 * nothing makes every scoped route answer 409 `tenant_required`, so the
 * console asks before the shell mounts rather than rendering pages that
 * cannot load. A stored id is validated against the memberships the server
 * reports, so an estate the user was removed from lands here instead of a
 * permanent 404.
 *
 * Accessibility: real buttons (not a list of links), 44px targets, the first
 * option focused on mount, and the heading names the list. Sign-out stays
 * reachable so a visitor who picked the wrong account is never stuck.
 */

import { useEffect, useRef, type JSX } from 'react';

import type { EstateSummary } from '@nvoos/pro-spa-v2/api/tenant';

import nvoosIcon from '../assets/nvoos-icon.svg';

interface EstatePickerProps {
  /** Estates this session may act in (never empty — the caller only asks). */
  estates: EstateSummary[];
  /** Persist the choice and re-run the session bootstrap. */
  onSelect: (estateId: string) => void;
  /** End the session (wrong account, or none of these estates). */
  onSignOut: () => Promise<void> | void;
}

/** Human label for the membership role, mirroring the team vocabulary. */
function roleLabel(estate: EstateSummary): string {
  const parts: string[] = [];
  if (estate.role) {
    parts.push(estate.role.charAt(0).toUpperCase() + estate.role.slice(1));
  }
  if (estate.is_operator) {
    parts.push('Operator');
  }
  return parts.join(' · ');
}

export function EstatePicker({ estates, onSelect, onSignOut }: EstatePickerProps): JSX.Element {
  const firstOptionRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    firstOptionRef.current?.focus();
  }, []);

  return (
    <div className="nvoos-login">
      <div className="nvoos-login__card">
        <div className="nvoos-login__header">
          <span className="nvoos-login__mark">
            <img src={nvoosIcon} alt="" />
          </span>
          <h1 className="nvoos-login__title" id="nvoos-estate-picker-title">
            Choose an estate
          </h1>
          <p className="nvoos-login__subtitle">
            This account belongs to more than one estate. Pick the one this
            session should work in.
          </p>
        </div>

        <ul
          className="nvoos-estate-picker__list"
          aria-labelledby="nvoos-estate-picker-title"
        >
          {estates.map((estate, index) => {
            const detail = roleLabel(estate);
            return (
              <li key={estate.id}>
                <button
                  type="button"
                  ref={index === 0 ? firstOptionRef : undefined}
                  className="nvoos-estate-picker__option"
                  onClick={() => onSelect(estate.id)}
                >
                  <span className="nvoos-estate-picker__name">
                    {estate.name || estate.id}
                  </span>
                  {detail && (
                    <span className="nvoos-estate-picker__role">{detail}</span>
                  )}
                </button>
              </li>
            );
          })}
        </ul>

        <button
          type="button"
          className="nvoos-login__secondary nvoos-estate-picker__signout"
          onClick={() => void onSignOut()}
        >
          Sign out
        </button>
      </div>
    </div>
  );
}
