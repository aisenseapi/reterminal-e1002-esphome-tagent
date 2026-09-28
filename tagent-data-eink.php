<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

date_default_timezone_set('Europe/Oslo');

$HA_URL = 'http://localhost:8123';
$HA_TOKEN = '';
$MARKET_DATA_FILE = __DIR__ . '/market-data.json';
$MARKET_CACHE_SECONDS = 15 * 60;
$MARKET_SERIES = [
    'stocks' => [
        ['label'=>'S&P',    'ticker'=>'^GSPC'],
        ['label'=>'NASDAQ', 'ticker'=>'^IXIC'],
        ['label'=>'DAX',    'ticker'=>'^GDAXI'],
        ['label'=>'OSEBX',  'ticker'=>'OSEBX.OL'],
    ],
    'fx' => [
        ['label'=>'SEK/NOK', 'ticker'=>'SEKNOK=X'],
        ['label'=>'EUR/NOK', 'ticker'=>'EURNOK=X'],
        ['label'=>'USD/NOK', 'ticker'=>'NOK=X'],
    ],
    'commodities' => [
        ['label'=>'BRENT', 'ticker'=>'BZ=F'],
        ['label'=>'GULL',  'ticker'=>'GC=F'],
        ['label'=>'SØLV',  'ticker'=>'SI=F'],
    ],
    'crypto' => [
        ['label'=>'SOL', 'ticker'=>'SOL-USD'],
        ['label'=>'ETH', 'ticker'=>'ETH-USD'],
        ['label'=>'BTC', 'ticker'=>'BTC-USD'],
    ],
];

$CITY_LOCATIONS = [
    ['name'=>'LONDON',    'lat'=>51.5074,  'lon'=>-0.1278,  'tz'=>'Europe/London'],
    ['name'=>'MALAGA',    'lat'=>36.7213,  'lon'=>-4.4214,  'tz'=>'Europe/Madrid'],
    ['name'=>'CASCAIS',   'lat'=>38.6979,  'lon'=>-9.4215,  'tz'=>'Europe/Lisbon'],
    ['name'=>'MARILIA',   'lat'=>-22.2171, 'lon'=>-49.9501, 'tz'=>'America/Sao_Paulo'],
    ['name'=>'FARSUND',   'lat'=>58.0954,  'lon'=>6.8040,   'tz'=>'Europe/Oslo'],
    ['name'=>'TOKYO',     'lat'=>35.6762,  'lon'=>139.6503, 'tz'=>'Asia/Tokyo'],
    ['name'=>'NEW YORK',  'lat'=>40.7128,  'lon'=>-74.0060, 'tz'=>'America/New_York'],
    ['name'=>'DOHA',      'lat'=>25.2854,  'lon'=>51.5310,  'tz'=>'Asia/Qatar'],
    ['name'=>'MELBOURNE', 'lat'=>-37.8136, 'lon'=>144.9631, 'tz'=>'Australia/Melbourne'],
];

$OSLO = new DateTimeZone('Europe/Oslo');

function mapIcon($c) {
    $m = ['clear-night'=>'night','cloudy'=>'cloud','exceptional'=>'sunny','fog'=>'fog','hail'=>'storm','lightning'=>'storm','lightning-rainy'=>'storm','partlycloudy'=>'partly','pouring'=>'rain','rainy'=>'rain','snowy'=>'snow','snowy-rainy'=>'rain','sunny'=>'sunny','windy'=>'wind','windy-variant'=>'wind'];
    return $m[$c] ?? 'cloud';
}

function wxNow($c) {
    $m = ['clear-night'=>'Klart','cloudy'=>'Skyet','exceptional'=>'Sol','fog'=>'Tåke','hail'=>'Hagl','lightning'=>'Torden','lightning-rainy'=>'Torden/regn','partlycloudy'=>'Delvis skyet','pouring'=>'Kraftig regn','rainy'=>'Regn','snowy'=>'Snø','snowy-rainy'=>'Sludd','sunny'=>'Sol','windy'=>'Vind','windy-variant'=>'Vind'];
    return $m[$c] ?? $c;
}

function dLabel($d) {
    global $OSLO;
    $l = ['søndag','mandag','tirsdag','onsdag','torsdag','fredag','lørdag'];
    $dt = (new DateTime($d))->setTimezone($OSLO);
    $ds = $dt->format('Y-m-d');
    $now = (new DateTime('now', $OSLO))->format('Y-m-d');
    if ($ds === $now) return 'i dag';
    $tom = (new DateTime('+1 day', $OSLO))->format('Y-m-d');
    if ($ds === $tom) return 'i morgen';
    return $l[(int)$dt->format('w')];
}

