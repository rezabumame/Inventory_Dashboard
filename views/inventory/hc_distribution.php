<?php
check_role(['super_admin', 'admin_hc']);
?>

<link href='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css' rel='stylesheet' />
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js'></script>
<script src='https://cdn.jsdelivr.net/npm/@fullcalendar/core@6.1.8/locales/id.global.min.js'></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

<style>
    :root {
        --bumame-blue: #204EAB;
        --glass-bg: rgba(255, 255, 255, 0.7);
        --glass-border: rgba(255, 255, 255, 0.2);
    }

    .distribution-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 4px 20px -5px rgba(0,0,0,0.05);
        border: 1px solid #edf2f7;
        overflow: hidden;
    }

    .view-switcher {
        background: #f1f5f9;
        padding: 4px;
        border-radius: 8px;
        display: inline-flex;
    }

    .view-btn {
        padding: 6px 16px;
        border-radius: 6px;
        border: none;
        background: transparent;
        color: #64748b;
        font-weight: 600;
        font-size: 0.875rem;
        transition: all 0.2s;
    }

    .view-btn.active {
        background: #fff;
        color: var(--bumame-blue);
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }

    /* Calendar Styling Refinement */
    #calendar {
        background: #fff;
        padding: 25px;
        border-radius: 16px;
        border: none;
    }

    .fc {
        font-family: 'Inter', 'Poppins', sans-serif !important;
    }

    .fc .fc-toolbar-title {
        font-size: 1.4rem !important;
        font-weight: 800 !important;
        color: #1e293b;
    }

    .fc .fc-button {
        background: #f8fafc !important;
        border: 1px solid #e2e8f0 !important;
        color: #64748b !important;
        font-weight: 600 !important;
        text-transform: capitalize !important;
        padding: 8px 16px !important;
        font-size: 0.85rem !important;
        box-shadow: none !important;
        transition: all 0.2s;
    }

    .fc .fc-button-active, .fc .fc-button:hover {
        background: var(--bumame-blue) !important;
        border-color: var(--bumame-blue) !important;
        color: #fff !important;
    }

    .fc .fc-today-button {
        opacity: 1 !important;
        background: #fff !important;
        color: var(--bumame-blue) !important;
        border-color: var(--bumame-blue) !important;
    }

    .fc-theme-bootstrap5 a {
        text-decoration: none !important;
        color: #1e293b;
    }

    .fc-col-header-cell-cushion {
        padding: 10px 0 !important;
        font-size: 0.85rem !important;
        font-weight: 700 !important;
        color: var(--bumame-blue) !important;
        text-decoration: none !important;
    }
    .fc-daygrid-day-number, .fc-daygrid-more-link {
        text-decoration: none !important;
    }
    .breadcrumb-item + .breadcrumb-item::before { content: "/"; }

    .fc-timegrid-slot-label-cushion {
        font-size: 0.75rem !important;
        color: #94a3b8 !important;
        text-transform: uppercase;
    }

    .fc-popover {
        background: #ffffff !important;
        z-index: 1040 !important;
        border-radius: 12px !important;
        border: 1px solid #e2e8f0 !important;
        box-shadow: 0 10px 25px -5px rgba(0,0,0,0.2) !important;
        overflow: hidden !important;
    }

    .fc-popover-header {
        background: #f8fafc !important;
        padding: 10px 14px !important;
        border-bottom: 1px solid #e2e8f0 !important;
        font-weight: 700 !important;
    }

    .fc-popover-body {
        max-height: 350px !important;
        overflow-y: auto !important;
        padding: 8px !important;
        background: #ffffff !important;
    }

    /* Fix for modal overlay */
    .modal-backdrop {
        z-index: 1050 !important;
    }
    #modalMoveBooking {
        z-index: 1060 !important;
    }

    .fc-daygrid-day-number {
        font-weight: 600 !important;
        font-size: 0.85rem !important;
        color: #475569 !important;
        padding: 2px 6px !important; /* Extremely tight */
        line-height: 1 !important;
    }

    .fc-daygrid-day-top {
        display: flex !important;
        justify-content: flex-end !important;
    }

    .fc-day-today {
        background: rgba(32, 78, 171, 0.03) !important;
    }

    .fc-timegrid-event {
        border-radius: 6px !important;
        border: 1px solid rgba(32, 78, 171, 0.15) !important;
        border-left: 3px solid var(--bumame-blue) !important;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05) !important;
        transition: all 0.2s ease;
        background: #ffffff !important;
    }

    .fc-timegrid-event:hover {
        z-index: 100 !important;
        transform: scale(1.05);
        box-shadow: 0 8px 15px -5px rgba(0, 0, 0, 0.1) !important;
        cursor: pointer;
    }

    .fc-daygrid-day-bottom {
        display: flex !important;
        justify-content: center !important;
        padding: 2px 0 !important;
    }

    .fc-daygrid-more-link {
        display: inline-block !important; /* Changed from block to inline-block */
        text-align: center !important;
        background: rgba(32, 78, 171, 0.08) !important;
        color: var(--bumame-blue) !important;
        font-weight: 700 !important;
        font-size: 0.7rem !important; /* Smaller */
        padding: 2px 8px !important; /* More compact */
        margin: 2px 6px !important; /* Minimal margin */
        border-radius: 20px !important;
        transition: all 0.2s ease !important;
        border: 1px solid rgba(32, 78, 171, 0.1) !important;
    }

    .fc-daygrid-event {
        margin-bottom: 0px !important; /* No margin */
        border-bottom: 1px solid rgba(0,0,0,0.1) !important; /* More visible separator */
    }

    .fc-event-main-frame {
        padding: 2px 6px !important; /* More compact */
    }

    .fc-daygrid-day-events {
        margin-top: -4px !important; /* Negative margin to pull list up */
        margin-bottom: 0 !important;
    }

    .fc-daygrid-day-frame {
        padding-bottom: 2px !important;
    }

    /* Kanban Styling */
    .kanban-container {
        display: flex;
        overflow-x: auto;
        gap: 16px;
        padding: 10px 0 20px 0;
        min-height: 70vh;
    }

    .kanban-column {
        min-width: 260px;
        width: 260px;
        background: #f8fafc;
        border-radius: 14px;
        display: flex;
        flex-direction: column;
        max-height: 75vh;
        border: 1px solid #e2e8f0;
    }

    .kanban-column-header {
        padding: 12px 15px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #fff;
        border-bottom: 1px solid #e2e8f0;
        border-radius: 14px 14px 0 0;
    }

    .column-title {
        font-weight: 700;
        color: #1e293b;
        font-size: 0.85rem;
    }

    .column-badge {
        background: var(--bumame-blue);
        color: #fff;
        padding: 2px 8px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 700;
    }

    .kanban-items {
        flex: 1;
        overflow-y: auto;
        padding: 10px;
        min-height: 100px;
    }

    .kanban-card {
        background: #fff;
        border-radius: 10px;
        padding: 12px;
        margin-bottom: 10px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        cursor: pointer;
        transition: transform 0.2s, box-shadow 0.2s;
        position: relative;
    }

    .kanban-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        border-color: var(--bumame-blue);
    }

    .card-id {
        font-size: 0.65rem;
        color: var(--bumame-blue);
        font-weight: 700;
        margin-bottom: 2px;
        display: flex;
        justify-content: space-between;
    }

    .card-order-id {
        font-size: 0.65rem;
        color: #64748b;
        font-weight: 500;
    }

    .card-name {
        font-weight: 700;
        color: #1e293b;
        font-size: 0.85rem;
        margin-bottom: 6px;
        line-height: 1.3;
    }

    .card-meta {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
        font-size: 0.7rem;
        color: #64748b;
    }

    .card-pax {
        background: #f1f5f9;
        padding: 1px 6px;
        border-radius: 4px;
        font-weight: 700;
        color: #475569;
    }

    .fc-event-main {
        padding: 5px !important;
        overflow: hidden;
    }

    .fc-timegrid-event {
        min-height: 60px !important;
        margin-bottom: 4px !important;
        border-radius: 8px !important;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05) !important;
        border: 1px solid rgba(0,0,0,0.1) !important;
    }

    .fc-timegrid-event-main {
        padding: 0 !important;
    }

    .fc-event-main-frame {
        display: flex;
        flex-direction: column;
        height: 100%;
        padding: 4px 6px;
        width: 100%; /* Force full width */
    }
