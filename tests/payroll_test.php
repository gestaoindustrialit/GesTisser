<?php
require dirname(__DIR__) . '/payroll_lib.php';
function check_shift($from,$to,$nightExpected,$dayExpected){$base='2026-09-01 ';$a=new DateTimeImmutable($base.$from);$b=new DateTimeImmutable($base.$to);if($b<=$a)$b=$b->modify('+1 day');$night=payroll_night_seconds($a,$b,'22:00','07:00')/3600;$day=($b->getTimestamp()-$a->getTimestamp())/3600-$night;if(abs($night-$nightExpected)>.001||abs($day-$dayExpected)>.001)throw new RuntimeException("$from-$to expected day=$dayExpected night=$nightExpected, got day=$day night=$night");echo "$from-$to: day=$day night=$night\n";}
check_shift('08:00','17:00',0,9);check_shift('14:00','23:00',1,8);check_shift('22:00','06:00',8,0);check_shift('20:00','04:00',6,2);check_shift('21:00','06:00',8,1);check_shift('05:00','10:00',2,3);
