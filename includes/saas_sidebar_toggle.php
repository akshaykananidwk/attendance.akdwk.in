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