</style>

<div class="container-fluid">
    <div class="row mb-4 align-items-center">
        <div class="col">
            <h1 class="h3 mb-1 fw-bold" style="color: var(--bumame-blue);">
                <i class="fas fa-route me-2"></i>HC Distribution
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="index.php?page=dashboard" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item active">HC Distribution</li>
                </ol>
            </nav>
        </div>
        <div class="col-auto">
            <div class="view-switcher shadow-sm">
                <button class="view-btn active" id="btn-calendar" onclick="switchView('calendar')">
                    <i class="fas fa-calendar-alt me-1"></i> Calendar
                </button>
                <button class="view-btn" id="btn-kanban" onclick="switchView('kanban')">
                    <i class="fas fa-th-list me-1"></i> Kanban
                </button>
            </div>
        </div>
    </div>

    <div class="distribution-card p-3 mb-4">
        <div class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label small fw-bold text-muted mb-1">Range Tanggal</label>
                <div class="input-group input-group-sm shadow-sm-hover">
                    <input type="date" id="filter-start" class="form-control border-end-0" value="<?= date('Y-m-d') ?>" onchange="updateUrlParams()">
                    <span class="input-group-text bg-white border-start-0 border-end-0 text-muted small">s/d</span>
                    <input type="date" id="filter-end" class="form-control border-start-0" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" onchange="updateUrlParams()">
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold text-muted mb-1">Cari Pasien</label>
                <div class="input-group input-group-sm shadow-sm-hover">
                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted opacity-50"></i></span>
                    <input type="text" id="filter-q" class="form-control border-start-0 ps-0" placeholder="Nama, No. Booking, Order ID...">
                </div>
            </div>
            <div class="col-md-4">
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-outline-primary flex-fill fw-bold py-2" id="btn-toggle-date" onclick="toggleDateFilter()">
                        <i class="fas fa-calendar-day me-1"></i> Hari Ini
                    </button>
                    <button class="btn btn-sm text-white flex-fill fw-bold py-2" style="background: var(--bumame-blue);" onclick="loadData()">
                        <i class="fas fa-sync-alt me-1"></i> Refresh
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Calendar View -->
    <div id="view-calendar">
        <div class="distribution-card p-0">
            <div id="calendar"></div>
        </div>
    </div>

    <!-- Kanban View -->
    <div id="view-kanban" style="display: none;">
        <div class="kanban-container" id="kanban-wrapper">
            <!-- Columns will be injected here -->
        </div>
    </div>