function ha($u, $post = null) {
    global $HA_TOKEN;
    $o = ['http' => ['header' => "Authorization: Bearer $HA_TOKEN\r\nAccept: application/json\r\n", 'timeout' => 8]];
    if ($post) { $o['http']['method'] = 'POST'; $o['http']['content'] = json_encode($post); $o['http']['header'] .= "Content-Type: application/json\r\n"; }
    $r = @file_get_contents($u, false, stream_context_create($o));
    return $r ? json_decode($r, true) : null;
}

function ftemp($v) { return round((float)$v, 1); }

function normalizeMarketRows($rows, $limit, $withSpark = false) {
    if (!is_array($rows)) return [];

    $out = [];
    foreach (array_slice($rows, 0, $limit) as $row) {
        if (!is_array($row)) continue;
        $symbol = preg_replace('/[^\p{L}0-9.&\/-]/u', '', (string)($row['symbol'] ?? ''));
        if ($symbol === '') continue;

        $item = [
            'symbol' => strtoupper(substr($symbol, 0, 10)),
            'price' => round((float)($row['price'] ?? 0), 4),
            'change' => round((float)($row['change'] ?? 0), 2),
        ];

        if ($withSpark) {
            $spark = is_array($row['spark'] ?? null) ? $row['spark'] : [];
            $item['spark'] = array_map(
                static fn($value) => round((float)$value, 4),
                array_slice($spark, 0, 8)
            );
        }
        $out[] = $item;
    }
    return $out;
}

function normalizeMarketFeed($data) {
    $empty = ['updated'=>'--:--', 'stocks'=>[], 'fx'=>[], 'commodities'=>[], 'crypto'=>[]];
    if (!is_array($data)) return $empty;

    $updated = preg_replace('/[^0-9:.-]/', '', (string)($data['updated'] ?? '--:--'));
    return [
        'updated' => substr($updated ?: '--:--', 0, 16),
        'stocks' => normalizeMarketRows($data['stocks'] ?? [], 4, true),
        'fx' => normalizeMarketRows($data['fx'] ?? [], 3, true),
        'commodities' => normalizeMarketRows($data['commodities'] ?? [], 3, true),
        'crypto' => normalizeMarketRows($data['crypto'] ?? [], 3, true),
    ];
}

function readMarketCache($path) {
    if (!is_readable($path)) return null;
    $raw = @file_get_contents($path);
    $data = $raw ? json_decode($raw, true) : null;
    return is_array($data) ? $data : null;
}

function marketFeedMatchesSeries($data, $series) {
    if (!is_array($data)) return false;
    foreach ($series as $group => $entries) {
        if (!is_array($data[$group] ?? null)) return false;
        if (array_column($data[$group], 'symbol') !== array_column($entries, 'label')) return false;
    }
    return true;
}

function sampleMarketSpark($values, $limit = 8) {
    $numeric = [];
    foreach ((array)$values as $value) {
        if (is_numeric($value)) $numeric[] = (float)$value;
    }
    $count = count($numeric);
    if ($count <= $limit) return $numeric;

    $sampled = [];
    for ($i = 0; $i < $limit; $i++) {
        $index = (int)round($i * ($count - 1) / ($limit - 1));
        $sampled[] = $numeric[$index];
    }
    return $sampled;
}

function yahooChartUrl($ticker) {
    return 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($ticker)
        . '?interval=30m&range=5d';
}

function fetchMarketCharts($tickers) {
    $responses = [];

    // Fetch all thirteen series concurrently when PHP cURL is available. This
    // keeps the first uncached dashboard request inside the ESP32 timeout.
    if (function_exists('curl_multi_init')) {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($tickers as $ticker) {
            $handle = curl_init(yahooChartUrl($ticker));
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'User-Agent: Mozilla/5.0 (compatible; E1002Dashboard/1.0)',
                ],
            ]);
            curl_multi_add_handle($multi, $handle);
            $handles[$ticker] = $handle;
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running && $status === CURLM_OK) {
                $selected = curl_multi_select($multi, 1.0);
                if ($selected === -1) usleep(100000);
            }
        } while ($running && $status === CURLM_OK);

        foreach ($handles as $ticker => $handle) {
            $body = curl_multi_getcontent($handle);
            $httpCode = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($httpCode === 200 && $body !== false) {
                $decoded = json_decode($body, true);
                if (is_array($decoded)) $responses[$ticker] = $decoded;
            }
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }
        curl_multi_close($multi);
        return $responses;
    }

    // Portable fallback for servers without php-curl.
    $context = stream_context_create(['http'=>[
        'header'=>"Accept: application/json\r\nUser-Agent: Mozilla/5.0 (compatible; E1002Dashboard/1.0)\r\n",
        'timeout'=>3,
    ]]);
    foreach ($tickers as $ticker) {
        $body = @file_get_contents(yahooChartUrl($ticker), false, $context);
        $decoded = $body ? json_decode($body, true) : null;
        if (is_array($decoded)) $responses[$ticker] = $decoded;
    }
    return $responses;
}

