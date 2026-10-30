/**
 * StudentHub — Practical 15 Final Integration
 * Client-side Controller & Dynamic Interactions
 */

(() => {
  'use strict';

  // -------------------------------------------------------------------
  // 1. Theme Management (Classy Beige Light & Roasted Espresso Dark)
  // -------------------------------------------------------------------
  const themeToggle = document.querySelector('#themeToggle');
  const htmlRoot = document.documentElement;

  function initTheme() {
    const saved = localStorage.getItem('studenthub_theme');
    if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
      setTheme('dark');
    } else {
      setTheme('light');
    }
  }

  function setTheme(mode) {
    if (mode === 'dark') {
      htmlRoot.setAttribute('data-theme', 'dark');
      if (themeToggle) {
        themeToggle.innerHTML = '☀️';
        themeToggle.setAttribute('title', 'Switch to Classy Light Beige mode');
        themeToggle.setAttribute('aria-label', 'Switch to Classy Light Beige mode');
      }
      localStorage.setItem('studenthub_theme', 'dark');
    } else {
      htmlRoot.removeAttribute('data-theme');
      if (themeToggle) {
        themeToggle.innerHTML = '🌙';
        themeToggle.setAttribute('title', 'Switch to Roasted Espresso Dark mode');
        themeToggle.setAttribute('aria-label', 'Switch to Roasted Espresso Dark mode');
      }
      localStorage.setItem('studenthub_theme', 'light');
    }
  }

  themeToggle?.addEventListener('click', () => {
    const isDark = htmlRoot.getAttribute('data-theme') === 'dark';
    setTheme(isDark ? 'light' : 'dark');
  });

  initTheme();

  // -------------------------------------------------------------------
  // 2. Mobile Navigation Toggle
  // -------------------------------------------------------------------
  const mobileToggle = document.querySelector('#mobileToggle');
  const navMenu = document.querySelector('#navMenu');

  mobileToggle?.addEventListener('click', () => {
    navMenu?.classList.toggle('open');
    const isOpen = navMenu?.classList.contains('open');
    mobileToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
  });

  // -------------------------------------------------------------------
  // 3. Interactive FAQ Accordion
  // -------------------------------------------------------------------
  const faqQuestions = document.querySelectorAll('.faq-question');
  faqQuestions.forEach(btn => {
    btn.addEventListener('click', () => {
      const parent = btn.closest('.faq-item');
      if (!parent) return;
      const wasActive = parent.classList.contains('active');
      
      // Close all other FAQs for clean accordion feel
      document.querySelectorAll('.faq-item').forEach(item => {
        item.classList.remove('active');
        item.querySelector('.faq-question')?.setAttribute('aria-expanded', 'false');
      });

      if (!wasActive) {
        parent.classList.add('active');
        btn.setAttribute('aria-expanded', 'true');
      }
    });
  });

  // -------------------------------------------------------------------
  // 4. Student Directory AJAX Search, Filtering & Sorting
  // -------------------------------------------------------------------
  const studentSearch = document.querySelector('#studentSearch');
  const deptFilter = document.querySelector('#deptFilter');
  const semFilter = document.querySelector('#semFilter');
  const sortSelect = document.querySelector('#sortSelect');
  const studentTableBody = document.querySelector('#studentTableBody');
  const studentCountDisplay = document.querySelector('#studentCount');
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  let searchDebounceTimer;

  async function fetchStudents() {
    if (!studentTableBody) return;

    const params = new URLSearchParams({
      q: studentSearch?.value.trim() || '',
      department: deptFilter?.value || '',
      semester: semFilter?.value || '',
      sort: sortSelect?.value || 'id_desc'
    });

    studentTableBody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding: 24px; color: var(--text-muted);">
      <em>Searching student records...</em>
    </td></tr>`;

    try {
      const response = await fetch(`api/search_students.php?${params.toString()}`, {
        headers: { 'Accept': 'application/json' }
      });

      if (!response.ok) {
        throw new Error(`HTTP error ${response.status}`);
      }

      const result = await response.json();
      if (!result.ok) {
        throw new Error(result.message || 'Error occurred while loading data.');
      }

      renderStudentRows(result.data);
      if (studentCountDisplay) {
        studentCountDisplay.textContent = `Showing ${result.count} student${result.count === 1 ? '' : 's'}`;
      }
    } catch (err) {
      console.error('[StudentHub Search Error]', err);
      studentTableBody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding: 24px; color: var(--status-error-text);">
        Unable to load student records. Please refresh and check your database connection.
      </td></tr>`;
    }
  }

  function renderStudentRows(students) {
    if (!studentTableBody) return;
    studentTableBody.replaceChildren();

    if (!students || students.length === 0) {
      const emptyRow = document.createElement('tr');
      const td = document.createElement('td');
      td.colSpan = 7;
      td.style.textAlign = 'center';
      td.style.padding = '30px';
      td.style.color = 'var(--text-muted)';
      td.innerHTML = '<strong>No matching student records found.</strong><br><small>Try adjusting your search criteria or clearing filters.</small>';
      emptyRow.appendChild(td);
      studentTableBody.appendChild(emptyRow);
      return;
    }

    students.forEach(s => {
      const tr = document.createElement('tr');

      // ID
      const tdId = document.createElement('td');
      tdId.textContent = `#${s.student_id}`;
      tdId.style.fontWeight = '700';
      tdId.style.color = 'var(--brand-cognac)';
      tr.appendChild(tdId);

      // Full Name
      const tdName = document.createElement('td');
      tdName.textContent = s.full_name;
      tdName.style.fontWeight = '600';
      tr.appendChild(tdName);

      // Email
      const tdEmail = document.createElement('td');
      tdEmail.textContent = s.email;
      tr.appendChild(tdEmail);

      // Department
      const tdDept = document.createElement('td');
      const deptPill = document.createElement('span');
      deptPill.className = 'pill pill-amber';
      deptPill.textContent = s.department;
      tdDept.appendChild(deptPill);
      tr.appendChild(tdDept);

      // Semester
      const tdSem = document.createElement('td');
      tdSem.textContent = `Sem ${s.semester}`;
      tr.appendChild(tdSem);

      // Phone
      const tdPhone = document.createElement('td');
      tdPhone.textContent = s.phone || '—';
      tr.appendChild(tdPhone);

      // Actions
      const tdAction = document.createElement('td');
      tdAction.style.whiteSpace = 'nowrap';

      // Edit Button
      const editBtn = document.createElement('button');
      editBtn.type = 'button';
      editBtn.className = 'btn btn-secondary btn-sm';
      editBtn.style.marginRight = '6px';
      editBtn.textContent = 'Edit';
      editBtn.addEventListener('click', () => openEditStudentModal(s));
      tdAction.appendChild(editBtn);

      // Delete Form
      const deleteForm = document.createElement('form');
      deleteForm.method = 'post';
      deleteForm.action = 'index.php?page=students';
      deleteForm.style.display = 'inline';
      deleteForm.innerHTML = `
        <input type="hidden" name="csrf" value="${csrfToken}">
        <input type="hidden" name="action" value="delete_student">
        <input type="hidden" name="student_id" value="${s.student_id}">
      `;
      deleteForm.addEventListener('submit', (e) => {
        if (!confirm(`Are you sure you want to permanently delete record for ${s.full_name}?`)) {
          e.preventDefault();
        }
      });

      const delBtn = document.createElement('button');
      delBtn.type = 'submit';
      delBtn.className = 'btn btn-danger btn-sm';
      delBtn.textContent = 'Delete';
      deleteForm.appendChild(delBtn);
      tdAction.appendChild(deleteForm);

      tr.appendChild(tdAction);
      studentTableBody.appendChild(tr);
    });
  }

  // Bind Search and Filter Events
  studentSearch?.addEventListener('input', () => {
    clearTimeout(searchDebounceTimer);
    searchDebounceTimer = setTimeout(fetchStudents, 250);
  });
  deptFilter?.addEventListener('change', fetchStudents);
  semFilter?.addEventListener('change', fetchStudents);
  sortSelect?.addEventListener('change', fetchStudents);

  // -------------------------------------------------------------------
  // 5. Modals Management (Student Edit, Event Attendees, Event Edit)
  // -------------------------------------------------------------------
  const editStudentModal = document.querySelector('#editStudentModal');
  const attendeeRosterModal = document.querySelector('#attendeeRosterModal');
  const editEventModal = document.querySelector('#editEventModal');

  function openModal(modal) {
    if (!modal) return;
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  function closeModal(modal) {
    if (!modal) return;
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }

  // Setup close handlers for all modal close buttons
  document.querySelectorAll('.modal-close, .modal-cancel').forEach(btn => {
    btn.addEventListener('click', (e) => {
      const modal = e.target.closest('.modal-overlay');
      closeModal(modal);
    });
  });

  // Close modal when clicking outside box
  document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) closeModal(overlay);
    });
  });

  // Close modal on Escape key
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal-overlay.open').forEach(closeModal);
    }
  });

  function openEditStudentModal(student) {
    if (!editStudentModal) return;
    const form = editStudentModal.querySelector('#editStudentForm');
    if (!form) return;
    form.querySelector('#edit_student_id').value = student.student_id;
    form.querySelector('#edit_full_name').value = student.full_name;
    form.querySelector('#edit_email').value = student.email;
    form.querySelector('#edit_department').value = student.department;
    form.querySelector('#edit_semester').value = student.semester;
    form.querySelector('#edit_phone').value = student.phone || '';
    openModal(editStudentModal);
  }

  // Global exposure for rows rendered statically via PHP
  window.openEditStudentModal = openEditStudentModal;

  // -------------------------------------------------------------------
  // 6. Event Attendees Roster Modal (Admin Only)
  // -------------------------------------------------------------------
  window.viewEventAttendees = async function(eventId, eventTitle) {
    if (!attendeeRosterModal) return;
    const rosterTitle = attendeeRosterModal.querySelector('#rosterEventTitle');
    const rosterBody = attendeeRosterModal.querySelector('#rosterTableBody');
    const rosterCount = attendeeRosterModal.querySelector('#rosterCount');

    if (rosterTitle) rosterTitle.textContent = eventTitle;
    if (rosterBody) rosterBody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding: 20px;">Loading attendees...</td></tr>';
    openModal(attendeeRosterModal);

    try {
      const res = await fetch(`api/event_attendees.php?event_id=${eventId}`);
      if (!res.ok) throw new Error('Failed to load roster.');
      const data = await res.json();
      if (!data.ok) throw new Error(data.message || 'Error occurred');

      if (rosterCount) rosterCount.textContent = `(${data.count} registered)`;
      rosterBody.replaceChildren();

      if (data.attendees.length === 0) {
        rosterBody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding: 20px; color: var(--text-muted);">No student registrations recorded yet for this event.</td></tr>';
        return;
      }

      data.attendees.forEach((att, idx) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td>${idx + 1}</td>
          <td><strong>${escapeHtml(att.full_name)}</strong><br><small style="color:var(--text-muted);">${escapeHtml(att.email)}</small></td>
          <td>${escapeHtml(att.department)} (Sem ${att.semester})</td>
          <td><span class="pill pill-success">${escapeHtml(att.status)}</span></td>
        `;
        rosterBody.appendChild(tr);
      });
    } catch (err) {
      if (rosterBody) {
        rosterBody.innerHTML = `<tr><td colspan="4" style="text-align:center; padding: 20px; color: var(--status-error-text);">${err.message}</td></tr>`;
      }
    }
  };

  // -------------------------------------------------------------------
  // 7. Event Edit Modal (Admin Only)
  // -------------------------------------------------------------------
  window.openEditEventModal = function(ev) {
    if (!editEventModal) return;
    const form = editEventModal.querySelector('#editEventForm');
    if (!form) return;
    form.querySelector('#edit_event_id').value = ev.id;
    form.querySelector('#edit_event_name').value = ev.name;
    form.querySelector('#edit_event_category').value = ev.category || 'Academic';
    form.querySelector('#edit_event_date').value = ev.date;
    form.querySelector('#edit_event_venue').value = ev.venue;
    form.querySelector('#edit_event_seats').value = ev.seats || 60;
    form.querySelector('#edit_event_desc').value = ev.description || '';
    openModal(editEventModal);
  };

  // -------------------------------------------------------------------
  // 8. Event Client Search & Filter on Events Page
  // -------------------------------------------------------------------
  const eventSearchInput = document.querySelector('#eventSearchInput');
  const eventCategoryFilter = document.querySelector('#eventCategoryFilter');
  const eventDateSort = document.querySelector('#eventDateSort');
  const eventsContainer = document.querySelector('#eventsContainer');

  function filterEventsList() {
    if (!eventsContainer) return;
    const cards = Array.from(eventsContainer.querySelectorAll('.event-card'));
    const q = (eventSearchInput?.value || '').toLowerCase().trim();
    const cat = eventCategoryFilter?.value || '';
    const sort = eventDateSort?.value || 'asc';

    let visibleCount = 0;
    cards.forEach(card => {
      const title = (card.dataset.title || '').toLowerCase();
      const venue = (card.dataset.venue || '').toLowerCase();
      const category = card.dataset.category || '';
      
      const matchSearch = !q || title.includes(q) || venue.includes(q);
      const matchCat = !cat || category === cat;

      if (matchSearch && matchCat) {
        card.style.display = 'flex';
        visibleCount++;
      } else {
        card.style.display = 'none';
      }
    });

    // Sort visible cards by date
    cards.sort((a, b) => {
      const dateA = a.dataset.date || '';
      const dateB = b.dataset.date || '';
      return sort === 'desc' ? dateB.localeCompare(dateA) : dateA.localeCompare(dateB);
    });

    cards.forEach(card => eventsContainer.appendChild(card));

    const emptyMsg = document.querySelector('#eventsEmptyMsg');
    if (emptyMsg) {
      emptyMsg.style.display = visibleCount === 0 ? 'block' : 'none';
    }
  }

  eventSearchInput?.addEventListener('input', filterEventsList);
  eventCategoryFilter?.addEventListener('change', filterEventsList);
  eventDateSort?.addEventListener('change', filterEventsList);

  // -------------------------------------------------------------------
  // 9. Quick Demo Fill for Testing
  // -------------------------------------------------------------------
  window.fillDemoCredentials = function(username, password) {
    const identInput = document.querySelector('input[name="identity"]');
    const passInput = document.querySelector('input[name="password"]');
    if (identInput) identInput.value = username;
    if (passInput) passInput.value = password;
  };

  // -------------------------------------------------------------------
  // 10. Client-Side Form Validation (Registration)
  // -------------------------------------------------------------------
  const regForm = document.querySelector('#registerForm');
  regForm?.addEventListener('submit', (e) => {
    const pass = regForm.querySelector('input[name="password"]')?.value || '';
    const passConfirm = regForm.querySelector('input[name="password_confirm"]')?.value || '';
    const email = regForm.querySelector('input[name="email"]')?.value || '';
    const sem = parseInt(regForm.querySelector('input[name="semester"]')?.value || '0', 10);

    if (pass.length < 8) {
      alert('Password must be at least 8 characters long.');
      e.preventDefault();
      return;
    }

    if (passConfirm && pass !== passConfirm) {
      alert('Password and Confirm Password do not match.');
      e.preventDefault();
      return;
    }

    if (sem < 1 || sem > 12) {
      alert('Please enter a valid semester number between 1 and 12.');
      e.preventDefault();
      return;
    }
  });

  function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

})();
