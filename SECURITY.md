# Security policy

## Report a problem privately

Please don't open a public issue for a security problem.

- Use GitHub's **private vulnerability reporting**: on the repository, open the **Security** tab and choose **Report a vulnerability**.
- Or email **support@facilo.es** with "Security" in the subject.

Tell us what you found, which version, and how to repeat it. Never send real API keys or customer data. Test data is enough.

We read every report and answer as soon as we can. We don't promise a response time. If a report suggests a flaw is being actively exploited, we treat it as urgent.

## How fixes are released

1. We confirm the problem and prepare a fix on the `1.x` branch.
2. We release a patch version, for example 1.0.1, and describe the fix in `CHANGELOG.md`. We say who found it, if you want to be named.
3. We publish the details after a fix is available, so people have time to update.
4. Each release comes with a software bill of materials (CycloneDX).

Fixes come to you through the normal Craft update. Lite is free and always gets updates. A Pro site must hold a current update licence to receive updates. Licences are perpetual, so a site that stops renewing keeps what it has, but gets no updates, including security fixes.

## Support period

Version 1.x (for Craft 5) gets security fixes for 5 years from the release of 1.0.0, or for as long as Craft 5 is supported, whichever ends first.

When that period ends, we will say so in the changelog. Only the latest 1.x release is supported.

Version 2.x (for Craft 6) is in beta until Craft 6 is released. Beta versions get fixes in the next beta, not patch releases.

## How Rumbo Maps protects your site

- Server API keys are never written to your pages or the log. Browser keys appear in pages by design, so restrict them to your site's address in the provider's console.
- Apple's private key and Google's URL-signing secret stay on the server. Pages get a short-lived Apple token (30 minutes) that only works for your site's address.
- The map code of Google, Mapbox, HERE or Apple is loaded from that provider's own servers, and only when you choose that provider. With the free OpenStreetMap maps, no third-party script is loaded.
- Everything that comes from a map or address provider is escaped before it's shown.
- The public store finder checks every input (radius 0 to 500, 1 to 100 results, searches of 2 to 120 characters) and allows 30 searches a minute from one visitor, without storing IP addresses.
- **Check my templates** only reads files inside your template folders and never runs them.
- CSV import is for admins only, up to 10 MB.

## Scope

In scope: the Rumbo Maps plugin code in this repository.

Out of scope: Craft CMS itself, map and address providers, your server, and third-party libraries (report those to their authors, but tell us too if they affect Rumbo Maps).

## No guarantee

We build Rumbo Maps with care, but we can't promise it is free of flaws. The software is provided as is, under the terms in `LICENSE.md`.
