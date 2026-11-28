<script>if (typeof bootstrap === 'undefined') { document.write('<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"><\/script>'); }</script>
<script>
(function () {
    window.toggleSidebar = function () {
        var s = document.getElementById("sidebar");
        var b = document.getElementById("saasSidebarBackdrop");
        if (!s) return;
        s.classList.toggle("active");
        if (b) {
            b.classList.toggle("saas-backdrop-visible", s.classList.contains("active"));
            b.setAttribute("aria-hidden", s.classList.contains("active") ? "false" : "true");
        }
    };
    document.addEventListener("keydown", function (e) {
        if (e.key !== "Escape") return;
        var s = document.getElementById("sidebar");
        var b = document.getElementById("saasSidebarBackdrop");
        if (s) s.classList.remove("active");
        if (b) {
            b.classList.remove("saas-backdrop-visible");
            b.setAttribute("aria-hidden", "true");
        }
    });
    document.addEventListener("DOMContentLoaded", function () {
        var b = document.getElementById("saasSidebarBackdrop");
        if (!b) return;
        b.addEventListener("click", function () {
            var s = document.getElementById("sidebar");
            if (s) s.classList.remove("active");
            b.classList.remove("saas-backdrop-visible");
            b.setAttribute("aria-hidden", "true");
        });
    });
})();
</script>
<script>
/* Mobile: tables marked .ui-cards-sm show each row as a card, with column names from the header. */
(function () {
    function label() {
        document.querySelectorAll('table.ui-cards-sm').forEach(function (t) {
            var heads = Array.prototype.map.call(t.querySelectorAll('thead th'), function (th) { return th.innerText.trim(); });
            t.querySelectorAll('tbody tr').forEach(function (tr) {
                var i = 0;
                Array.prototype.forEach.call(tr.children, function (td) {
                    var span = parseInt(td.getAttribute('colspan') || '1', 10);
                    if (!td.hasAttribute('data-label') && heads[i] && span === 1) td.setAttribute('data-label', heads[i]);
                    i += span;
                });
            });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', label); else label();
})();
</script>
