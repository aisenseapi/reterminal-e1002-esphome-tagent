<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

date_default_timezone_set('Europe/Oslo');

$HA_URL = 'http://localhost:8123';
$HA_TOKEN = '';

$OSLO = new DateTimeZone('Europe/Oslo');

function mapIcon($c) {
    $m = ['clear-night'=>'night','cloudy'=>'cloud','exceptional'=>'sunny','fog'=>'cloud','hail'=>'rain','lightning'=>'rain','lightning-rainy'=>'rain','partlycloudy'=>'partly','pouring'=>'rain','rainy'=>'rain','snowy'=>'snow','snowy-rainy'=>'rain','sunny'=>'sunny','windy'=>'cloud','windy-variant'=>'cloud'];
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

$ho = []; $c = 0;
foreach ($hf as $f) {
    if ($c >= 5) break;
    $ft = (new DateTime($f['datetime'] ?? ''))->setTimezone($OSLO);
    if ($ft <= $now) continue;
    $ho[] = ['t'=>$ft->format('H:i'),'icon'=>mapIcon($f['condition']??'cloudy'),'temp'=>ftemp($f['temperature']??0)];
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

$out = "{\n";
$out .= '  "wx_loc": ' . json_encode('Aker brygge, Oslo') . ",\n";
$out .= '  "wx_now": ' . json_encode(wxNow($w['state'])) . ",\n";
$out .= sprintf('  "wx_temp": %.1f', ftemp($a['temperature']??0)) . ",\n";
$out .= '  "wx_hum": ' . (int)($a['humidity']??0) . ",\n";
$out .= '  "wx_icon": ' . json_encode(mapIcon($w['state'])) . ",\n";
if ($wx_temp_in !== null) {
    $out .= sprintf('  "wx_temp_in": %.1f', $wx_temp_in) . ",\n";
}
$out .= "  \"hourly\": [\n";
foreach ($ho as $i => $h) {
    $c = $i < count($ho)-1 ? ',' : '';
    $out .= sprintf('    {"t": %s, "icon": %s, "temp": %.1f}%s', json_encode($h['t']), json_encode($h['icon']), $h['temp'], $c) . "\n";
}
$out .= "  ],\n";
$out .= "  \"daily\": [\n";
foreach ($da as $i => $d) {
    $c = $i < count($da)-1 ? ',' : '';
    $out .= sprintf('    {"d": %s, "icon": %s, "hi": %.1f, "lo": %.1f}%s', json_encode($d['d']), json_encode($d['icon']), $d['hi'], $d['lo'], $c) . "\n";
}
$out .= "  ]\n}\n";

echo $out;
