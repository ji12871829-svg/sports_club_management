// Basic client-side validation for registration form
document.addEventListener('DOMContentLoaded', function () {
    const registerForm = document.querySelector('form[action*="register.php"]');
    if (registerForm) {
        registerForm.addEventListener('submit', function (event) {
            let isValid = true;

            // Clear previous errors
            document.querySelectorAll('.invalid-feedback').forEach(el => el.textContent = '');
            document.querySelectorAll('.form-control').forEach(el => el.classList.remove('is-invalid'));

            // Validate First Name
            const firstName = registerForm.querySelector('#first_name');
            if (firstName && firstName.value.trim() === '') {
                isValid = false;
                firstName.classList.add('is-invalid');
                firstName.nextElementSibling.textContent = 'Please enter your first name.';
            }

            // Validate Last Name
            const lastName = registerForm.querySelector('#last_name');
            if (lastName && lastName.value.trim() === '') {
                isValid = false;
                lastName.classList.add('is-invalid');
                lastName.nextElementSibling.textContent = 'Please enter your last name.';
            }

            // Validate Email
            const email = registerForm.querySelector('#email');
            const emailRegex = /^[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,6}$/;
            if (email && (email.value.trim() === '' || !emailRegex.test(email.value.trim()))) {
                isValid = false;
                email.classList.add('is-invalid');
                email.nextElementSibling.textContent = 'Please enter a valid email address.';
            }

            // Validate Password
            const password = registerForm.querySelector('#password');
            if (password && password.value.trim() === '') {
                isValid = false;
                password.classList.add('is-invalid');
                password.nextElementSibling.textContent = 'Please enter a password.';
            } else if (password && password.value.trim().length < 6) {
                isValid = false;
                password.classList.add('is-invalid');
                password.nextElementSibling.textContent = 'Password must have at least 6 characters.';
            }

            // Validate Confirm Password
            const confirmPassword = registerForm.querySelector('#confirm_password');
            if (confirmPassword && confirmPassword.value.trim() === '') {
                isValid = false;
                confirmPassword.classList.add('is-invalid');
                confirmPassword.nextElementSibling.textContent = 'Please confirm password.';
            } else if (password && confirmPassword && password.value !== confirmPassword.value) {
                isValid = false;
                confirmPassword.classList.add('is-invalid');
                confirmPassword.nextElementSibling.textContent = 'Password did not match.';
            }

            if (!isValid) {
                event.preventDefault(); // Prevent form submission if validation fails
            }
        });
    }
});
