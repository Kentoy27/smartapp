self.addEventListener('install', function (event) {
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', function (event) {
    var request = event.request;
    var url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin || request.mode !== 'navigate') {
        return;
    }

    event.respondWith(fetch(request).catch(function () {
        var homeUrl = new URL('home', self.registration.scope).href;

        return new Response(
            '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f1f5f9"><title>Offline — SmartApp</title><style>body{font:16px system-ui,sans-serif;max-width:34rem;margin:15vh auto;padding:24px;color:#1e293b}h1{font-size:1.5rem}p{line-height:1.6;color:#475569}a{color:#1d4ed8}</style><h1>You are offline</h1><p>SmartApp could not load this page. Reconnect to the internet and try again.</p><a href="' + homeUrl + '">Try again</a></html>',
            { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
        );
    }));
});
