// ── Label Config Wizard — logika terpusat "Konfigurasi Label" (BIS) ────────────
// Dipakai bersama oleh views/master/barang.php dan views/inventory/qr_inbound.php
// supaya pertanyaan, aturan kategori, dan hasilnya selalu konsisten di semua tempat.

const LABEL_CONFIG_QUESTIONS = [
    { q: 'Apakah item memiliki Barcode bawaan dari Vendor yang dapat di-scan?' },                       // Q1 - idx 0
    { q: 'Apakah box item memiliki Barcode bawaan dari Vendor yang dapat di-scan?' },                    // Q2 - idx 1
    { q: 'Apakah secara fisik item dapat ditempel Barcode tanpa menutupi informasi penting?' },          // Q3 - idx 2
    { q: 'Apakah quantity item masih masuk akal untuk ditempel Barcode pada setiap item?' },             // Q4 - idx 3
    { q: 'Apakah frekuensi pemindahan atau penggunaan item cukup sering?' },                             // Q5 - idx 4
    { q: 'Apakah fisik item memiliki kemiripan dengan item lain?' },                                     // Q6 - idx 5
    { q: 'Apakah secara fisik box pada item dapat ditempel Barcode tanpa menutupi informasi penting?' }, // Q7 - idx 6
    { q: 'Apakah penyimpanan item tidak harus dipisahkan antara item dan box?' },                        // Q8 - idx 7
    { q: 'Apakah penggunaan (usage) item dihitung per box?' },                                           // Q9 - idx 8
    { q: 'Apakah ketika box item sudah tidak ada dapat dianggap sebagai stok habis?' },                  // Q10 - idx 9
];

const LABEL_CONFIG_CATEGORIES = {
    A: { label: 'Category A', desc: 'Internal Barcode Only on System',                     lp: 'none',     place: null,        icon: 'fa-barcode', color: '#64748b', bg: '#f1f5f9', badge: 'Sistem Saja' },
    B: { label: 'Category B', desc: 'Internal Barcode Printed & Applied on Item Level',    lp: 'physical', place: 'unit',      icon: 'fa-tag',     color: '#204EAB', bg: '#eff6ff', badge: 'Cetak Fisik (Item)' },
    C: { label: 'Category C', desc: 'Internal Barcode Printed & Applied on Box Level',     lp: 'physical', place: 'box',       icon: 'fa-box',     color: '#7c3aed', bg: '#f5f3ff', badge: 'Cetak Fisik (Box)' },
    D: { label: 'Category D', desc: 'Internal Barcode Printed & Applied on Catalogue Book',lp: 'physical', place: 'catalogue', icon: 'fa-book',    color: '#b45309', bg: '#fffbeb', badge: 'Cetak Fisik (Katalog)' },
};

const LABEL_OVERRIDE_REASONS = [
    'Item memiliki karakteristik khusus yang tidak tercakup pada pertanyaan',
    'Kondisi fisik item tidak sesuai dengan kondisi operasional',
    'Perubahan proses bisnis atau SOP',
    'Kebutuhan operasional tertentu',
    'Lainnya',
];

/**
 * Menghitung urutan pertanyaan yang perlu ditampilkan (hanya yang relevan — short-circuit
 * begitu satu jalur sudah pasti gagal/berhasil) beserta kategori hasil rekomendasi otomatis.
 * @param {Array<boolean|null>} answers - array 10 elemen, index 0..9 = Q1..Q10
 * @returns {{path:number[], category:('A'|'B'|'C'|'D'|null), next:(number|null)}}
 */
function labelConfigCompute(answers) {
    const a = answers || [];
    const isAnswered = (v) => v === true || v === false;
    const path = [];

    path.push(0);
    if (!isAnswered(a[0])) return { path, category: null, next: 0 };
    if (a[0] === true) return { path, category: 'A', next: null };

    path.push(1);
    if (!isAnswered(a[1])) return { path, category: null, next: 1 };
    if (a[1] === true) return { path, category: 'A', next: null };

    path.push(2);
    if (!isAnswered(a[2])) return { path, category: null, next: 2 };

    if (a[2] === true) {
        // Jalur Item: Q4 → Q5 → Q6, short-circuit begitu ada yang "Tidak"
        let allYes = true;
        for (const qi of [3, 4, 5]) {
            path.push(qi);
            if (!isAnswered(a[qi])) return { path, category: null, next: qi };
            if (a[qi] === false) { allYes = false; break; }
        }
        if (allYes) return { path, category: 'B', next: null };
    }

    // Jalur Box: Q7
    path.push(6);
    if (!isAnswered(a[6])) return { path, category: null, next: 6 };
    if (a[6] === false) return { path, category: 'D', next: null };

    // Q8 → Q9 → Q10, short-circuit begitu ada yang "Tidak"
    let allYes2 = true;
    for (const qi of [7, 8, 9]) {
        path.push(qi);
        if (!isAnswered(a[qi])) return { path, category: null, next: qi };
        if (a[qi] === false) { allYes2 = false; break; }
    }
    if (allYes2) return { path, category: 'C', next: null };
    return { path, category: 'D', next: null };
}

