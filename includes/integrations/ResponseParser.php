<?php
class ResponseParser { public static function path($data, string $path) { if($path==='') return $data; foreach(explode('.',$path) as $p){ if(!is_array($data)||!array_key_exists($p,$data)) return null; $data=$data[$p]; } return $data; } }
