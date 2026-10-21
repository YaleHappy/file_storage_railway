<div class="dropdown navbar-notifications ms-2">
  <button class="btn notification-trigger position-relative" type="button" id="notificationDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="通知">
    <span class="notification-bell" aria-hidden="true">🔔</span>
    <span class="mobile-notification-label">通知</span>
    <span class="position-absolute badge rounded-pill bg-danger notification-badge" style="display:none"><span class="notification-count">0</span><span class="visually-hidden">則未讀通知</span></span>
  </button>
  <div class="dropdown-menu dropdown-menu-end notification-dropdown" aria-labelledby="notificationDropdown">
    <div class="d-flex justify-content-between align-items-center p-2 border-bottom">
      <h6 class="m-0">通知</h6>
      <button type="button" class="btn btn-sm btn-link text-info" id="clearAllNotifications">全部標為已讀</button>
    </div>
    <div class="notifications-container" aria-live="polite"></div>
  </div>
</div>
<script src="assets/js/mobile_navigation.js?v=20261021-1" defer></script>