/**
 * Kategori langsung dari label_print/label_placement yang sudah tersimpan (mis. hasil
 * import/injeksi data lama) tanpa melalui wizard — dipakai supaya item yang kategorinya
 * sudah diketahui tidak dipaksa menjawab ulang 10 pertanyaan dari nol.
 * @returns {('A'|'B'|'C'|'D'|null)}
 */
function labelConfigInferCategory(lp, placement) {
    if (lp === 'none') return 'A';
    if (lp === 'physical') {
        if (placement === 'unit')      return 'B';
        if (placement === 'box')       return 'C';
        if (placement === 'catalogue') return 'D';
    }
    return null;
}

function labelConfigCatCardsHtml(matchedCategory) {
    return ['A', 'B', 'C', 'D'].map(cat => {
        const ci = LABEL_CONFIG_CATEGORIES[cat];
        const isMatch = matchedCategory === cat;
        return `<div style="border:2px solid ${isMatch?ci.color:'#e2e8f0'};border-radius:10px;padding:10px 12px;background:${isMatch?ci.bg:'#fff'};transition:all .2s;">
            <div style="display:flex;align-items:center;gap:8px;">
                <div style="width:28px;height:28px;border-radius:7px;background:${isMatch?ci.color:'#e8ecf0'};display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .2s;">
                    <i class="fas ${ci.icon}" style="color:${isMatch?'#fff':'#94a3b8'};font-size:.75rem;"></i>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:.8rem;font-weight:${isMatch?700:600};color:${isMatch?ci.color:'#374151'};">${ci.label}</div>
                    <div style="font-size:.68rem;color:#94a3b8;margin-top:1px;line-height:1.3;">${ci.desc}</div>
                </div>
                ${isMatch?`<span style="font-size:.68rem;font-weight:700;background:${ci.color};color:#fff;padding:2px 7px;border-radius:5px;white-space:nowrap;flex-shrink:0;">${ci.badge}</span>`:''}
            </div>
        </div>`;
    }).join('');
}

/**
 * Bangun HTML baris pertanyaan (hanya yang ada di path) dan kartu kategori.
 * @param {Array<boolean|null>} answers
 * @param {string} setAnswerFnName - nama fungsi global onclick, mis. "lcSetAnswer"
 * @returns {{qRowsHtml:string, catCardsHtml:string, state:object}}
 */
function labelConfigRenderParts(answers, setAnswerFnName) {
    const state = labelConfigCompute(answers);

    const qRowsHtml = state.path.map((qi, orderIdx) => {
        const ans = answers[qi];
        const yS = `border:2px solid ${ans===true?'#22c55e':'#e2e8f0'};background:${ans===true?'#dcfce7':'#fff'};color:${ans===true?'#166534':'#64748b'};font-weight:${ans===true?700:500};`;
        const nS = `border:2px solid ${ans===false?'#94a3b8':'#e2e8f0'};background:${ans===false?'#f1f5f9':'#fff'};color:${ans===false?'#374151':'#64748b'};font-weight:${ans===false?700:500};`;
        return `<div style="display:flex;align-items:center;gap:8px;padding:6px 0;${orderIdx>0?'border-top:1px solid #f1f5f9;':''}">
            <span style="min-width:18px;height:18px;border-radius:50%;background:#e8f0fd;display:flex;align-items:center;justify-content:center;font-size:.62rem;font-weight:700;color:#204EAB;flex-shrink:0;">${qi+1}</span>
            <span style="flex:1;font-size:.77rem;font-weight:500;color:#374151;">${LABEL_CONFIG_QUESTIONS[qi].q}</span>
            <div style="display:flex;gap:4px;flex-shrink:0;">
                <button type="button" onclick="${setAnswerFnName}(${qi},true)" style="${yS}border-radius:5px;padding:2px 10px;font-size:.73rem;cursor:pointer;transition:all .1s;">Ya</button>
                <button type="button" onclick="${setAnswerFnName}(${qi},false)" style="${nS}border-radius:5px;padding:2px 10px;font-size:.73rem;cursor:pointer;transition:all .1s;">Tidak</button>
            </div>
        </div>`;
    }).join('');

    const catCardsHtml = labelConfigCatCardsHtml(state.category);

    return { qRowsHtml, catCardsHtml, state };
}