function marketRowFromChart($label, $ticker, $payload) {
    $chart = $payload['chart'] ?? null;
    if (!is_array($chart) || !empty($chart['error'])) return null;
    $result = $chart['result'][0] ?? null;
    if (!is_array($result)) return null;

    $meta = $result['meta'] ?? [];
    $closes = $result['indicators']['quote'][0]['close'] ?? [];
    $spark = sampleMarketSpark($closes, 8);
    $price = is_numeric($meta['regularMarketPrice'] ?? null)
        ? (float)$meta['regularMarketPrice']
        : ($spark ? (float)end($spark) : null);
    if ($price === null) return null;

    if (is_numeric($meta['regularMarketChangePercent'] ?? null)) {
        $change = (float)$meta['regularMarketChangePercent'];
    } else {
        $previous = is_numeric($meta['previousClose'] ?? null) ? (float)$meta['previousClose'] : 0.0;
        $change = $previous != 0.0 ? (($price - $previous) / $previous) * 100.0 : 0.0;
    }

    return [
        'symbol'=>$label,
        'price'=>round($price, 4),
        'change'=>round($change, 2),
        'spark'=>array_map(static fn($value) => round((float)$value, 4), $spark ?: [$price]),
    ];
}

function fetchLiveMarketFeed($series) {
    $tickers = [];
    foreach ($series as $entries) {
        foreach ($entries as $entry) $tickers[] = $entry['ticker'];
    }
    $responses = fetchMarketCharts(array_values(array_unique($tickers)));

    $feed = [
        'updated'=>date('H:i'),
        'stocks'=>[],
        'fx'=>[],
        'commodities'=>[],
        'crypto'=>[],
    ];
    foreach ($series as $group => $entries) {
        foreach ($entries as $entry) {
            $ticker = $entry['ticker'];
            $row = isset($responses[$ticker])
                ? marketRowFromChart($entry['label'], $ticker, $responses[$ticker])
                : null;
            // All-or-nothing prevents a temporary provider failure from
            // replacing a complete cache with a partial dashboard.
            if ($row === null) return null;
            $feed[$group][] = $row;
        }
    }
    return $feed;
}

function writeMarketCache($path, $data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $temporary = $path . '.tmp.' . getmypid();
    if (@file_put_contents($temporary, $json . "\n", LOCK_EX) === false) return false;
    @chmod($temporary, 0644);
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        return false;
    }
    return true;
}

function loadMarketFeed($path, $series, $cacheSeconds) {
    $cached = readMarketCache($path);
    $modified = @filemtime($path);
    // A changed series list (for example adding crypto) refreshes immediately,
    // even when the old cache is still within its normal 15-minute lifetime.
    if (marketFeedMatchesSeries($cached, $series)
        && $modified !== false && (time() - $modified) < $cacheSeconds) {
        return normalizeMarketFeed($cached);
    }

    $fresh = fetchLiveMarketFeed($series);
    if ($fresh !== null) {
        writeMarketCache($path, $fresh);
        return normalizeMarketFeed($fresh);
    }

    // Retain the previous snapshot on failure, including older schemas without
    // crypto or the third FX row. Missing optional groups normalize to [].
    return normalizeMarketFeed($cached);
}

function wmoIcon($code) {
    $code = (int)$code;
    if ($code === 0) return 'sunny';
    if ($code <= 2) return 'partly';
    if ($code === 3) return 'cloud';
    if ($code === 45 || $code === 48) return 'fog';
    if ($code >= 71 && $code <= 77) return 'snow';
    if ($code === 85 || $code === 86) return 'snow';
    if ($code >= 95) return 'storm';
    if (($code >= 51 && $code <= 67) || ($code >= 80 && $code <= 82)) return 'rain';
    return 'cloud';
}

