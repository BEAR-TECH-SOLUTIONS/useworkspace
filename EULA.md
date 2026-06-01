# End User License Agreement

**usework.space Self-Hosted**
**Effective Date: TBD**

> **This is a template.** Every section flagged with `<!-- LEGAL -->`
> must be reviewed and adjusted by a qualified lawyer before this
> agreement is offered to any customer. The technical claims (license
> verification, telemetry allow-list, phone-home behaviour) match the
> shipped product; the commercial and legal terms are placeholders.

---

This End User License Agreement (this "Agreement") is a binding contract
between you (either an individual or the legal entity you represent, "You" or
"Customer") and **usework.space** (the "Licensor", "We", "Us"). By installing,
accessing, or using the usework.space Self-Hosted software (the "Software") You
agree to be bound by this Agreement.

If You do not agree, do not install the Software. The installer enforces
acceptance by requiring You to type "yes" before continuing.

## 1. Definitions

- **"Software"** — the usework.space Self-Hosted application distributed by Us as a
  Docker image and/or source code repository, including all updates and
  modifications We make available.
- **"License Key"** — the Ed25519-signed token We issue to You, required for
  the Software to operate beyond a trial or grace period.
- **"Instance"** — a single deployment of the Software running against a single
  database, identified by an `instance_id` generated at install time.
- **"Documentation"** — installation guides, API references, and operator
  runbooks We make available at <https://usework.space>.

## 2. License Grant

Subject to Your compliance with this Agreement and payment of the applicable
fees, We grant You a non-exclusive, non-transferable, non-sublicensable license
to install and operate one Instance of the Software for Your internal business
purposes, for the duration of the License Key's validity period.

<!-- LEGAL: confirm whether the license is per-instance, per-organisation, or
     per-user. Adjust the scope above to match commercial terms. -->

## 3. License Key & Verification

3.1 The Software requires a valid License Key to operate. The License Key is
issued by Us and bound to Your organisation. Each Instance must report a unique
`instance_id` on activation.

3.2 The Software verifies the License Key locally on every authenticated
request using a public key embedded in the distribution. The Software also
contacts Our license-verification endpoint hourly to confirm the key has not
been revoked.

3.3 If the License Key expires, is revoked, or cannot be verified for more
than seven (7) consecutive days, the Software will refuse authenticated
requests until verification succeeds. Read access to underlying data via
direct database tools is unaffected — no data is destroyed.

## 4. Telemetry

The Software's hourly phone-home transmits the following fields to Us and
nothing else:

- `license_id`
- `instance_id`
- `instance_url` (the public hostname You configured)
- `instance_version` (the deployed image tag)
- `member_count` (aggregate count of workspace members)
- `project_count` (aggregate count of projects)
- A SHA-256 hash of the requesting IP address, salted with a server-side pepper

We never receive Your project names, user emails, vault contents, document
contents, expense data, or any other content created by Your users. This is the
trust contract — any change to this allow-list will be disclosed in the release
notes.

You may inspect the phone-home implementation in the public source repository
at `app/Modules/SelfHosted/Console/Commands/PhoneHomeCommand.php`.

## 5. Permitted Use

You may:

- (a) install, configure, and operate one Instance of the Software for Your
  organisation's internal business purposes;
- (b) modify the Software's source code for Your own use, provided the
  modifications do not bypass or disable license verification;
- (c) make backups of Your data and configuration;
- (d) authorise Your employees, contractors, and end users to access the
  Software, provided their use complies with this Agreement.

## 6. Restrictions

You may not:

- (a) operate more than one Instance per License Key without Our prior written
  consent;
- (b) bypass, disable, or remove the license-verification, telemetry, or other
  enforcement mechanisms;
- (c) make the Software available to third parties as a hosted service that
  substitutes for Our commercial offering (this restriction also appears in the
  Functional Source License governing the source code);
- (d) sublicense, rent, lease, sell, or otherwise transfer the Software or
  License Key to a third party;
- (e) use the Software in any manner that violates applicable law.

## 7. Updates & Support

<!-- LEGAL: spell out concrete support obligations. Below is a placeholder. -->

7.1 During the License Key's validity period, We will make Software updates
(security patches and feature releases) available to You via the same
distribution channel from which You obtained the Software.

7.2 We are not obligated to provide installation assistance, custom
development, or on-call support unless a separate support agreement is in
effect.

## 8. Fees & Term

<!-- LEGAL: insert pricing terms, payment schedule, renewal, cancellation. -->

8.1 The License Key is issued for the term and at the price stated on Your
order. The current published pricing for the Self-Hosted plan is available at
<https://usework.space/pricing>.

8.2 We may revoke the License Key for non-payment, breach of this Agreement,
or violation of applicable law.

## 9. Intellectual Property

The Software, the License Key signing infrastructure, and all related
trademarks remain Our property. This Agreement grants You a license, not an
ownership interest. See `TRADEMARK.md` for trademark terms.

## 10. Warranty Disclaimer

THE SOFTWARE IS PROVIDED "AS IS" WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING WITHOUT LIMITATION WARRANTIES OF MERCHANTABILITY, FITNESS
FOR A PARTICULAR PURPOSE, AND NON-INFRINGEMENT.

## 11. Limitation of Liability

<!-- LEGAL: set the liability cap appropriate to Your business and
     jurisdiction. Common pattern: capped at fees paid in the prior 12 months. -->

IN NO EVENT WILL OUR AGGREGATE LIABILITY UNDER THIS AGREEMENT EXCEED THE FEES
PAID BY YOU FOR THE LICENSE KEY IN THE TWELVE (12) MONTHS PRECEDING THE EVENT
GIVING RISE TO THE CLAIM. WE WILL NOT BE LIABLE FOR ANY INDIRECT, INCIDENTAL,
CONSEQUENTIAL, SPECIAL, OR PUNITIVE DAMAGES, INCLUDING LOST PROFITS, LOST
DATA, OR BUSINESS INTERRUPTION.

## 12. Termination

12.1 This Agreement terminates automatically if You materially breach it and
fail to cure the breach within thirty (30) days of written notice.

12.2 Upon termination, You must cease all use of the Software and uninstall
all Instances. Your data remains Yours; this Agreement does not grant Us any
right to it.

## 13. Governing Law

<!-- LEGAL: insert governing-law / venue / dispute-resolution clauses
     appropriate to Your legal entity's jurisdiction. -->

## 14. Contact

Legal: <legal@usework.space>
Sales: <sales@usework.space>
Support: <support@usework.space>

---

By typing "yes" at install time, You acknowledge that You have read this
Agreement, understand it, and agree to be bound by its terms.
