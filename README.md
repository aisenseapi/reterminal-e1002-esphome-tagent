Showing weather, markets, currency and commodities on the Seeed reTerminal E1002 color ePaper display.

## Dashboard

The 800×480 screen uses two persistent columns plus a full-width city strip:

- Current weather, an eight-hour temperature/rain chart and five daily forecasts on the left.
- Four stocks/indices, two currency pairs, Brent oil, gold and silver on the right. Every numeric series has an eight-point sparkline.
- Current temperature and weather icon for London, Malaga, Cascais, Marilia, Farsund, Tokyo, New York, Doha and Melbourne along the bottom.
- Last update, IP address and battery are kept as a compact three-line block in the lower-right corner.

The layout only uses the E1002 panel's six native colors: black, white, red, green, blue and yellow. Text is rendered with one-bit fonts and charts use solid two-pixel lines; there are no gradients or simulated gray surfaces. Weather icons are built from layered circles, rectangles and lines, with black cloud outlines, sun rays, rain drops, lightning, fog, wind and snow details.

## Browser preview

`docs/index.html` is a single self-contained page that renders the dashboard the way the display
lambda draws it: the same coordinates, the same Bresenham lines and midpoint circles, one-bit text
without antialiasing, and a framebuffer that can only hold the panel's six colors. It carries the
example values from `data-feed-example.json` and can switch between the muted Spectra 6 pigments and
the raw RGB constants the YAML sets.

The page reads in Norwegian by default, with English, Portuguese and Chinese a click away at the
top. The panel inside it stays Norwegian in every language, because those labels are drawn by the
firmware rather than by the page.

Viewing it needs nothing but a browser:

<https://htmlpreview.github.io/?https://github.com/aisenseapi/reterminal-e1002-esphome-tagent/blob/main/docs/index.html>

GitHub Pages is not enabled on this repository, so
<https://aisenseapi.github.io/reterminal-e1002-esphome-tagent/> returns 404 today. Turning it on
under Settings -> Pages, serving branch `main` from the `/docs` folder, would publish the same page
at that address without a third-party proxy, and that is the better link to hand out.

The panel colors in the preview are an estimate of how the pigments read in room light rather than
measured values, and text can sit a pixel or two off, because the browser and ESPHome derive font
height slightly differently.

## E1002 refresh policy

The Spectra 6 panel has no partial refresh and a full redraw takes roughly 15–20 seconds. The default scheduled update is therefore 30 minutes. The green hardware button can request an immediate refresh. Avoid short refresh intervals, especially on battery power.

The current ESPHome configuration keeps Wi-Fi and the Home Assistant API active, so it is intended primarily for USB-C power. Reaching the advertised long battery runtime would require a separate deep-sleep profile; while asleep, the device cannot react immediately to Home Assistant or button-driven network actions.

## Data flow

`weather-eink.php` collects local weather data from Home Assistant, current temperatures for all nine cities in one batched Open-Meteo request, and an optional normalized market snapshot from `market-data.json`. Open-Meteo does not require an API key for this request, but PHP must have outbound HTTPS and `allow_url_fopen` enabled. Market API keys and provider-specific code should stay on the server rather than on the ESP32.

Open-Meteo data is CC BY 4.0 and requires attribution. The display therefore includes `WX OPEN-METEO.COM` next to the city section. The free endpoint is suitable for non-commercial prototyping; review Open-Meteo's current licence or commercial plan before commercial deployment.

Copy `market-data-example.json` to `market-data.json` to exercise the full layout. The values in the example are display/test data, not live quotes. A future provider integration only needs to keep this JSON contract updated.

`data-feed-example.json` shows the combined response consumed by ESPHome.

---

A part of the TAGENT Private Edge Office Stack | An AI Agent Platform Project by AI VISIONS

https://www.linkedin.com/feed/update/urn:li:activity:7447711407745200129/
