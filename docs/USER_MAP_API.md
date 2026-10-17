# webOS User Map API

A feed of webOS users who have chosen to appear on a community map, as GeoJSON you can drop straight onto an OpenStreetMap-based map.

## Request

```
GET https://appcatalog.webosarchive.org/WebService/getUserMap.php
X-Api-Key: <your key>
```

Send the key in the `X-Api-Key` header. If you can't set headers, `?key=<your key>` works too, but it ends up in URLs and logs, so prefer the header.

```bash
curl -H "X-Api-Key: $USER_MAP_KEY" https://appcatalog.webosarchive.org/WebService/getUserMap.php
```

**Keep the key secret.** If your map runs in the browser, don't put the key in your front-end JavaScript where anyone can read it. Fetch the feed from your own server and serve it to your page from there (caching it is a good idea anyway; see below).

## Response

A standard GeoJSON `FeatureCollection` (`Content-Type: application/geo+json`). Each feature is one user:

```json
{
  "type": "FeatureCollection",
  "generated_at": "2026-10-17T13:21:37+00:00",
  "pending": 0,
  "features": [
    {
      "type": "Feature",
      "geometry": { "type": "Point", "coordinates": [-100.4459, 39.7837] },
      "properties": {
        "username": "codepoet",
        "place": "United States",
        "precision": "country",
        "last_seen": "2026-10-17"
      }
    }
  ]
}
```

| Field | Meaning |
|---|---|
| `geometry.coordinates` | `[longitude, latitude]`: GeoJSON order, **longitude first** |
| `properties.username` | The user's webOS Account username |
| `properties.place` | A readable label: `"Wichita, United States"`, `"Kansas, United States"`, or `"United States"` |
| `properties.precision` | `city`, `state`, or `country`: how precise the point is |
| `properties.last_seen` | Date (UTC, `YYYY-MM-DD`) the user's location was last seen |
| `pending` | Opted-in users whose location is still being looked up. They show up on a later request |
| `generated_at` | When this response was built |

### How locations work

- Users opt in themselves, on their account page, and choose a precision: **nearest large city** (100,000+ people), **state/province**, or **country**. They can opt out at any time, and they drop off the feed when they do.
- The point is always the **center of a named place** (the city, state, or country), never the user's actual position. Many users share the same point, so expect stacked markers; clustering helps (see below).
- Locations come from the user's webOS devices checking for app updates, so a user appears after their first update check after opting in. Anyone not seen in 12 months drops off.
- If a user's chosen precision can't be worked out, they're shown at the next coarser one. `precision` always says what you actually got.

## Errors

| Status | Body | Meaning |
|---|---|---|
| `401` | `{"error":"Invalid API key."}` | Missing or wrong key |
| `429` | rate-limit message | Too many requests (limit: 120 per hour per IP) |
| `503` | `{"error":"The user map is not enabled."}` | The feed is switched off on the server |
| `500` | `{"error":"Unable to build the user map."}` | Server problem. Try again later |

## Caching

The data changes slowly (users show up after update checks), and responses are marked cacheable for 5 minutes. Refresh every 5–15 minutes on your server and serve your copy, rather than calling the API for every page view.

## Quick start: Leaflet + OpenStreetMap

```html
<!DOCTYPE html>
<html>
<head>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
  <style>html, body, #map { height: 100%; margin: 0; }</style>
</head>
<body>
  <div id="map"></div>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
  <script>
    const map = L.map('map').setView([30, 0], 2);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors; city data <a href="https://www.geonames.org/">GeoNames</a>'
    }).addTo(map);

    // Your own server's copy of the feed, so the API key stays private.
    fetch('/webos-users.geojson')
      .then(r => r.json())
      .then(data => {
        const layer = L.geoJSON(data, {
          onEachFeature: (f, marker) => {
            const p = f.properties;
            const name = document.createElement('b');
            name.textContent = p.username;   // textContent: never inject usernames as HTML
            const box = document.createElement('div');
            box.append(name, document.createElement('br'), p.place);
            marker.bindPopup(box);
          }
        });
        L.markerClusterGroup().addLayer(layer).addTo(map);
      });
  </script>
</body>
</html>
```

## Attribution

Please credit **OpenStreetMap contributors** (map tiles and state/country centers) and **GeoNames** (city points, CC BY 4.0) on the map, as in the example above.

Questions or problems: webosarchive@gmail.com
