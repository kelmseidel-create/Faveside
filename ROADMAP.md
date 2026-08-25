# Faveside Launch Roadmap

## Current build

Faveside currently has a responsive marketing site (`index.html`) and an interactive creator dashboard (`app.html`). The dashboard already includes Today, My Creators, Hot 5, Catch Me Up, and Parent Controls views, with device-local preference storage.

## Work that can move forward before Square is connected

### 1. Launch List — production backend required
- Keep the polished signup UI on the marketing site.
- Connect submissions to a server-side endpoint/database before public launch.
- Normalize email addresses and reject duplicates.
- Add basic abuse/rate protection.
- Store consent timestamp and signup source.
- Return friendly success, duplicate, and error states.
- Never put private API keys or secrets in client-side HTML/JavaScript.

### 2. Accounts & authentication — backend required
- Email/password or passwordless sign-in.
- Account profile and onboarding.
- Persist followed creators and preferences across devices.
- Track entitlement states: `free`, `premium`, `complimentary`, `past_due`, `canceled`.
- Keep authentication/session tokens out of localStorage when a production auth provider is selected.

### 3. Parent Controls — flagship Premium feature
- Parent profile protected by a PIN/re-authentication.
- Child profiles.
- Per-child approved creator list.
- Parent-only ability to add/remove approved creators.
- Clear child/restricted mode.
- Server-side enforcement in production; UI hiding alone is not access control.

### 4. Square-ready subscription layer
- Keep payment-provider logic behind a small server-side billing interface.
- Store Square customer/subscription identifiers server-side.
- Verify Square webhook signatures server-side before changing entitlements.
- Never expose Square access tokens in the browser.
- Map paid subscription status to Faveside entitlement status.

### 5. Promotions / Family & Friends
- Support promotions as server-side records rather than hard-coded browser codes.
- Suggested fields: code hash, discount type, discount value, duration, max redemptions, expiration, active flag.
- Allow a 100% complimentary Premium entitlement for Family & Friends.
- Track redemptions per account.
- Make codes revocable without redeploying the site.

### 6. App experience
- Continue improving Today, My Creators, Hot 5, Catch Me Up, Search, Creator detail, Settings, and Parent Controls.
- Replace demo creator/trend data with approved platform/API data as integrations become available.
- Add loading, empty, offline, and error states.

### 7. Launch readiness
- Privacy Policy and Terms of Service.
- Parent/child privacy and consent review before enabling child accounts.
- Support/contact flow.
- Favicon/app icons and social sharing metadata.
- Analytics with privacy-conscious event tracking.
- Accessibility and mobile QA.
- Error monitoring and backups for production data.

## Recommended implementation order

1. Choose production backend/auth/database.
2. Make Launch List persistence real.
3. Add account authentication and cross-device persistence.
4. Move Parent Controls enforcement server-side.
5. Connect Square subscriptions and webhooks.
6. Add server-side promotions/complimentary access.
7. Replace demo creator data with live integrations.
8. Complete legal, analytics, accessibility, and launch QA.

## Security rule

All secrets — Square tokens, API credentials, webhook secrets, database admin credentials, and private promotion logic — must remain server-side and must never be committed to this repository or embedded in public HTML/JavaScript.
