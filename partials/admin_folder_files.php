<?php if($section==='folders' && $uid && $folder!==null): $insideFiles=adminListData($pdo,'files','',1,$uid,$folder); ?>
<section class="admin-editor"><h2>此資料夾的檔案（<?= (int)$insideFiles['total'] ?>）</h2>
<?php if(!$insideFiles['total']): ?><p class="admin-total">這一層沒有檔案。</p><?php endif; ?>
<?php foreach($insideFiles['items'] as $insideFile): ?><article class="admin-storage-file"><div><strong><?= ah($insideFile['original_filename']) ?></strong><small><?= ah($insideFile['full_path']) ?> · <?= adminSize($insideFile['file_size']) ?></small></div><div class="admin-actions"><a class="btn btn-sm btn-soft" href="preview.php?admin=1&amp;file_id=<?= (int)$insideFile['id'] ?>" target="_blank" rel="noopener">預覽</a><?= adminButton('deleteFile',(int)$insideFile['id'],'刪除',"永久刪除檔案？\n擁有者：".$insideFile['username']."\n路徑：".$insideFile['full_path'].' / '.$insideFile['original_filename']."\n分享連結也會移除，獨立副本保留。") ?></div></article><?php endforeach; ?>
<?php if($insideFiles['pages']>1): ?><a href="<?= ah(adminUrl(['section'=>'user_files','q'=>''])) ?>">查看全部 <?= (int)$insideFiles['total'] ?> 個檔案</a><?php endif; ?></section>
<?php endif; ?>
