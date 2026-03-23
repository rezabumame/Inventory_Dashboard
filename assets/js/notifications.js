/**
 * Notification Helper using SweetAlert2
 * Replace all alert() with beautiful notifications
 */

// Success notification
function showSuccess(message, title = 'Berhasil!') {
    Swal.fire({
        icon: 'success',
        title: title,
        text: message,
        confirmButtonColor: '#204EAB',
        confirmButtonText: 'OK'
    });
}

// Error notification
function showError(message, title = 'Error!') {
    Swal.fire({
        icon: 'error',
        title: title,
        text: message,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'OK'
    });
}

// Warning notification
function showWarning(message, title = 'Perhatian!') {
    Swal.fire({
        icon: 'warning',
        title: title,
        text: message,
        confirmButtonColor: '#ffc107',
        confirmButtonText: 'OK'
    });
}

// Info notification
function showInfo(message, title = 'Informasi') {
    Swal.fire({
        icon: 'info',
        title: title,
        text: message,
        confirmButtonColor: '#204EAB',
        confirmButtonText: 'OK'
    });
}

// Confirmation dialog
function showConfirm(message, title = 'Konfirmasi', callback) {
    Swal.fire({
        icon: 'question',
        title: title,
        text: message,
        showCancelButton: true,
        confirmButtonColor: '#204EAB',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Ya',
        cancelButtonText: 'Batal'
    }).then((result) => {
        if (result.isConfirmed && callback) {
            callback();
        }
    });
}

// Success with redirect
function showSuccessRedirect(message, url, title = 'Berhasil!') {
    Swal.fire({
        icon: 'success',
        title: title,
        text: message,
        confirmButtonColor: '#204EAB',
        confirmButtonText: 'OK',
        allowOutsideClick: false
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = url;
        }
    });
}

// Error with redirect
function showErrorRedirect(message, url, title = 'Error!') {
    Swal.fire({
        icon: 'error',
        title: title,
        text: message,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'OK',
        allowOutsideClick: false
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = url;
        }
    });
}

// Toast notification (small notification at top-right)
function showToast(message, type = 'success') {
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer)
            toast.addEventListener('mouseleave', Swal.resumeTimer)
        }
    });

    Toast.fire({
        icon: type,
        title: message
    });
}

// Loading overlay
function showLoading(message = 'Memproses...') {
    Swal.fire({
        title: message,
        allowOutsideClick: false,
        allowEscapeKey: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
}

// Close loading
function closeLoading() {
    Swal.close();
}
