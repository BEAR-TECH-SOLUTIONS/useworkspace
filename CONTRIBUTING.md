# Contributing

This repository is a **read-only public mirror** of usework.space's source code.
It exists so that operators and security teams can audit what they are
installing on their own infrastructure.

## We do not accept pull requests

The mirror is force-pushed from our private GitLab repository on every
release. Any pull request you open against this repository will be silently
overwritten by the next sync — even if a maintainer comments on it, there is
no path to merge it into the canonical source.

Issues opened here are not actively monitored. We respond to bug reports and
feature requests through the channels listed below instead.

## Reporting bugs

Open a support ticket at **<support@usework.space>** with:

- the version tag You are running (`usework version`),
- steps to reproduce,
- the output of `usework logs app | tail -200`,
- any relevant `.env` keys (redacted — never paste secrets).

## Reporting security vulnerabilities

Please **do not** open public issues for security-sensitive findings.

Email **<security@usework.space>** with a description of the vulnerability,
affected versions, and reproduction steps. We aim to acknowledge reports
within two business days and to ship a fix as quickly as severity warrants.

If you would like to receive credit in our release notes, include the name
and contact you would like us to use. Anonymous reports are welcome too.

## Feature requests

Email **<feedback@usework.space>** or use the in-app feedback form
(Settings → Feedback) on a hosted instance.

## License

The source in this repository is governed by the Functional Source License,
Version 1.1 (Apache 2.0 Future License). See `LICENSE.md`. Operational use
of the Software requires acceptance of the End User License Agreement in
`EULA.md`.
