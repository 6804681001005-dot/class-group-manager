const API = 'api/index.php';
let currentUser = null;
let selectedClassroom = null;
let latestRunId = null;

const $ = (selector) => document.querySelector(selector);
const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
function safeReset(form) { if (form && typeof form.reset === 'function') form.reset(); }
function setSectionOpen(buttonSelector, areaSelector, isOpen) {
  const button = $(buttonSelector);
  const area = $(areaSelector);
  if (!button || !area) return;
  area.classList.toggle('hidden', !isOpen);
  button.setAttribute('aria-expanded', String(isOpen));
  const arrow = button.querySelector('.toggle-arrow');
  if (arrow) arrow.textContent = isOpen ? '⌃' : '⌄';
}
function resetClassSections() {
  [['#membersBtn','#membersArea'],['#groupsBtn','#groupsArea'],['#ratingsBtn','#ratingsArea']].forEach(([button,area]) => {
    setSectionOpen(button, area, false);
    const el = $(area);
    if (el) { el.innerHTML = ''; el.dataset.loaded = 'false'; }
  });
}
function toast(message) {
  const el = $('#toast'); el.textContent = message; el.classList.add('show');
  window.setTimeout(() => el.classList.remove('show'), 3200);
}
async function api(action, method='GET', data=null, params={}) {
  const url = new URL(API, window.location.href);
  url.searchParams.set('action', action);
  Object.entries(params).forEach(([k,v]) => url.searchParams.set(k, v));
  const options = {method, headers:{'Accept':'application/json'}};
  if (data !== null) { options.headers['Content-Type']='application/json'; options.body=JSON.stringify(data); }
  const response = await fetch(url, options);
  let result;
  try { result = await response.json(); } catch { throw new Error('เซิร์ฟเวอร์ส่งผลลัพธ์ไม่ใช่ JSON'); }
  if (!response.ok || result.success === false) throw new Error(result.message || 'เกิดข้อผิดพลาด');
  return result;
}
function showAuth() {
  currentUser=null; selectedClassroom=null; latestRunId=null;
  $('#authView').classList.remove('hidden'); $('#appView').classList.add('hidden');
  $('#logoutBtn').classList.add('hidden');
  ['#loginForm','#registerForm','#createClassForm','#joinClassForm','#randomizeForm','#skillsForm'].forEach(sel => safeReset($(sel)));
  const skillsMessage = $('#skillsMessage'); if (skillsMessage) skillsMessage.style.color = '';
  const ratingMessage = $('#ratingMessage'); if (ratingMessage) ratingMessage.textContent = '';
  $('#skillsText').value = '';
  ['#loginMessage','#registerMessage','#classMessage','#skillsMessage'].forEach(sel=>{const el=$(sel); if(el) el.textContent='';});
  $('#classList').innerHTML=''; $('#classDetail').classList.add('hidden'); resetClassSections();
}
function showApp(user) {
  currentUser = user;
  $('#authView').classList.add('hidden'); $('#appView').classList.remove('hidden');
  $('#logoutBtn').classList.remove('hidden');
  $('#welcomeTitle').textContent = `สวัสดี ${user.full_name}`;
  $('#roleLabel').textContent = user.role === 'teacher' ? 'บทบาท: ครู' : 'บทบาท: นักเรียน';
  $('#createClassForm').classList.toggle('hidden', user.role !== 'teacher');
  $('#joinClassForm').classList.toggle('hidden', user.role !== 'student');
  $('#teacherActions').classList.toggle('hidden', user.role !== 'teacher');
  $('#editClassNameBtn').classList.toggle('hidden', user.role !== 'teacher');
  $('#editClassDescriptionBtn').classList.toggle('hidden', user.role !== 'teacher');
  $('#skillsPanel').classList.toggle('hidden', user.role !== 'student');
  $('#classDetail').classList.add('hidden'); selectedClassroom=null; latestRunId=null;
  ['#loginForm','#registerForm'].forEach(sel => safeReset($(sel)));
  $('#classList').innerHTML = ''; resetClassSections();
  $('#classMessage').textContent = ''; $('#skillsMessage').textContent = ''; $('#skillsMessage').style.color = '';
  loadClassrooms();
  if(user.role==='student') loadSkills();
}
async function loadClassrooms() {
  const requestUser = currentUser;
  if (!requestUser) return;
  try {
    const result = await api('classrooms');
    if (currentUser !== requestUser) return;
    const list = $('#classList');
    if (!result.data.length) {
      list.innerHTML = '<p class="muted">ยังไม่มีห้องเรียน แนะนำให้ครูสร้างห้องเรียน หรือนักเรียนเข้าร่วมด้วยรหัสจากครู</p>';
      return;
    }
    list.innerHTML = result.data.map(c => `<article class="class-card">
      <h3>${escapeHtml(c.class_name)}</h3>
      ${currentUser.role==='teacher' && c.join_code ? `<p>รหัสเข้าห้อง: <strong>${escapeHtml(c.join_code)}</strong></p>` : ''}
      <button type="button" data-class="${escapeHtml(c.classroom_id)}" data-name="${escapeHtml(c.class_name)}" data-code="${escapeHtml(c.join_code || '')}" data-description="${escapeHtml(c.description || '')}">เปิดห้องเรียน</button>
      ${currentUser.role==='teacher' ? `<button type="button" class="secondary icon-label-button" data-rename-class="${escapeHtml(c.classroom_id)}" data-rename-name="${escapeHtml(c.class_name)}" aria-label="แก้ไขชื่อห้อง ${escapeHtml(c.class_name)}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg><span>แก้ไขชื่อ</span></button><button type="button" class="danger" data-delete-class="${escapeHtml(c.classroom_id)}" data-delete-name="${escapeHtml(c.class_name)}">ลบห้องเรียน</button>` : ''}
    </article>`).join('');
    list.querySelectorAll('button[data-class]').forEach(btn => btn.addEventListener('click', () => openClass({
      classroom_id:Number(btn.dataset.class), class_name:btn.dataset.name, join_code:btn.dataset.code, description:btn.dataset.description
    })));
    list.querySelectorAll('button[data-rename-class]').forEach(btn => btn.addEventListener('click', () => openRenameModal(Number(btn.dataset.renameClass), btn.dataset.renameName || '')));
    list.querySelectorAll('button[data-delete-class]').forEach(btn => btn.addEventListener('click', async () => {
      const name = btn.dataset.deleteName;
      if (!window.confirm(`ยืนยันลบห้องเรียน “${name}” และข้อมูลกลุ่ม/ผลประเมินในห้องนี้หรือไม่? บัญชีนักเรียนจะไม่ถูกลบ`)) return;
      try { await api('delete_classroom','POST',{classroom_id:Number(btn.dataset.deleteClass)});
        if (selectedClassroom && Number(selectedClassroom.classroom_id)===Number(btn.dataset.deleteClass)) { selectedClassroom=null; $('#classDetail').classList.add('hidden'); }
        toast('ลบห้องเรียนแล้ว'); await loadClassrooms();
      } catch(err) { toast(err.message); }
    }));
  } catch (e) { toast(e.message); }
}
async function openClass(c) {
  if (selectedClassroom && Number(selectedClassroom.classroom_id)===Number(c.classroom_id) && !$('#classDetail').classList.contains('hidden')) { $('#classDetail').classList.add('hidden'); return; }
  selectedClassroom = c; latestRunId = null;
  $('#classDetail').classList.remove('hidden');
  $('#detailTitle').textContent = c.class_name;
  $('#editClassNameBtn').classList.toggle('hidden', currentUser.role !== 'teacher');
  $('#editClassDescriptionBtn').classList.toggle('hidden', currentUser.role !== 'teacher');
  $('#joinCodeText').textContent = currentUser.role==='teacher' && c.join_code ? `รหัสเข้าห้อง: ${c.join_code}` : '';
  $('#classDescription').textContent = c.description || 'ยังไม่มีรายละเอียดงาน';
  $('#collapseClassBtn').textContent='ย่อรายละเอียด';
  resetClassSections();
  $('#classDetail').scrollIntoView({behavior:'smooth',block:'start'});
}
let renameTargetClassroomId = null;
function openRenameModal(classroomId, currentName) {
  renameTargetClassroomId = Number(classroomId);
  $('#renameClassInput').value = currentName;
  $('#renameClassMessage').textContent = '';
  $('#renameClassModal').classList.remove('hidden');
  document.body.classList.add('modal-open');
  window.setTimeout(() => { $('#renameClassInput').focus(); $('#renameClassInput').select(); }, 30);
}
function closeRenameModal() {
  $('#renameClassModal').classList.add('hidden');
  document.body.classList.remove('modal-open');
  renameTargetClassroomId = null;
  safeReset($('#renameClassForm'));
  $('#renameClassMessage').textContent = '';
}
$('#editClassNameBtn').addEventListener('click', () => {
  if (currentUser?.role !== 'teacher' || !selectedClassroom) return;
  openRenameModal(Number(selectedClassroom.classroom_id), selectedClassroom.class_name);
});
$('#cancelRenameBtn').addEventListener('click', closeRenameModal);
$('#renameClassModal').addEventListener('click', e => { if (e.target === $('#renameClassModal')) closeRenameModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape' && !$('#renameClassModal').classList.contains('hidden')) closeRenameModal(); });
function openDescriptionModal() {
  if (currentUser?.role !== 'teacher' || !selectedClassroom) return;
  $('#editDescriptionInput').value = selectedClassroom.description || '';
  $('#editDescriptionMessage').textContent = '';
  $('#editDescriptionModal').classList.remove('hidden');
  document.body.classList.add('modal-open');
  window.setTimeout(() => $('#editDescriptionInput').focus(), 30);
}
function closeDescriptionModal() {
  $('#editDescriptionModal').classList.add('hidden');
  document.body.classList.remove('modal-open');
  safeReset($('#editDescriptionForm'));
  $('#editDescriptionMessage').textContent = '';
}
$('#editClassDescriptionBtn').addEventListener('click', openDescriptionModal);
$('#cancelDescriptionBtn').addEventListener('click', closeDescriptionModal);
$('#editDescriptionModal').addEventListener('click', e => { if (e.target === $('#editDescriptionModal')) closeDescriptionModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape' && !$('#editDescriptionModal').classList.contains('hidden')) closeDescriptionModal(); });
$('#editDescriptionForm').addEventListener('submit', async e => {
  e.preventDefault();
  if (currentUser?.role !== 'teacher' || !selectedClassroom) return;
  const description = $('#editDescriptionInput').value.trim();
  const saveBtn = $('#saveDescriptionBtn');
  saveBtn.disabled = true;
  try {
    const result = await api('update_classroom_description', 'POST', {classroom_id:Number(selectedClassroom.classroom_id), description});
    selectedClassroom.description = result.data?.description ?? description;
    $('#classDescription').textContent = selectedClassroom.description || 'ยังไม่มีรายละเอียด';
    closeDescriptionModal();
    toast('บันทึกรายละเอียดห้องเรียนแล้ว');
    await loadClassrooms();
  } catch(err) {
    $('#editDescriptionMessage').textContent = err.message || 'ไม่สามารถบันทึกรายละเอียดได้';
  } finally { saveBtn.disabled = false; }
});

$('#renameClassForm').addEventListener('submit', async e => {
  e.preventDefault();
  if (currentUser?.role !== 'teacher' || !renameTargetClassroomId) return;
  const cleanName = $('#renameClassInput').value.trim();
  if (!cleanName) { $('#renameClassMessage').textContent = 'กรุณากรอกชื่อห้องเรียน'; return; }
  const saveBtn = $('#saveRenameBtn');
  saveBtn.disabled = true;
  try {
    await api('rename_classroom', 'POST', {classroom_id:renameTargetClassroomId, class_name:cleanName});
    if (selectedClassroom && Number(selectedClassroom.classroom_id) === renameTargetClassroomId) {
      selectedClassroom.class_name = cleanName;
      $('#detailTitle').textContent = cleanName;
    }
    closeRenameModal();
    toast('เปลี่ยนชื่อห้องเรียนแล้ว');
    await loadClassrooms();
  } catch(err) {
    $('#renameClassMessage').textContent = err.message || 'ไม่สามารถเปลี่ยนชื่อห้องได้';
  } finally { saveBtn.disabled = false; }
});

$('#loginForm').addEventListener('submit', async e => {
  e.preventDefault(); $('#loginMessage').textContent='';
  const form = e.currentTarget;
  const fd = new FormData(form);
  try {
    const result = await api('login','POST',{email:fd.get('email'),password:fd.get('password')});
    safeReset(form);
    showApp(result.user);
  } catch(err) { $('#loginMessage').textContent=err.message; }
});
$('#registerForm').addEventListener('submit', async e => {
  e.preventDefault(); $('#registerMessage').textContent='';
  const form = e.currentTarget;
  const fd = new FormData(form);
  try {
    const result = await api('register_student','POST',{full_name:fd.get('full_name'),email:fd.get('email'),password:fd.get('password')});
    $('#registerMessage').style.color='#067647'; $('#registerMessage').textContent=result.message;
    safeReset(form);
  } catch(err) { $('#registerMessage').style.color='#b42318'; $('#registerMessage').textContent=err.message; }
});
$('#logoutBtn').addEventListener('click', async () => {
  try { await api('logout','POST',{}); } catch {}
  showAuth();
});
$('#createClassForm').addEventListener('submit', async e => {
  e.preventDefault(); $('#classMessage').textContent='';
  const form = e.currentTarget;
  const fd = new FormData(form);
  try {
    const result = await api('create_classroom','POST',{class_name:fd.get('class_name'),description:fd.get('description') || ''});
    $('#classMessage').style.color='#067647';
    $('#classMessage').textContent=`สร้างห้องสำเร็จ รหัสเข้าห้องคือ ${result.join_code}`;
    safeReset(form); await loadClassrooms();
  } catch(err) { $('#classMessage').style.color='#b42318'; $('#classMessage').textContent=err.message; }
});
$('#joinClassForm').addEventListener('submit', async e => {
  e.preventDefault(); $('#classMessage').textContent='';
  const form = e.currentTarget;
  const fd = new FormData(form);
  try {
    const result = await api('join_classroom','POST',{join_code:fd.get('join_code')});
    $('#classMessage').style.color='#067647'; $('#classMessage').textContent=result.message;
    safeReset(form); await loadClassrooms();
  } catch(err) { $('#classMessage').style.color='#b42318'; $('#classMessage').textContent=err.message; }
});

async function refreshMembersArea() {
  if (!selectedClassroom) return;
  const area = $('#membersArea');
  try {
    const result = await api('members','GET',null,{classroom_id:selectedClassroom.classroom_id});
    const memberRows = result.data.map(u => `<tr><td>${escapeHtml(u.full_name)}</td><td>${escapeHtml(u.skills_text || 'ยังไม่ได้กรอกทักษะ')}</td>${currentUser.role==='teacher' ? `<td><button type="button" class="danger" data-remove-student="${escapeHtml(u.user_id)}" data-student-name="${escapeHtml(u.full_name)}">นำออกจากห้อง</button></td>` : ''}</tr>`).join('');
    area.innerHTML = `<h3>รายชื่อนักเรียน (${result.data.length})</h3>` + (result.data.length ? `<table class="data-table"><thead><tr><th>ชื่อ-นามสกุล</th><th>ทักษะที่นักเรียนกรอก</th>${currentUser.role==='teacher' ? '<th>จัดการ</th>' : ''}</tr></thead><tbody>${memberRows}</tbody></table>` : '<p class="muted">ยังไม่มีนักเรียนในห้องนี้</p>');
    area.dataset.loaded='true';
    area.querySelectorAll('button[data-remove-student]').forEach(btn => btn.addEventListener('click', async () => {
      if (!window.confirm(`ยืนยันนำ “${btn.dataset.studentName}” ออกจากห้องนี้หรือไม่? บัญชีและห้องเรียนอื่นจะไม่ถูกลบ`)) return;
      try { await api('remove_member','POST',{classroom_id:Number(selectedClassroom.classroom_id),student_id:Number(btn.dataset.removeStudent)}); toast('นำสมาชิกออกจากห้องแล้ว'); area.dataset.loaded='false'; await refreshMembersArea(); } catch(err) { toast(err.message); }
    }));
  } catch(err) { toast(err.message); }
}

$('#membersBtn').addEventListener('click', async () => {
  if (!selectedClassroom) return;
  const area = $('#membersArea');
  const willOpen = area.classList.contains('hidden');
  setSectionOpen('#membersBtn', '#membersArea', willOpen);
  if (!willOpen || area.dataset.loaded === 'true') return;
  area.innerHTML = '<p class="muted">กำลังโหลดรายชื่อนักเรียน...</p>';
  try {
    const result = await api('members','GET',null,{classroom_id:selectedClassroom.classroom_id});
    const memberRows = result.data.map(u => `<tr><td>${escapeHtml(u.full_name)}</td><td>${escapeHtml(u.skills_text || 'ยังไม่ได้กรอกทักษะ')}</td>${currentUser.role==='teacher' ? `<td><button type="button" class="danger" data-remove-student="${escapeHtml(u.user_id)}" data-student-name="${escapeHtml(u.full_name)}">นำออกจากห้อง</button></td>` : ''}</tr>`).join('');
    area.innerHTML = `<h3>รายชื่อนักเรียน (${result.data.length})</h3>` +
      (result.data.length ? `<table class="data-table"><thead><tr><th>ชื่อ-นามสกุล</th><th>ทักษะที่นักเรียนกรอก</th>${currentUser.role==='teacher' ? '<th>จัดการ</th>' : ''}</tr></thead><tbody>${memberRows}</tbody></table>` : '<p class="muted">ยังไม่มีนักเรียนในห้องนี้</p>');
    area.querySelectorAll('button[data-remove-student]').forEach(btn => btn.addEventListener('click', async () => {
      if (!window.confirm(`ยืนยันนำ “${btn.dataset.studentName}” ออกจากห้องนี้หรือไม่? บัญชีและห้องเรียนอื่นจะไม่ถูกลบ`)) return;
      try { await api('remove_member','POST',{classroom_id:Number(selectedClassroom.classroom_id),student_id:Number(btn.dataset.removeStudent)});
        toast('นำสมาชิกออกจากห้องแล้ว'); area.dataset.loaded='false'; $('#groupsArea').dataset.loaded='false'; $('#ratingsArea').dataset.loaded='false';
        await refreshMembersArea();
      } catch(err) { toast(err.message); }
    }));
    area.dataset.loaded = 'true';
  } catch(err) { area.innerHTML = `<p class="message">${escapeHtml(err.message)}</p>`; }
});
$('#groupsBtn').addEventListener('click', async () => {
  if (!selectedClassroom) return;
  const area = $('#groupsArea');
  const willOpen = area.classList.contains('hidden');
  setSectionOpen('#groupsBtn', '#groupsArea', willOpen);
  if (!willOpen || area.dataset.loaded === 'true') return;
  area.innerHTML = '<p class="muted">กำลังโหลดผลการสุ่มกลุ่ม...</p>';
  try {
    const result = await api('groups','GET',null,{classroom_id:selectedClassroom.classroom_id});
    latestRunId = result.run ? Number(result.run.run_id) : null;
    if (!result.data.length) {
      area.innerHTML='<h3>ผลการสุ่มกลุ่ม</h3><p class="muted">ยังไม่มีผลการสุ่ม กรุณาให้ครูกดปุ่มสุ่มกลุ่ม</p>';
      area.dataset.loaded = 'true';
      return;
    }
    const groupCards = result.data.map(g => {
      const members = g.members.map(m => {
        const canRate = currentUser.role === 'teacher' || Number(m.user_id) !== Number(currentUser.user_id);
        return `<li>${escapeHtml(m.full_name)}${canRate ? `<form class="rating-form compact-rating" data-target-user="${escapeHtml(m.user_id)}"><label>คะแนนเพื่อน/นักเรียน<select name="score" required><option value="5">5 - ดีมาก</option><option value="4">4 - ดี</option><option value="3">3 - ปานกลาง</option><option value="2">2 - ควรปรับปรุง</option><option value="1">1 - ต้องปรับปรุง</option></select></label><input name="comment" maxlength="500" placeholder="ความคิดเห็น (ไม่บังคับ)"><button type="submit" class="secondary">ส่งคะแนน</button></form>` : ''}</li>`;
      }).join('');
      const groupRating = currentUser.role === 'teacher' ? `<form class="rating-form compact-rating" data-target-group="${escapeHtml(g.group_id)}"><strong>ประเมินกลุ่มนี้</strong><label>คะแนน<select name="score" required><option value="5">5 - ดีมาก</option><option value="4">4 - ดี</option><option value="3">3 - ปานกลาง</option><option value="2">2 - ควรปรับปรุง</option><option value="1">1 - ต้องปรับปรุง</option></select></label><input name="comment" maxlength="500" placeholder="ความคิดเห็น (ไม่บังคับ)"><button type="submit" class="secondary">ส่งคะแนนกลุ่ม</button></form>` : '';
      return `<article class="group-card"><h3>กลุ่ม ${escapeHtml(g.group_number)}</h3><ul>${members}</ul>${groupRating}</article>`;
    }).join('');
    area.innerHTML = `<h3>ผลการสุ่มกลุ่ม</h3><p class="muted">${currentUser.role === 'teacher' ? 'ครูสามารถประเมินนักเรียนหรือกลุ่มได้' : 'นักเรียนสามารถประเมินสมาชิกในกลุ่มต่าง ๆ ได้ ยกเว้นตนเอง โดยระบบไม่แสดงชื่อผู้ประเมินให้ผู้เรียนเห็น'}</p><div class="group-grid">${groupCards}</div><p id="ratingMessage" class="message"></p>`;
    area.dataset.loaded = 'true';
  } catch(err) { area.innerHTML = `<p class="message">${escapeHtml(err.message)}</p>`; }
});
$('#ratingsBtn').addEventListener('click', async () => {
  if (!selectedClassroom) return;
  const area = $('#ratingsArea');
  const willOpen = area.classList.contains('hidden');
  setSectionOpen('#ratingsBtn', '#ratingsArea', willOpen);
  if (!willOpen || area.dataset.loaded === 'true') return;
  area.innerHTML = '<p class="muted">กำลังโหลดผลการประเมิน...</p>';
  try {
    const result = await api('rating_results', 'GET', null, {classroom_id: selectedClassroom.classroom_id});
    if (!result.run) {
      area.innerHTML = '<section class="panel"><h3>ผลการประเมิน</h3><p class="muted">ยังไม่มีผลการสุ่มกลุ่ม จึงยังไม่มีผลการประเมิน</p></section>';
      area.dataset.loaded = 'true';
      return;
    }
    if (!result.data.length) {
      area.innerHTML = `<section class="panel"><h3>${currentUser.role === 'teacher' ? 'ผลการประเมินทั้งหมด' : 'ผลการประเมินจากครู'}</h3><p class="muted">ยังไม่มีการประเมินสำหรับผลการสุ่มกลุ่มนี้</p></section>`;
      area.dataset.loaded = 'true';
      return;
    }
    const rowsByGroup = new Map();
    const groupRatings = new Map();
    result.data.forEach(r => {
      const groupKey = r.target_group_number ? String(r.target_group_number) : 'unknown';
      if (!rowsByGroup.has(groupKey)) rowsByGroup.set(groupKey, []);
      const reviewerLabel = currentUser.role === 'teacher'
        ? `<span class="muted">ผู้ประเมิน: ${escapeHtml(r.reviewer_name || (r.rating_type === 'teacher' ? 'ครู' : 'นักเรียน'))}</span>`
        : '<span class="muted">ผู้ประเมิน: ครู</span>';
      const ratingLabel = r.rating_type === 'teacher' ? 'ครูประเมิน' : 'นักเรียนประเมิน';
      const dateLabel = r.created_at ? new Date(String(r.created_at).replace(' ', 'T')).toLocaleString('th-TH') : '';
      const editForm = currentUser.role === 'teacher' && Number(r.reviewer_id) === Number(currentUser.user_id) && r.rating_type === 'teacher'
        ? `<form class="rating-edit-form compact-rating" data-rating-id="${escapeHtml(r.rating_id)}"><strong>แก้ไขคะแนนที่คุณให้</strong><label>คะแนน<select name="score" required>${[5,4,3,2,1].map(n=>`<option value="${n}" ${Number(r.score)===n?'selected':''}>${n} - ${({5:'ดีมาก',4:'ดี',3:'ปานกลาง',2:'ควรปรับปรุง',1:'ต้องปรับปรุง'})[n]}</option>`).join('')}</select></label><label>ความคิดเห็น<input name="comment" maxlength="500" value="${escapeHtml(r.comment || '')}" placeholder="ความคิดเห็น (ไม่บังคับ)"></label><button type="submit" class="secondary">บันทึกการแก้ไข</button></form>` : '';

      // คะแนนกลุ่มจะแสดงต่อท้ายหัวข้อกลุ่ม ไม่สร้างการ์ดแยกชื่อ "ผลประเมินกลุ่ม X"
      if (!r.target_name && r.target_group_number) {
        if (!groupRatings.has(groupKey)) groupRatings.set(groupKey, []);
        groupRatings.get(groupKey).push(`<div class="group-rating-summary"><p><strong>คะแนนกลุ่ม: ${escapeHtml(r.score)}/5</strong></p><p class="muted">${ratingLabel} · ${reviewerLabel}</p><p><strong>ความคิดเห็น:</strong> ${escapeHtml(r.comment || 'ไม่มีความคิดเห็น')}</p>${editForm}${dateLabel ? `<small class="muted">วันที่ประเมิน: ${escapeHtml(dateLabel)}</small>` : ''}</div>`);
        return;
      }

      const targetLabel = r.target_name
        ? `นักเรียน: ${escapeHtml(r.target_name)}`
        : 'ไม่ระบุเป้าหมาย';
      const row = `<article class="class-card rating-result-card">
        <div class="section-head"><h4>${targetLabel}</h4><strong>${escapeHtml(r.score)}/5</strong></div>
        <p class="muted">${ratingLabel} · ${reviewerLabel}</p>
        <p><strong>ความคิดเห็น:</strong> ${escapeHtml(r.comment || 'ไม่มีความคิดเห็น')}</p>
        ${editForm}
        ${dateLabel ? `<small class="muted">วันที่ประเมิน: ${escapeHtml(dateLabel)}</small>` : ''}
      </article>`;
      rowsByGroup.get(groupKey).push(row);
    });
    const allGroupKeys = new Set([...rowsByGroup.keys(), ...groupRatings.keys()]);
    const rows = [...allGroupKeys].sort((a,b) => {
      if (a === 'unknown') return 1;
      if (b === 'unknown') return -1;
      return Number(a) - Number(b);
    }).map(groupNumber => {
      const groupHeading = groupNumber === 'unknown' ? 'ไม่ระบุกลุ่ม' : `กลุ่ม ${escapeHtml(groupNumber)}`;
      const groupScoreRows = (groupRatings.get(groupNumber) || []).join('');
      const individualRows = (rowsByGroup.get(groupNumber) || []).join('');
      return `<section class="rating-group-results"><h4>${groupHeading}</h4>${groupScoreRows}<div class="rating-results-list">${individualRows}</div></section>`;
    }).join('');
    const heading = currentUser.role === 'teacher' ? 'ผลการประเมินทั้งหมด' : 'ผลการประเมินจากครู';
    const note = currentUser.role === 'teacher'
      ? 'ผลประเมินรายคนจะแสดงอยู่ใต้กลุ่มที่นักเรียนคนนั้นสังกัดจริง'
      : 'แสดงเฉพาะผลประเมินจากครู รวมคะแนนและความคิดเห็น';
    area.innerHTML = `<section class="panel"><h3>${heading}</h3><p class="muted">${note}</p>${rows}</section>`;
    area.dataset.loaded = 'true';
  } catch (err) {
    area.innerHTML = '';
    toast(err.message);
  }
});

$('#ratingsArea').addEventListener('submit', async e => {
  const form = e.target.closest('form.rating-edit-form');
  if (!form) return;
  e.preventDefault();
  const fd = new FormData(form);
  try {
    const result = await api('update_rating', 'POST', {
      rating_id:Number(form.dataset.ratingId),
      score:Number(fd.get('score')),
      comment:fd.get('comment') || ''
    });
    toast(result.message);
    $('#ratingsArea').dataset.loaded = 'false';
    $('#ratingsArea').innerHTML = '';
    if (!$('#ratingsArea').classList.contains('hidden')) { $('#ratingsBtn').click(); $('#ratingsBtn').click(); }
  } catch(err) { toast(err.message); }
});

$('#randomizeForm').addEventListener('submit', async e => {
  e.preventDefault(); if (!selectedClassroom) return;
  const fd = new FormData(e.currentTarget);
  try {
    const result = await api('randomize','POST',{classroom_id:selectedClassroom.classroom_id,group_size:Number(fd.get('group_size'))});
    toast(`สุ่มกลุ่มสำเร็จ ${result.group_count} กลุ่ม`);
    $('#groupsArea').dataset.loaded = 'false';
    $('#groupsArea').innerHTML = '';
    if (!$('#groupsArea').classList.contains('hidden')) setSectionOpen('#groupsBtn', '#groupsArea', false);
    $('#groupsBtn').click();
  } catch(err) { toast(err.message); }
});

$('#collapseClassBtn').addEventListener('click', () => { $('#classDetail').classList.add('hidden'); });

async function loadSkills() {
  const requestUser = currentUser;
  if (!requestUser || requestUser.role !== 'student') return;
  try {
    const result = await api('skills');
    if (currentUser !== requestUser) return;
    $('#skillsText').value = result.skills_text || '';
    $('#skillsMessage').textContent = '';
    $('#skillsMessage').style.color = '';
  } catch (err) {
    if (currentUser === requestUser) $('#skillsMessage').textContent = err.message;
  }
}
$('#skillsForm').addEventListener('submit', async e => {
  e.preventDefault();
  const form = e.currentTarget;
  const skillsText = $('#skillsText').value.trim();
  $('#skillsMessage').textContent = '';
  try {
    const result = await api('save_skills', 'POST', {skills_text: skillsText});
    $('#skillsMessage').style.color = '#067647';
    $('#skillsMessage').textContent = result.message;
  } catch (err) {
    $('#skillsMessage').style.color = '#b42318';
    $('#skillsMessage').textContent = err.message;
  }
});

$('#groupsArea').addEventListener('submit', async e => {
  const form = e.target.closest('form.rating-form');
  if (!form) return;
  e.preventDefault();
  if (!latestRunId) { toast('ไม่พบผลการสุ่มกลุ่ม'); return; }
  const fd = new FormData(form);
  const payload = {
    run_id: latestRunId,
    score: Number(fd.get('score')),
    comment: fd.get('comment') || ''
  };
  if (form.dataset.targetUser) payload.target_user_id = Number(form.dataset.targetUser);
  if (form.dataset.targetGroup) payload.target_group_id = Number(form.dataset.targetGroup);
  try {
    const result = await api('rate', 'POST', payload);
    $('#ratingMessage').style.color = '#067647';
    $('#ratingMessage').textContent = result.message;
    safeReset(form);
  } catch (err) {
    $('#ratingMessage').style.color = '#b42318';
    $('#ratingMessage').textContent = err.message;
  }
});

(async function init() {
  try { const result=await api('me'); showApp(result.user); }
  catch { showAuth(); }
})();
