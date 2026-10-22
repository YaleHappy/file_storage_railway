<?php
function parseShareExpiry(string $value): ?string {
 if($value==='')return null;
 $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value);
 $errors=DateTimeImmutable::getLastErrors();
 if(!$date || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d\TH:i')!==$value)throw new InvalidArgumentException('到期時間格式不正確，請重新選擇日期。');
 return $date->format('Y-m-d H:i:s');
}