/**
 * Bangun HTML blok Manual Override (alasan + dropdown kategori final).
 * @param {object} opts - { visible, active, reason, notes, finalCategory, recommendedCategory, overrideBy, overrideAt, fnPrefix }
 */
function labelConfigRenderOverrideHTML(opts) {
    const fn = opts.fnPrefix || 'lc';
    const reasonOpts = LABEL_OVERRIDE_REASONS.map(r =>
        `<option value="${r}" ${opts.reason===r?'selected':''}>${r}</option>`
    ).join('');
    const catOpts = ['A','B','C','D'].map(cat =>
        `<option value="${cat}" ${opts.finalCategory===cat?'selected':''}>${LABEL_CONFIG_CATEGORIES[cat].label} — ${LABEL_CONFIG_CATEGORIES[cat].badge}</option>`
    ).join('');
    const isLainnya = opts.reason === 'Lainnya';

    if (!opts.active) {
        return `<div style="margin-top:10px;">
            <button type="button" onclick="${fn}OverrideStart()" style="background:none;border:none;color:#b45309;font-size:.75rem;font-weight:600;cursor:pointer;padding:4px 0;">
                <i class="fas fa-user-edit me-1"></i>Kategori Tidak Sesuai / Tentukan Secara Manual
            </button>
        </div>`;
    }

    return `<div style="margin-top:10px;border:1.5px solid #fde68a;background:#fffbeb;border-radius:10px;padding:12px;">
        <div style="display:flex;align-items:center;justify-content:between;gap:8px;margin-bottom:8px;">
            <span style="font-size:.75rem;font-weight:700;color:#92400e;flex:1;"><i class="fas fa-user-edit me-1"></i>Manual Override</span>
            <button type="button" onclick="${fn}OverrideCancel()" style="background:none;border:none;color:#94a3b8;font-size:.72rem;cursor:pointer;">Batal</button>
        </div>
        <label style="font-size:.72rem;font-weight:600;color:#78350f;display:block;margin-bottom:3px;">Alasan Override</label>
        <select id="${fn}OverrideReason" onchange="${fn}OverrideReasonChange()" class="form-select form-select-sm mb-2" style="font-size:.78rem;border-radius:7px;">
            <option value="">— Pilih alasan —</option>
            ${reasonOpts}
        </select>
        <div id="${fn}OverrideNotesWrap" style="display:${isLainnya?'block':'none'};margin-bottom:8px;">
            <label style="font-size:.72rem;font-weight:600;color:#78350f;display:block;margin-bottom:3px;">Keterangan (wajib)</label>
            <textarea id="${fn}OverrideNotes" class="form-control form-control-sm" rows="2" style="font-size:.78rem;border-radius:7px;" placeholder="Jelaskan alasan override...">${opts.notes || ''}</textarea>
        </div>
        <div style="display:${opts.reason?'block':'none'};" id="${fn}OverrideCatWrap">
            <label style="font-size:.72rem;font-weight:600;color:#78350f;display:block;margin-bottom:3px;">Kategori Final</label>
            <select id="${fn}OverrideCategory" onchange="${fn}OverrideCategoryChange()" class="form-select form-select-sm" style="font-size:.78rem;border-radius:7px;">
                <option value="">— Pilih kategori —</option>
                ${catOpts}
            </select>
        </div>
    </div>`;
}

function labelConfigStatusBadgeHTML(overrideStatus) {
    if (overrideStatus) {
        return `<span style="font-size:.68rem;font-weight:700;background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:5px;"><i class="fas fa-user-edit me-1"></i>Manual Override</span>`;
    }
    return `<span style="font-size:.68rem;font-weight:700;background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:5px;"><i class="fas fa-robot me-1"></i>Auto Recommendation</span>`;
}

/**
 * Banner info untuk item yang kategorinya sudah diketahui dari data lama/import (bukan hasil
 * menjawab wizard) — supaya kategori itu langsung ditampilkan apa adanya tanpa memaksa user
 * menjawab ulang dari pertanyaan pertama.
 * @param {string} redoFnName - nama fungsi global onclick untuk "Jawab Ulang"
 */
function labelConfigInferredBannerHTML(redoFnName) {
    return `<div style="display:flex;align-items:center;gap:8px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:8px 10px;margin-bottom:10px;">
        <i class="fas fa-info-circle" style="color:#2563eb;flex-shrink:0;"></i>
        <span style="flex:1;font-size:.74rem;color:#1e40af;">Kategori ini berasal dari data yang sudah diset/di-import sebelumnya.</span>
        <button type="button" onclick="${redoFnName}()" style="background:none;border:none;color:#204EAB;font-size:.72rem;font-weight:600;cursor:pointer;white-space:nowrap;">Jawab Ulang</button>
    </div>`;
}
