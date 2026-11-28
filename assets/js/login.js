/* Login page: password show/hide + submit state. No backend logic here. */
(function () {
    var eye = document.getElementById('eyeBtn'), pw = document.getElementById('password');
    if (eye && pw) {
        eye.addEventListener('click', function () {
            var show = pw.type === 'password';
            pw.type = show ? 'text' : 'password';
            eye.innerHTML = show ? '<i class="bi bi-eye-slash" aria-hidden="true"></i>' : '<i class="bi bi-eye" aria-hidden="true"></i>';
            eye.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            eye.setAttribute('aria-pressed', show ? 'true' : 'false');
        });
    }
    var form = document.getElementById('loginForm'), btn = document.getElementById('loginBtn');
    if (form && btn) {
        form.addEventListener('submit', function (e) {
            if (!form.checkValidity()) { return; }
            btn.disabled = true;
            btn.innerHTML = '<span class="lg-spin" aria-hidden="true"></span> Signing in…';
        });
    }
})();
