<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

date_default_timezone_set('Europe/Oslo');

$HA_URL = 'http://localhost:8123';
$HA_TOKEN = '';
$MARKET_DATA_FILE = __DIR__ . '/market-data.json';

$CITY_LOCATIONS = [
    ['name'=>'LONDON',    'lat'=>51.5074,  'lon'=>-0.1278],
    ['name'=>'MALAGA',    'lat'=>36.7213,  'lon'=>-4.4214],
    ['name'=>'CASCAIS',   'lat'=>38.6979,  'lon'=>-9.4215],
    ['name'=>'MARILIA',   'lat'=>-22.2171, 'lon'=>-49.9501],
    ['name'=>'FARSUND',   'lat'=>58.0954,  'lon'=>6.8040],
    ['name'=>'TOKYO',     'lat'=>35.6762,  'lon'=>139.6503],
    ['name'=>'NEW YORK',  'lat'=>40.7128,  'lon'=>-74.0060],
    ['name'=>'DOHA',      'lat'=>25.2854,  'lon'=>51.5310],
    ['name'=>'MELBOURNE', 'lat'=>-37.8136, 'lon'=>144.9631],
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
        $symbol = preg_replace('/[^\p{L}0-9.\/-]/u', '', (string)($row['symbol'] ?? ''));
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

function loadMarketFeed($path) {
    $empty = ['updated'=>'--:--', 'stocks'=>[], 'fx'=>[], 'commodities'=>[]];
    if (!is_readable($path)) return $empty;

    $raw = @file_get_contents($path);
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data)) return $empty;

    $updated = preg_replace('/[^0-9:.-]/', '', (string)($data['updated'] ?? '--:--'));
    return [
        'updated' => substr($updated ?: '--:--', 0, 16),
        'stocks' => normalizeMarketRows($data['stocks'] ?? [], 4, true),
        'fx' => normalizeMarketRows($data['fx'] ?? [], 2, true),
        'commodities' => normalizeMarketRows($data['commodities'] ?? [], 3, true),
    ];
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
        $cities[] = [
            'name' => $location['name'],
            'temp' => $valid ? round((float)$current['temperature_2m'], 1) : 0.0,
            'icon' => $valid ? wmoIcon($current['weather_code'] ?? 3) : 'cloud',
            'valid' => $valid,
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
$market = loadMarketFeed($MARKET_DATA_FILE);
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