function loadCityWeather($locations) {
    $latitudes = implode(',', array_column($locations, 'lat'));
    $longitudes = implode(',', array_column($locations, 'lon'));
    $query = http_build_query([
        'latitude' => $latitudes,
        'longitude' => $longitudes,
        'current' => 'temperature_2m,weather_code',
    ], '', '&', PHP_QUERY_RFC3986);

    $context = stream_context_create(['http'=>[
        'header'=>"Accept: application/json\r\nUser-Agent: reterminal-e1002-dashboard\r\n",
        'timeout'=>8,
    ]]);
    $raw = @file_get_contents("https://api.open-meteo.com/v1/forecast?$query", false, $context);
    $response = $raw ? json_decode($raw, true) : null;
    if (isset($response['current'])) $response = [$response];
    if (!is_array($response)) $response = [];

    $cities = [];
    foreach ($locations as $index => $location) {
        $current = $response[$index]['current'] ?? [];
        $valid = is_numeric($current['temperature_2m'] ?? null);
        try {
            $localTime = (new DateTimeImmutable('now', new DateTimeZone($location['tz'])))->format('H:i');
        } catch (Exception $exception) {
            $localTime = '--:--';
        }
        $cities[] = [
            'name' => $location['name'],
            'temp' => $valid ? round((float)$current['temperature_2m'], 1) : 0.0,
            'icon' => $valid ? wmoIcon($current['weather_code'] ?? 3) : 'cloud',
            'valid' => $valid,
            'time' => $localTime,
        ];
    }
    return $cities;
}

$w = ha("$HA_URL/api/states/weather.forecast_home");
if (!$w) { http_response_code(500); echo json_encode(['error'=>'HA unreachable']); exit; }
$a = $w['attributes'] ?? [];

$ti = ha("$HA_URL/api/states/sensor.tz2000_a476raq2_ts0201_001_temperatur");
$wx_temp_in = $ti ? ftemp($ti['state'] ?? 0) : null;

$hr = ha("$HA_URL/api/services/weather/get_forecasts?return_response", ['entity_id'=>'weather.forecast_home','type'=>'hourly']);
$dr = ha("$HA_URL/api/services/weather/get_forecasts?return_response", ['entity_id'=>'weather.forecast_home','type'=>'daily']);

$hf = $hr['service_response']['weather.forecast_home']['forecast'] ?? [];
$df = $dr['service_response']['weather.forecast_home']['forecast'] ?? [];

$now = new DateTime('now', $OSLO);
$market = loadMarketFeed($MARKET_DATA_FILE, $MARKET_SERIES, $MARKET_CACHE_SECONDS);
$cities = loadCityWeather($CITY_LOCATIONS);

$ho = []; $c = 0;
foreach ($hf as $f) {
    if ($c >= 8) break;
    $ft = (new DateTime($f['datetime'] ?? ''))->setTimezone($OSLO);
    if ($ft <= $now) continue;
    $ho[] = [
        't'=>$ft->format('H:i'),
        'icon'=>mapIcon($f['condition']??'cloudy'),
        'temp'=>ftemp($f['temperature']??0),
        'rain'=>(int)($f['precipitation_probability']??0),
    ];
    $c++;
}

$da = []; $s = []; $c = 0; $td = $now->format('Y-m-d');
foreach ($df as $f) {
    if ($c >= 5) break;
    $ft = (new DateTime($f['datetime']??''))->setTimezone($OSLO);
    $ds = $ft->format('Y-m-d');
    if ($ds <= $td || isset($s[$ds])) continue;
    $s[$ds] = true;
    $da[] = ['d'=>dLabel($ds),'icon'=>mapIcon($f['condition']??'cloudy'),'hi'=>ftemp($f['temperature']??0),'lo'=>ftemp($f['templow']??0)];
    $c++;
}

$out = [
    'wx_loc'       => 'Aker brygge, Oslo',
    'wx_now'       => wxNow( $w['state'] ),
    'wx_temp'      => round( ftemp( $a['temperature'] ?? 0 ), 1 ),
    'wx_hum'       => ( int )( $a['humidity'] ?? 0 ),
    'wx_icon'      => mapIcon( $w['state'] ),
    'hourly'       => [],
    'daily'        => [],
    'market'       => $market,
    'cities'       => $cities,
];

if ( $wx_temp_in !== null ){
    $out['wx_temp_in'] = round( $wx_temp_in, 1 );
}

foreach( $ho as $h ){
    $out['hourly'][] = [
        't'    => $h['t'],
        'icon' => $h['icon'],
        'temp' => round( $h['temp'], 1 ),
        'rain' => $h['rain'],
    ];
}

foreach( $da as $d ){
    $out['daily'][] = [
        'd'    => $d['d'],
        'icon' => $d['icon'],
        'hi'   => round( $d['hi'], 1 ),
        'lo'   => round( $d['lo'], 1 ),
    ];
}

echo json_encode(
    $out,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
);
