Showing weather, markets, currency, commodities and crypto on the Seeed reTerminal E1002 color ePaper display.

## Dashboard

The 800×480 screen uses two persistent columns plus a full-width city strip:

- Current weather, an eight-hour temperature/rain chart and five daily forecasts on the left.
- S&P 500, Nasdaq Composite, DAX and OSEBX; SEK/NOK, EUR/NOK and USD/NOK; Brent oil, gold and silver; plus SOL, ETH and BTC quoted in USD on the right. Every numeric series has an eight-point sparkline.
- Current temperature, weather icon and local 24-hour time for London, Malaga, Cascais, Marilia, Farsund, Tokyo, New York, Doha and Melbourne along the bottom.
- Last update remains above a compact status line with Wi-Fi signal strength, battery percentage and IP address in the lower-right corner.

The thirteen market rows retain the 14px font, using a 19px row pitch and 14px-high sparklines. The weather column, nine-city strip and status lines keep their existing positions.

The layout only uses the E1002 panel's six native colors: black, white, red, green, blue and yellow. Text is rendered with one-bit fonts and charts use solid two-pixel lines; there are no gradients or simulated gray surfaces. Weather icons are built from layered circles, rectangles and lines, with black cloud outlines, sun rays, rain drops, lightning, fog, wind and snow details.

## Browser preview

`docs/index.html` is a single self-contained page that renders the dashboard the way the display
lambda draws it: the same coordinates, the same Bresenham lines and midpoint circles, one-bit text
without antialiasing, and a framebuffer that can only hold the panel's six colors. It carries the
illustrative values in the same format as `data-feed-example.json` and can switch between the muted Spectra 6 pigments and
the raw RGB constants the YAML sets.

The page reads in Norwegian by default, with English, Portuguese and Chinese a click away at the
top. The panel inside it stays Norwegian in every language, because those labels are drawn by the
firmware rather than by the page.

Viewing it needs nothing but a browser:

<https://aisenseapi.github.io/reterminal-e1002-esphome-tagent/>

GitHub Pages serves that address from the `/docs` folder on `main`, so pushing to this branch
updates the live page within a minute or two.

The panel colors in the preview are an estimate of how the pigments read in room light rather than
measured values, and text can sit a pixel or two off, because the browser and ESPHome derive font
height slightly differently.

## E1002 refresh policy

The Spectra 6 panel has no partial refresh and a full redraw takes roughly 15 to 20 seconds. The default scheduled update is therefore 30 minutes. The green hardware button can request an immediate refresh. Avoid short refresh intervals, especially on battery power.

The API restart timer is set to `0s`. With ESPHome's default of 15 minutes the device restarts whenever no Home Assistant client is connected, and every restart is a full redraw. Battery, Wi-Fi strength and IP address are read immediately before each redraw.

The current ESPHome configuration keeps Wi-Fi and the Home Assistant API active, so it is intended primarily for USB-C power. Reaching the advertised long battery runtime would require a separate deep-sleep profile; while asleep, the device cannot react immediately to Home Assistant or button-driven network actions.

## Data flow

`tagent-data-eink.php` collects local weather data from Home Assistant, current temperatures for all nine cities in one batched Open-Meteo request, and market data for the configured indices, currencies, commodities and cryptocurrencies. Quotes may be delayed. Open-Meteo does not require an API key for this request, but PHP must have outbound HTTPS and `allow_url_fopen` enabled.

Market data is fetched from Yahoo Finance's chart endpoint and cached for 15 minutes in `market-data.json`. PHP cURL is preferred because it retrieves all thirteen series concurrently; a stream-based fallback is included. A failed refresh keeps the previous cache rather than replacing it with partial data. Adding or changing a configured series triggers an immediate refresh, even if the existing cache is less than 15 minutes old. Yahoo's endpoint is convenient for a personal dashboard but is not a contracted market-data service and may change; use a licensed provider before relying on the values commercially or for trading decisions.

USD/NOK uses Yahoo's `NOK=X` ticker. The currency rows remain ordered SEK/NOK, EUR/NOK, USD/NOK so the older firmware can continue reading its first two rows. The optional `market.crypto` group contains SOL, ETH and BTC, in that order, using Yahoo's `SOL-USD`, `ETH-USD` and `BTC-USD` tickers. Each row includes its USD price, percentage change and up to eight sparkline points. Older caches without crypto remain usable if an upstream request fails, with `crypto: []` in the response.

PHP can be updated before the firmware; displaying the third currency, crypto, city times and revised status line requires the new firmware. Wi-Fi strength, battery and IP address come from the device itself. All data collection remains in PHP; no Python updater is required.

The Wi-Fi percentage is an RSSI quality estimate, clamped to 0-100% using `2 * (RSSI + 100)` as in the [ESPHome Wi-Fi signal example](https://esphome.io/components/sensor/wifi_signal/). All status values and city clocks show the snapshot at the last display refresh; the screen does not redraw every minute.

Open-Meteo data is CC BY 4.0 and requires attribution. The display therefore includes `WX OPEN-METEO.COM` next to the city section. The free endpoint is suitable for non-commercial prototyping; review Open-Meteo's current licence or commercial plan before commercial deployment.

Copy `market-data-example.json` to `market-data.json` only when an offline display test is needed. The values in the example are display/test data, not live quotes. Once the cache is older than 15 minutes, `tagent-data-eink.php` attempts to replace it with live delayed data.

`data-feed-example.json` shows the combined response consumed by ESPHome.

---

A part of the TAGENT Private Edge Office Stack | An AI Agent Platform Project by AI VISIONS

https://www.linkedin.com/feed/update/urn:li:activity:7447711407745200129/