</div>

<!-- Modal Move (Click to Switch) -->
<div class="modal fade" id="modalMoveBooking" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header bg-white border-bottom py-3">
                <h5 class="modal-title fw-bold" style="color: var(--bumame-blue);"><i class="fas fa-exchange-alt me-2"></i>Pindahkan Booking</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-4 text-center">
                    <div class="p-3 bg-light rounded-3 mb-3">
                        <p class="text-muted mb-1 small text-uppercase fw-bold">Pasien yang akan dipindah</p>
                        <input type="hidden" id="move-booking-id">
                        <h5 id="move-booking-nomor" class="fw-bold mb-0" style="color: #204EAB;">-</h5>
                        <p id="move-booking-nama" class="text-muted mb-0 small">-</p>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label fw-bold small text-muted text-uppercase">Pilih Klinik Tujuan</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0 text-muted">
                            <i class="fas fa-hospital"></i>
                        </span>
                        <select id="move-target-klinik" class="form-select border-start-0 ps-0 shadow-none">
                            <!-- Options filled via JS -->
                        </select>
                    </div>
                </div>
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-primary py-2 fw-bold" onclick="confirmMove()">
                        <i class="fas fa-check-circle me-1"></i> Simpan Perubahan
                    </button>
                    <button type="button" class="btn btn-light py-2" data-bs-dismiss="modal">Batal</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    let calendar;
    let allBookings = [];
    let clinics = [];
    let eventSource;

    function toggleDateFilter() {
        const start = document.getElementById('filter-start').value;
        const end = document.getElementById('filter-end').value;
        const today = new Date().toLocaleDateString('en-CA');
        
        if (start === today && end === today) {
            // Is today, switch to 7 days
            const nextWeek = new Date();
            nextWeek.setDate(nextWeek.getDate() + 7);
            document.getElementById('filter-end').value = nextWeek.toLocaleDateString('en-CA');
        } else {
            // Is not today, switch to today
            document.getElementById('filter-start').value = today;
            document.getElementById('filter-end').value = today;
        }
        updateButtonState();
        updateUrlParams();
        loadData();
    }

    function updateButtonState() {
        const start = document.getElementById('filter-start').value;
        const end = document.getElementById('filter-end').value;
        const today = new Date().toLocaleDateString('en-CA');
        const btn = document.getElementById('btn-toggle-date');
        
        if (start === today && end === today) {
            btn.innerHTML = '<i class="fas fa-calendar-week me-1"></i> 7 Hari';
        } else {
            btn.innerHTML = '<i class="fas fa-calendar-day me-1"></i> Hari Ini';
        }
    }

    function initCalendar() {
        const urlParams = new URLSearchParams(window.location.search);
        const savedView = urlParams.get('calView') || 'dayGridMonth';
        
        const calendarEl = document.getElementById('calendar');
        calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: savedView,
            locale: 'id',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,dayGridWeek,timeGridDay'
            },
            buttonText: {
                today: 'Hari Ini',
                month: 'Bulan',
                week: 'Minggu',
                day: 'Hari'
            },
            navLinks: true,
            themeSystem: 'bootstrap5',
            datesSet: function(info) {
                const url = new URL(window.location);
                url.searchParams.set('calView', info.view.type);
                window.history.replaceState({}, '', url);
            },
            slotMinTime: '07:00:00',
            slotMaxTime: '20:00:00',
            allDaySlot: false,
            slotEventOverlap: false,
            dayMaxEvents: 3,
            moreLinkClick: 'popover',
            height: 'auto',
            events: [],
            eventMinHeight: 50,
            eventClick: function(info) {
                openMoveModal(info.event.id);
            },
            eventContent: function(arg) {
                const b = arg.event.extendedProps;
                const isSmall = arg.view.type.includes('timeGrid');
                return {
                    html: `
                        <div class="fc-event-main-frame">
                            <div class="fw-bold text-truncate" style="font-size: ${isSmall ? '10px' : '11px'}; color: #1e293b;">
                                ${arg.event.title}
                            </div>
                                <div class="d-flex align-items-center mb-1" style="font-size: 9px; color: #64748b;">
                                    <i class="fas fa-hospital me-1 opacity-75"></i> <span class="text-truncate">${b.klinik || 'Unassigned'}</span>
                                </div>
                                <div class="d-flex align-items-center justify-content-between" style="width: 100%;">
                                    <span style="font-size: 9px; color: #204EAB; font-weight: 700;">#${b.order_id || 'N/A'}</span>
                                    <span class="badge rounded-pill bg-light text-primary border" style="font-size: 8px; padding: 2px 6px;">${b.pax} Pax</span>
                                </div>
                        </div>
                    `
                };
            }
        });
        calendar.render();
    }

    function switchView(view) {
        document.getElementById('view-calendar').style.display = view === 'calendar' ? 'block' : 'none';
        document.getElementById('view-kanban').style.display = view === 'kanban' ? 'block' : 'none';
        
        document.getElementById('btn-calendar').classList.toggle('active', view === 'calendar');
        document.getElementById('btn-kanban').classList.toggle('active', view === 'kanban');
        
        if (view === 'calendar' && calendar) {
            setTimeout(() => calendar.render(), 100);
        } else if (view === 'kanban') {
            renderKanban();
        }

        // URL Persistence
        const url = new URL(window.location);
        url.searchParams.set('tab', view);
        window.history.replaceState({}, '', url);
    }

    function updateUrlParams() {
        const start = document.getElementById('filter-start').value;
        const end = document.getElementById('filter-end').value;
        const url = new URL(window.location);
        if (start) url.searchParams.set('start', start);
        if (end) url.searchParams.set('end', end);
        window.history.replaceState({}, '', url);
    }

    function loadData() {
        const start = document.getElementById('filter-start').value;
        const end = document.getElementById('filter-end').value;
        const q = document.getElementById('filter-q').value;
        
        updateUrlParams();

        $.ajax({
            url: 'api/ajax_hc_distribution.php',
            type: 'GET',
            cache: false,
            data: {
                action: 'get_data',
                start: start,
                end: end,
                q: q,
                _t: Date.now()
            },
            dataType: 'json',
            success: function(res) {
                if (typeof Swal !== 'undefined' && Swal.isVisible()) {
                    Swal.close();
                }
                if (res.success) {
                    allBookings = res.data;
                    clinics = res.clinics;
                    
                    updateCalendarEvents();
                    renderKanban();
                    updateButtonState();
                }
            },
            error: function() {
                if (typeof Swal !== 'undefined' && Swal.isVisible()) {
                    Swal.close();
                }
                Swal.fire('Error', 'Gagal memuat data', 'error');
            }
        });
    }

    function moment_add_minutes(timeStr, mins) {
        if (!timeStr) return '01:00:00';
        const parts = timeStr.split(':');
        const h = parseInt(parts[0]);
        const m = parseInt(parts[1]);
        const s = parts[2] ? parseInt(parts[2]) : 0;
        
        const date = new Date();
        date.setHours(h, m + mins, s);
        return date.getHours().toString().padStart(2, '0') + ':' + 
               date.getMinutes().toString().padStart(2, '0') + ':' + 
               date.getSeconds().toString().padStart(2, '0');
    }

    function updateCalendarEvents() {
        const events = allBookings.map(b => ({
            id: b.id,
            title: b.nama_pemesan,
            start: `${b.tanggal_pemeriksaan}T${b.jam_layanan || '00:00:00'}`,
            end: `${b.tanggal_pemeriksaan}T${moment_add_minutes(b.jam_layanan || '00:00:00', 30)}`, // 30 min duration
            backgroundColor: '#ffffff',
            borderColor: '#204EAB',
            textColor: '#1e293b',
            extendedProps: {
                jam: b.jam_layanan ? b.jam_layanan.substring(0, 5) : 'Anytime',
                klinik: b.nama_klinik,
                klinik_id: b.klinik_id,
                pax: b.jumlah_pax,
                order_id: b.order_id
            }
        }));
        
        if (eventSource) {
            eventSource.remove();
        }
        eventSource = calendar.addEventSource(events);
    }

    function renderKanban() {
        const wrapper = document.getElementById('kanban-wrapper');
        wrapper.innerHTML = '';

        // Group bookings by clinic
        const grouped = {};
        clinics.forEach(c => grouped[c.id] = []);
        grouped[0] = []; // Unassigned

        allBookings.forEach(b => {
            const kid = b.klinik_id || 0;
            if (grouped[kid]) grouped[kid].push(b);
        });

        // Add columns
        clinics.forEach(c => {
            addColumn(wrapper, c.id, c.nama_klinik, grouped[c.id]);
        });

        // Initialize Sortable for each column
        document.querySelectorAll('.kanban-items').forEach(el => {
            new Sortable(el, {
                group: 'hc-distribution',
                animation: 150,
                ghostClass: 'ghost-card',
                onEnd: function(evt) {
                    const bookingId = evt.item.dataset.id;
                    const targetKlinikId = evt.to.dataset.klinikId;
                    const oldKlinikId = evt.from.dataset.klinikId;
                    
                    if (targetKlinikId !== oldKlinikId) {
                        updateBookingClinic(bookingId, targetKlinikId);
                    }
                }
            });
        });
    }

    function addColumn(container, clinicId, name, items) {
        const col = document.createElement('div');
        col.className = 'kanban-column';
        col.innerHTML = `
            <div class="kanban-column-header">
                <span class="column-title text-truncate" title="${name}">${name}</span>
                <span class="column-badge">${items.length}</span>
            </div>
            <div class="kanban-items" data-klinik-id="${clinicId}">
                ${items.map(b => `
                    <div class="kanban-card" data-id="${b.id}" onclick="openMoveModal(${b.id})">
                        <div class="card-id">
                            <span>#${b.nomor_booking}</span>
                            <span class="card-order-id">${b.order_id || ''}</span>
                        </div>
                        <div class="card-name">${b.nama_pemesan}</div>
                        <div class="card-meta">
                            <span><i class="far fa-clock me-1"></i>${b.jam_layanan ? b.jam_layanan.substring(0, 5) : '-'}</span>
                            <span><i class="far fa-calendar me-1"></i>${b.tanggal_pemeriksaan.substring(5)}</span>
                            <span class="card-pax">${b.jumlah_pax} Pax</span>
                        </div>
                    </div>
                `).join('')}
            </div>
        `;
        container.appendChild(col);
    }

    function openMoveModal(id) {
        const b = allBookings.find(x => x.id == id);
        if (!b) return;

        document.getElementById('move-booking-id').value = b.id;
        document.getElementById('move-booking-nomor').textContent = b.nomor_booking;
        document.getElementById('move-booking-nama').textContent = b.nama_pemesan + ' (' + (b.order_id || 'No Order ID') + ')';
        
        const select = document.getElementById('move-target-klinik');
        select.innerHTML = clinics.map(c => 
            `<option value="${c.id}" ${c.id == b.klinik_id ? 'selected disabled' : ''}>${c.nama_klinik}</option>`
        ).join('');

        const modal = new bootstrap.Modal(document.getElementById('modalMoveBooking'));
        modal.show();
    }

    function confirmMove() {
        const id = document.getElementById('move-booking-id').value;
        const target = document.getElementById('move-target-klinik').value;
        
        updateBookingClinic(id, target);
        bootstrap.Modal.getInstance(document.getElementById('modalMoveBooking')).hide();
    }

    function updateBookingClinic(bookingId, targetKlinikId) {
        Swal.fire({
            title: 'Memindahkan...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        $.ajax({
            url: 'api/ajax_hc_distribution.php',
            type: 'POST',
            data: {
                action: 'move_booking',
                booking_id: bookingId,
                target_klinik_id: targetKlinikId
            },
            dataType: 'json',
            success: function(res) {
                Swal.close();
                if (!res.success) {
                    Swal.fire('Error', res.message, 'error');
                } else {
                    // Optimistic update for instant feedback
                    const bIdx = allBookings.findIndex(x => x.id == bookingId);
                    if (bIdx !== -1) {
                        allBookings[bIdx].klinik_id = targetKlinikId;
                        const targetK = clinics.find(c => c.id == targetKlinikId);
                        if (targetK) allBookings[bIdx].nama_klinik = targetK.nama_klinik;
                    }
                    updateCalendarEvents();
                    renderKanban();

                    toastr.success('Distribusi berhasil diperbarui');
                    // Sync with server in background
                    setTimeout(loadData, 500);
                }
            },
            error: function() {
                Swal.close();
                Swal.fire('Error', 'Gagal memindahkan booking', 'error');
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        
        // Restore dates from URL if present
        if (urlParams.get('start')) {
            document.getElementById('filter-start').value = urlParams.get('start');
        }
        if (urlParams.get('end')) {
            document.getElementById('filter-end').value = urlParams.get('end');
        }
        
        initCalendar();
        
        const initialTab = urlParams.get('tab') || 'calendar';
        if (initialTab === 'kanban') {
            switchView('kanban');
        }
        
        loadData();
    });
</script>
