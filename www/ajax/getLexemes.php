<?php
require_once '../../lib/Core.php';

$query = Request::get('term');
$parts = preg_split('/\(/', $query, 2);
$name = trim($parts[0]);
$field = Str::hasDiacritics($name) ? 'formNoAccent' : 'formUtf8General';

if (count($parts) == 2) {
  $description = trim($parts[1]);
  $description = str_replace(')', '', $description);
  $lexemes = Model::factory('Lexeme')->where($field, $name)->where_like('description', "{$description}%")
    ->order_by_asc('formNoAccent')->order_by_asc('description')->limit(10)->find_many();
} else {
  $lexemes = Model::factory('Lexeme')->where_like($field, "{$name}%")->order_by_asc('formNoAccent')->limit(10)->find_many();
}

$resp = ['results' => []];
foreach ($lexemes as $l) {
  $resp['results'][] = [
    'id' => $l->id,
    'text' => (string)$l,
    'consistentAccent' => $l->consistentAccent,
    'hasParadigm' => $l->hasParadigm(),
  ];
}

header('Content-Type: application/json');
print json_encode($resp);
