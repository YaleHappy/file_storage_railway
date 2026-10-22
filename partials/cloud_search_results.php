<section id="cloudSearchResults" data-folder="<?= (int)$currentFolder ?>" aria-labelledby="cloudSearchHeading" hidden>
  <div class="cloud-search-toolbar">
    <div><h2 id="cloudSearchHeading">搜尋結果</h2><p id="cloudSearchStatus" role="status" aria-live="polite"></p></div>
    <div class="cloud-search-controls"><label class="visually-hidden" for="cloudSearchScope">搜尋範圍</label><select id="cloudSearchScope" class="form-select"><option value="cloud">我的整個雲端</option><option value="folder">目前資料夾（不含子資料夾）</option></select><button type="button" id="cloudSearchClear" class="btn storage-secondary">返回檔案</button></div>
  </div>
  <div id="cloudSearchList" class="cloud-search-list" aria-busy="false"></div>
  <nav class="cloud-search-pagination" aria-label="搜尋結果分頁"><button id="cloudSearchPrevious" type="button" class="btn storage-secondary" hidden>上一頁</button><span id="cloudSearchPage"></span><button id="cloudSearchNext" type="button" class="btn storage-secondary" hidden>下一頁</button></nav>
</section>
