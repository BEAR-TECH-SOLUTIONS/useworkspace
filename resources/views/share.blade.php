<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex,nofollow" />
    <meta name="referrer" content="no-referrer" />
    <meta name="theme-color" content="#08070D" />
    <title>Shared content — usework.space</title>

    <script nonce="{{ request()->attributes->get('csp_nonce') }}">
        // Runtime config injected by the backend so a single built
        // bundle can serve both:
        //   - self-hosted / local: same-origin API calls (BASE = '')
        //   - cloud share.usework.space: API hosted elsewhere
        // The nonce attribute pins this inline script under the
        // strict CSP set by SetShareLinkHeaders (audit M3).
        window.__TC_SHARE_API_BASE__ = @json($apiBase ?? '');
    </script>

    @vite(['resources/share/share.css', 'resources/share/main.jsx'])
</head>
<body>
    <div id="root"></div>
</body>
</html>
