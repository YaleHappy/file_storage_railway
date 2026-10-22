<?php
require __DIR__.'/../share_expiry.php';
$checks=0;
foreach(['2026-02-30T10:00','garbage','2026-13-01T00:00','2026-01-01T25:00','2026-01-01T10:00extra'] as $value){try{parseShareExpiry($value);throw new RuntimeException('Invalid date accepted');}catch(InvalidArgumentException $error){$checks++;}}
if(parseShareExpiry('2028-02-29T10:30')!=='2028-02-29 10:30:00')throw new RuntimeException('Valid leap date rejected');$checks++;
if(parseShareExpiry('')!==null)throw new RuntimeException('Explicit empty expiry must remain null');$checks++;
echo "Share expiry: $checks checks passed.\n";
