<?php
// student/components/header.php
?>
<div style="display:flex;align-items:center;gap:12px">
  <button class="btn btn-sm btn-outline-primary d-lg-none menu-toggle"><i class="bi bi-list"></i></button>
  <h5 style="margin:0">Student Portal</h5>
</div>

<div style="display:flex;align-items:center;gap:14px">
  <div style="position:relative">
    <button class="btn btn-light btn-sm"><i class="bi bi-bell"></i></button>
    <span style="position:absolute; top:-6px; right:-6px" class="badge bg-danger badge-pill">1</span>
  </div>
  <div style="display:flex;align-items:center;gap:8px">
    <div style="width:36px;height:36px;border-radius:999px;background:var(--primary);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700">
      S
    </div>
    <div style="font-weight:600"><?php echo htmlspecialchars($user['name'] ?? 'Student'); ?></div>
  </div>
</div>
