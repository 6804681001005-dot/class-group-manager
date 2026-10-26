<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ระบบจัดกลุ่มนักเรียน</title>
  <link rel="stylesheet" href="assets/style.css?v=20261026e">
</head>
<body>
<header class="topbar">
  <div><strong>ระบบจัดกลุ่มนักเรียน</strong><small>Class & Group Manager</small></div>
  <button id="logoutBtn" class="secondary hidden">ออกจากระบบ</button>
</header>
<main class="container">
  <section id="authView" class="panel auth-grid">
    <div>
      <p class="eyebrow">WELCOME</p><h1>เข้าสู่ระบบ</h1>
      <p class="muted">เข้าสู่ระบบเพื่อจัดการห้องเรียนและกลุ่ม</p>
      <form id="loginForm">
        <label>อีเมล<input name="email" type="email" required autocomplete="username"></label>
        <label>รหัสผ่าน<input name="password" type="password" required autocomplete="current-password"></label>
        <button type="submit">เข้าสู่ระบบ</button>
      </form>
      <p id="loginMessage" class="message"></p>
    </div>
    <div class="register-box">
      <h2>สมัครบัญชีนักเรียน</h2>
      <p class="muted">บัญชีครูต้องสร้างโดยผู้ดูแลระบบ</p>
      <form id="registerForm">
        <label>ชื่อ-นามสกุล<input name="full_name" required maxlength="100"></label>
        <label>อีเมล<input name="email" type="email" required maxlength="255"></label>
        <label>รหัสผ่าน (อย่างน้อย 8 ตัว)<input name="password" type="password" required minlength="8"></label>
        <button type="submit" class="secondary">สมัครบัญชี</button>
      </form>
      <p id="registerMessage" class="message"></p>
    </div>
  </section>

  <section id="appView" class="hidden">
    <div class="welcome panel">
      <div><p class="eyebrow">DASHBOARD</p><h1 id="welcomeTitle">ยินดีต้อนรับ</h1><p id="roleLabel" class="muted"></p></div>
    </div>
    <div class="panel">
      <h2>ห้องเรียน</h2>
      <form id="createClassForm" class="inline-form hidden">
        <label>ชื่อห้องเรียน<input name="class_name" required maxlength="100" placeholder="เช่น ห้องเรียน A"></label>
        <label>รายละเอียดงาน/คำชี้แจง<textarea name="description" rows="3" maxlength="2000" placeholder="อธิบายงาน เป้าหมาย และสิ่งที่ต้องส่ง"></textarea></label>
        <button type="submit">สร้างห้องเรียน</button>
      </form>
      <form id="joinClassForm" class="inline-form hidden">
        <label>รหัสเข้าห้อง<input name="join_code" required maxlength="20" placeholder="กรอกรหัสจากครู"></label>
        <button type="submit">เข้าร่วมห้อง</button>
      </form>
      <p id="classMessage" class="message"></p>
      <section id="skillsPanel" class="panel hidden">
        <h2>ทักษะของฉัน</h2>
        <p class="muted">พิมพ์ทักษะที่คุณมีได้ตามต้องการ จะกรอกหรือเว้นว่างก็ได้ ครูสามารถใช้ข้อมูลนี้ประกอบการจัดกลุ่ม</p>
        <form id="skillsForm">
          <label>ทักษะของฉัน (ไม่บังคับ)
            <textarea id="skillsText" name="skills_text" rows="4" maxlength="2000" placeholder="เช่น PHP, HTML, CSS, ออกแบบกราฟิก, นำเสนอ"></textarea>
          </label>
          <button type="submit">บันทึกทักษะ</button>
          <p id="skillsMessage" class="message"></p>
        </form>
      </section>
      <div id="classList" class="class-list"></div>
    </div>
    <div id="classDetail" class="panel hidden">
      <div class="section-head"><div class="classroom-heading"><p class="eyebrow">CLASSROOM</p><div class="title-edit-row"><h2 id="detailTitle">รายละเอียดห้อง</h2><button type="button" id="editClassNameBtn" class="icon-button hidden" aria-label="แก้ไขชื่อห้อง" title="แก้ไขชื่อห้อง"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg></button></div><p id="joinCodeText" class="muted"></p><div class="description-heading"><strong>รายละเอียด</strong><button type="button" id="editClassDescriptionBtn" class="icon-button hidden" aria-label="แก้ไขรายละเอียด" title="แก้ไขรายละเอียด"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg></button></div><p id="classDescription" class="muted class-description-text"></p></div><button type="button" id="collapseClassBtn" class="secondary">ย่อรายละเอียด</button></div>
      <div id="teacherActions" class="hidden">
        <form id="randomizeForm" class="inline-form">
          <label>จำนวนคนต่อกลุ่ม<input name="group_size" type="number" min="2" max="20" value="4" required></label>
          <button type="submit">สุ่มกลุ่ม</button>
        </form>
      </div>
      <section class="collapsible-section">
        <button id="membersBtn" type="button" class="secondary collapsible-toggle" aria-expanded="false" aria-controls="membersArea"><span>ดูรายชื่อนักเรียน</span><span class="toggle-arrow" aria-hidden="true">⌄</span></button>
        <div id="membersArea" class="collapsible-content hidden" data-loaded="false"></div>
      </section>
      <section class="collapsible-section">
        <button id="groupsBtn" type="button" class="secondary collapsible-toggle" aria-expanded="false" aria-controls="groupsArea"><span>ดูผลการสุ่มกลุ่ม</span><span class="toggle-arrow" aria-hidden="true">⌄</span></button>
        <div id="groupsArea" class="collapsible-content hidden" data-loaded="false"></div>
      </section>
      <section class="collapsible-section">
        <button id="ratingsBtn" type="button" class="secondary collapsible-toggle" aria-expanded="false" aria-controls="ratingsArea"><span>ดูผลการประเมิน</span><span class="toggle-arrow" aria-hidden="true">⌄</span></button>
        <div id="ratingsArea" class="collapsible-content hidden" data-loaded="false"></div>
      </section>
    </div>
  </section>
  <div id="renameClassModal" class="modal-backdrop hidden" role="presentation">
    <section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="renameModalTitle">
      <div class="modal-heading"><div class="modal-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg></div><div><p class="eyebrow">CLASSROOM SETTINGS</p><h2 id="renameModalTitle">แก้ไขชื่อห้องเรียน</h2><p class="muted">เปลี่ยนเฉพาะชื่อห้องเรียน รายละเอียดห้องจะไม่เปลี่ยนแปลง</p></div></div>
      <form id="renameClassForm">
        <label for="renameClassInput">ชื่อห้องเรียนใหม่<input id="renameClassInput" name="class_name" required maxlength="100" placeholder="กรอกชื่อห้องเรียน" autocomplete="off"></label>
        <p id="renameClassMessage" class="message"></p>
        <div class="modal-actions"><button type="button" id="cancelRenameBtn" class="secondary">ยกเลิก</button><button type="submit" id="saveRenameBtn"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>บันทึกชื่อห้อง</button></div>
      </form>
    </section>
  </div>
  <div id="editDescriptionModal" class="modal-backdrop hidden" role="presentation">
    <section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="editDescriptionTitle">
      <div class="modal-heading"><div class="modal-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg></div><div><p class="eyebrow">CLASSROOM SETTINGS</p><h2 id="editDescriptionTitle">แก้ไขรายละเอียดห้องเรียน</h2><p class="muted">แก้ไขคำชี้แจงหรือรายละเอียดของห้องเรียนนี้</p></div></div>
      <form id="editDescriptionForm">
        <label for="editDescriptionInput">รายละเอียด<textarea id="editDescriptionInput" name="description" rows="5" maxlength="2000" placeholder="กรอกรายละเอียดห้องเรียน"></textarea></label>
        <p id="editDescriptionMessage" class="message"></p>
        <div class="modal-actions"><button type="button" id="cancelDescriptionBtn" class="secondary">ยกเลิก</button><button type="submit" id="saveDescriptionBtn"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>บันทึกรายละเอียด</button></div>
      </form>
    </section>
  </div>
  <div id="toast" class="toast" role="status"></div>
</main>
<footer>ระบบต้นแบบสำหรับการเรียนการสอน · ตรวจสอบฐานข้อมูลก่อนใช้กับข้อมูลจริง</footer>
<script src="assets/app.js?v=20261026i"></script>
</body>
</html>
