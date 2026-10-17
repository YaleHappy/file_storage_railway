<div class="dropdown navbar-notifications ms-auto me-2 order-lg-3">
  <button class="btn btn-outline-light position-relative" type="button" id="notificationDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="通知">
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M8 16a2 2 0 0 0 2-2H6a2 2 0 0 0 2 2M8 1.918l-.797.161A4 4 0 0 0 4 6c0 .628-.134 2.197-.459 3.742-.16.767-.376 1.566-.663 2.258h10.244c-.287-.692-.502-1.49-.663-2.258C12.134 8.197 12 6.628 12 6a4 4 0 0 0-3.203-3.92zM14.22 12c.223.447.481.801.78 1H1c.299-.199.557-.553.78-1C2.68 10.2 3 6.88 3 6a5 5 0 0 1 4-4.9 1 1 0 1 1 2 0A5 5 0 0 1 13 6c0 .88.32 4.2 1.22 6"/></svg>
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
