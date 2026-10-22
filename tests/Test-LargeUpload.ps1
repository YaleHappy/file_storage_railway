param([Parameter(Mandatory=$true)][string]$Password,[switch]$BeforeFix)
Import-Module Microsoft.PowerShell.Utility
$ErrorActionPreference='Stop';$base='http://localhost/file_storage/';$session=[Microsoft.PowerShell.Commands.WebRequestSession]::new();$ids=@();$fixtures=@();$checks=0
$null=Invoke-WebRequest ($base+'login.php') -WebSession $session -Method Post -Body @{username='admin';password=$Password;authcode=('stust'+[TimeZoneInfo]::ConvertTimeBySystemTimeZoneId([DateTime]::UtcNow,'Taipei Standard Time').ToString('MMdd'));login=1}
try{
 $sizes=if($BeforeFix){@(46)}else{@(46,100,111)}
 foreach($size in $sizes){
  $path=Join-Path $env:TEMP ('upload-size-test-'+[guid]::NewGuid().ToString('N')+'.bin');$fixtures+=$path;$stream=[IO.File]::Create($path);$stream.SetLength($size*1MB);$stream.Dispose()
  $response=Invoke-WebRequest ($base+'upload_ajax.php') -WebSession $session -Method Post -Form @{'files[]'=(Get-Item $path);folder_id=0} -SkipHttpErrorCheck
  $json=$null;try{$json=$response.Content|ConvertFrom-Json}catch{}
  if($BeforeFix){"Before fix: 46 MiB upload; HTTP $($response.StatusCode); valid JSON: $($null -ne $json)";continue}
  if(!$json){throw "Size $size MiB returned non-JSON response"};$checks++
  if($size -le 100){
   if(!$json.success){throw "Size $size MiB failed: $($json.error)"};$checks++
   $name=[IO.Path]::GetFileName($path);$results=Invoke-RestMethod ($base+'cloud_search.php?q='+$name) -WebSession $session;$id=[int]$results.results[0].id;$ids+=$id
   $list=Invoke-RestMethod ($base+'admin.php?format=json&section=files&q='+$name) -WebSession $session
   if([long]$list.data.items[0].file_size -ne $size*1MB){throw 'Stored size differs from upload'};$checks++
  }else{if($json.success -or $response.StatusCode -ne 413){throw 'Oversized request was not rejected with JSON 413'};$checks++}
  "Upload $size MiB: HTTP $($response.StatusCode), valid JSON, success=$($json.success)"
 }
 if(!$BeforeFix){"Large upload: $checks checks passed."}
}finally{
 if($ids.Count){$null=Invoke-RestMethod ($base+'bulk_delete.php') -WebSession $session -Method Post -ContentType 'application/json' -Body (@{ids=$ids}|ConvertTo-Json -Compress)}
 foreach($path in $fixtures){Remove-Item -LiteralPath $path}
}
