<?php

// curl 'https://sheethub.com/area.reference.tw/中華民國行政區_map_名稱2014?format=csv' > area_2014.csv
$fp = fopen('area_2014.csv', 'r');
$areas = array();
while ($rows = fgetcsv($fp)) {
    $areas[$rows[0]] = $rows[1];
}
fclose($fp);

// 郵遞區號 http://data.gov.tw/node/5948
// curl 'http://download.post.gov.tw/post/download/Zip32_csv_10409_utf8.csv' > postcode.csv
$fp = fopen('postcode.csv', 'r');
$output_roads = fopen('data/road.csv', 'w');
$roads = array();
$unit_map = array('巷' => 'LANE', '弄' => 'ALLEY', '號' => 'NUMBER');
while ($rows = fgetcsv($fp)) {
    list($postcode, $county, $town, $road, $address) = $rows;
    if (!$area_id = $areas[$county . $town]) {
        $areas[$county . $town] = true;
        error_log("找不到 {$county} {$town}");
        continue;
    }

    if ($area_id[0] == '6') {
        $county_id = substr($area_id, 0, 2);
    } else {
        $county_id = substr($area_id, 0, 5);
    }

    $number_map = array('０', '１', '２', '３', '４', '５', '６', '７', '８', '９');
    foreach ($number_map as $num => $big_num) {
        $road = str_replace($big_num, $num, $road);
    }
    $rules = array();
    if (preg_match('#^(.*)([0-9]+)段$#', $road, $matches)) {
        $rules[] = '=SECTION:' . $matches[2];
        $road = $matches[1];
    }

    if (!array_key_exists($county_id . '-' . $road, $roads)) {
        fputcsv($output_roads, array($county_id, $road));
        file_put_contents("data/{$county_id}-{$road}.csv", "");
    }
    $roads[$county_id . '-' . $road] = true;

    $origin_address = $address;
    $address = preg_replace('#至[ ]+#', '至', $address);
    $address = preg_replace('#之[ ]+#', '之', $address);
    $address = preg_replace_callback('#([單雙])([0-9])#u', function($m) { return $m[1] . ' ' . $m[2]; }, $address);
    $address = preg_replace_callback('#([0-9]+號)([0-9]+樓)#', function($m) { return $m[1] . ' ' . $m[2]; }, $address);
    $address = preg_replace_callback('#連([0-9]+)#', function($m) { return $m[1]; }, $address);
    $address = str_replace('　', ' ', $address);
    $address = str_replace('  ', ' ', $address);
    $terms = preg_split('#[ ]+#u', trim($address, '  '));
    foreach ($terms as $term) {
        if ($term == '全') {
            $rules[] = 'all';
        } elseif (in_array($term, array('單全', '單'))) {
            $rules[] = 'odd';
        } elseif (in_array($term, array('雙全', '雙'))) {
            $rules[] = 'even';
        } elseif ($term == '連') {
        } elseif (preg_match('#^([0-9]+)樓以下$#', $term, $matches)) {
            $rules[] = '<FLOOR:' . $matches[1];
        } elseif (preg_match('#^([0-9]+)樓以上$#', $term, $matches)) {
            $rules[] = '>FLOOR:' . $matches[1];
        } elseif (preg_match('#^([0-9]+(之[0-9]+)?)([號巷弄])以下$#u', $term, $matches)) {
            $rules[] = '<' . $unit_map[$matches[3]] . ':' . $matches[1];
        } elseif (preg_match('#^([0-9]+(之[0-9]+)?)([號巷弄])以上$#u', $term, $matches)) {
            $rules[] = '>' . $unit_map[$matches[3]] . ':' . $matches[1];
        } elseif (preg_match('#^([0-9]+(之[0-9]+)?)號$#', $term, $matches)) {
            $rules[] = '=NUMBER:' . $matches[1];
        } elseif (preg_match('#^([0-9]+)([巷弄])全?連?$#u', $term, $matches)) {
            $rules[] = '=' . $unit_map[$matches[2]] . ':' . $matches[1];
        } elseif (preg_match('#^([0-9]+)([巷弄])雙全?$#u', $term, $matches)) {
            $rules[] = 'even';
            $rules[] = '=' . $unit_map[$matches[2]] . ':' . $matches[1];
        } elseif (preg_match('#^([0-9]+[巷弄])單全?$#u', $term, $matches)) {
            $rules[] = 'odd';
            $rules[] = '=' . $unit_map[$matches[2]] . ':' . $matches[1];
        } elseif (preg_match('#^([0-9]+(之[0-9]+)?)([巷弄號])至([0-9]*(之[0-9]+)?)([巷弄號])$#u', $term, $matches)) {
            if ($matches[3] != $matches[6]) {
                $unit = ($matches[3] == '號') ? $matches[6] : $matches[3];
                $rules[] = '~' . $unit_map[$unit] . ':' . $matches[1] . ':' . $matches[4];
            } else {
                $rules[] = '~' . $unit_map[$matches[3]] . ':' . $matches[1] . ':' . $matches[4];
            }
        } elseif (preg_match('#^([0-9]+)之([0-9]+)至之([0-9]+)號#', $term, $matches)) {
            $rules[] = "~NUMBER:{$matches[1]}之{$matches[2]}:{$matches[1]}之{$matches[3]}";
        } elseif (preg_match('#^([0-9]+)至([0-9]+)樓$#', $term, $matches)) {
            $rules[] = "~FLOOR:{$matches[1]}:{$matches[3]}";
        } elseif (strpos($term, '附號')) {
            // TODO
        } else {
            //echo $term . "\n";
        }
    }
    file_put_contents("data/{$county_id}-{$road}.csv", implode(',', array(
        $postcode,
        $area_id,
        $origin_address,
        implode(';', $rules),
    )) . "\n", FILE_APPEND);
}
