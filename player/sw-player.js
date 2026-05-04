const STATIC_CACHE = "wisetv-player-static-v2";
const MEDIA_CACHE = "wisetv-player-media-v2";
const STATIC_FILES = [
  "./index.html"
];

function canonicalPlaylistRequest(requestUrl) {
  const canonical = new URL(requestUrl.toString());
  canonical.searchParams.delete("t");
  return new Request(canonical.toString(), { method: "GET" });
}

function isMediaRequest(url) {
  return url.pathname.indexOf("/midias/") !== -1;
}

function isPlaylistRequest(url) {
  return url.pathname.indexOf("/player/playlist.php") !== -1 || url.pathname.endsWith("/playlist.php");
}

function parseRange(rangeHeader, totalLength) {
  if (!rangeHeader || rangeHeader.indexOf("bytes=") !== 0) return null;
  const range = rangeHeader.replace("bytes=", "").split("-");
  let start = Number(range[0]);
  let end = range[1] ? Number(range[1]) : totalLength - 1;
  if (Number.isNaN(start)) start = 0;
  if (Number.isNaN(end) || end >= totalLength) end = totalLength - 1;
  if (start > end || start >= totalLength) return null;
  return { start, end };
}

async function cachedRangeResponse(request, cachedResponse) {
  const rangeHeader = request.headers.get("range");
  if (!rangeHeader) return cachedResponse;

  const contentType = cachedResponse.headers.get("content-type") || "application/octet-stream";
  const buffer = await cachedResponse.arrayBuffer();
  const totalLength = buffer.byteLength;
  const parsed = parseRange(rangeHeader, totalLength);

  if (!parsed) {
    return new Response(null, {
      status: 416,
      statusText: "Range Not Satisfiable",
      headers: { "Content-Range": `bytes */${totalLength}` }
    });
  }

  const partial = buffer.slice(parsed.start, parsed.end + 1);
  return new Response(partial, {
    status: 206,
    statusText: "Partial Content",
    headers: {
      "Content-Type": contentType,
      "Content-Length": String(parsed.end - parsed.start + 1),
      "Content-Range": `bytes ${parsed.start}-${parsed.end}/${totalLength}`,
      "Accept-Ranges": "bytes"
    }
  });
}

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE).then((cache) => cache.addAll(STATIC_FILES))
  );
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys.map((key) => {
          if (key !== STATIC_CACHE && key !== MEDIA_CACHE) {
            return caches.delete(key);
          }
          return Promise.resolve();
        })
      )
    )
  );
  self.clients.claim();
});

self.addEventListener("fetch", (event) => {
  if (event.request.method !== "GET") return;

  const url = new URL(event.request.url);
  if (url.origin !== self.location.origin) return;

  if (isPlaylistRequest(url)) {
    event.respondWith(
      fetch(event.request)
        .then(async (response) => {
          const cache = await caches.open(STATIC_CACHE);
          const key = canonicalPlaylistRequest(url);
          cache.put(key, response.clone());
          return response;
        })
        .catch(async () => {
          const cache = await caches.open(STATIC_CACHE);
          const key = canonicalPlaylistRequest(url);
          const cached = await cache.match(key);
          if (cached) return cached;
          return new Response(JSON.stringify({ playlist: [] }), {
            status: 200,
            headers: { "Content-Type": "application/json; charset=utf-8" }
          });
        })
    );
    return;
  }

  if (isMediaRequest(url)) {
    event.respondWith(
      caches.open(MEDIA_CACHE).then(async (cache) => {
        const cached = await cache.match(event.request, { ignoreSearch: true, ignoreVary: true });
        if (cached) {
          return cachedRangeResponse(event.request, cached);
        }

        try {
          const network = await fetch(event.request);
          if (network && network.status === 200) {
            cache.put(event.request, network.clone());
          }
          return network;
        } catch (e) {
          return new Response("offline", { status: 503, statusText: "Offline" });
        }
      })
    );
    return;
  }

  event.respondWith(
    caches.match(event.request).then((cached) => cached || fetch(event.request))
  );
});
