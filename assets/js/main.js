// Campus Facility Reservation — Main JavaScript

document.addEventListener('DOMContentLoaded', function () {

  // ── Auto dismiss alerts setelah 5 detik ──
  document.querySelectorAll('.alert').forEach(function (el) {
    // Jangan dismiss alert yang ada di dalam modal
    if (el.closest('.modal-overlay')) return;
    setTimeout(function () {
      el.style.transition = 'opacity .5s';
      el.style.opacity = '0';
      setTimeout(function () { el.remove(); }, 500);
    }, 5000);
  });

  // ── Konfirmasi sebelum hapus (tombol dengan data-confirm) ──
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      if (!confirm(el.dataset.confirm || 'Yakin?')) e.preventDefault();
    });
  });

  // ── Close modal saat klik backdrop ──
  document.querySelectorAll('.modal-overlay').forEach(function (m) {
    m.addEventListener('click', function (e) {
      if (e.target === this) this.classList.remove('show');
    });
  });

  // ── Highlight nav item aktif berdasarkan URL ──
  var path = window.location.pathname;
  document.querySelectorAll('.nav-item').forEach(function (el) {
    var href = el.getAttribute('href') || '';
    var hPath = href.split('?')[0];
    if (hPath && path.endsWith(hPath.replace(/^.*\//, '/'))) {
      el.classList.add('active');
      var dot = el.querySelector('.dot');
      if (!dot) {
        dot = document.createElement('span');
        dot.className = 'dot';
        el.appendChild(dot);
      }
    }
  });

  // ── Date range: tanggal selesai min = tanggal mulai ──
  var tglMulai = document.querySelector('input[name=tanggal_mulai]');
  var tglSelesai = document.querySelector('input[name=tanggal_selesai]');
  if (tglMulai && tglSelesai) {
    tglMulai.addEventListener('change', function () {
      tglSelesai.min = this.value;
      if (tglSelesai.value && tglSelesai.value < this.value) tglSelesai.value = this.value;
    });
  }

});
