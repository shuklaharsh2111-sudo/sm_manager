(function () {
    'use strict';

    var root = document.getElementById('smeSettings');
    if (!root) {
        return;
    }

    var endpoint = root.getAttribute('data-endpoint') || 'settings.php';
    var csrfToken = root.getAttribute('data-csrf') || '';
    var MAX_PHOTO_BYTES = 5 * 1024 * 1024;
    var PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /* ------------------------------------------------------------------
       Small helpers
       ------------------------------------------------------------------ */
    function $(id) {
        return document.getElementById(id);
    }

    function each(list, fn) {
        Array.prototype.forEach.call(list, fn);
    }

    var toast = $('stgToast');
    var toastTimer = null;

    function showToast(message, type) {
        if (!toast) {
            return;
        }
        var isError = type === 'error';
        window.clearTimeout(toastTimer);
        toast.setAttribute('role', isError ? 'alert' : 'status');
        toast.className = 'stg-toast stg-toast-' + (isError ? 'error' : 'success');
        toast.textContent = message;
        // Force a reflow so the transition runs when the toast is re-shown.
        void toast.offsetWidth;
        toast.classList.add('is-visible');
        toastTimer = window.setTimeout(function () {
            toast.classList.remove('is-visible');
        }, isError ? 7000 : 4500);
    }

    function setFieldError(form, name, message) {
        var slot = form.querySelector('[data-error-for="' + name + '"]');
        var input = form.elements[name];
        if (slot) {
            slot.textContent = message || '';
        }
        if (input && input.setAttribute) {
            if (message) {
                input.setAttribute('aria-invalid', 'true');
            } else {
                input.removeAttribute('aria-invalid');
            }
        }
    }

    function clearErrors(form) {
        each(form.querySelectorAll('[data-error-for]'), function (slot) {
            setFieldError(form, slot.getAttribute('data-error-for'), '');
        });
    }

    function applyErrors(form, errors) {
        var first = null;
        Object.keys(errors).forEach(function (name) {
            setFieldError(form, name, errors[name]);
            if (!first && form.elements[name]) {
                first = form.elements[name];
            }
        });
        if (first && typeof first.focus === 'function') {
            first.focus();
        }
    }

    function hasErrors(errors) {
        return Object.keys(errors).length > 0;
    }

    function setBusy(button, busy, label) {
        if (!button) {
            return;
        }
        if (busy) {
            if (!button.hasAttribute('data-label')) {
                button.setAttribute('data-label', button.textContent);
            }
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.textContent = label;
        } else {
            button.disabled = false;
            button.removeAttribute('aria-busy');
            button.textContent = button.getAttribute('data-label') || button.textContent;
        }
    }

    /* Posts to settings.php and always resolves with a payload object. */
    function send(formData, button, busyLabel) {
        formData.set('settings_api', '1');
        formData.set('csrf_token', csrfToken);
        setBusy(button, true, busyLabel);

        return window.fetch(endpoint, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
            .then(function (response) {
                return response.text().then(function (text) {
                    var payload = null;
                    try {
                        payload = JSON.parse(text);
                    } catch (error) {
                        payload = null;
                    }
                    if (!payload || typeof payload !== 'object') {
                        payload = {
                            success: false,
                            message: 'The server sent an unexpected response. Try again.'
                        };
                    }
                    payload.status = response.status;
                    return payload;
                });
            })
            .catch(function () {
                return {
                    success: false,
                    status: 0,
                    message: 'We could not reach the server. Check your connection and try again.'
                };
            })
            .then(function (payload) {
                setBusy(button, false);
                if (payload.status === 401) {
                    showToast(payload.message || 'Your session has expired. Sign in again.', 'error');
                    window.setTimeout(function () {
                        window.location.href = '../index.php';
                    }, 1800);
                }
                return payload;
            });
    }

    /* ------------------------------------------------------------------
       Tabs
       ------------------------------------------------------------------ */
    var tabs = root.querySelectorAll('.stg-tab');
    var panels = root.querySelectorAll('.stg-panel');
    var tabNames = [];
    each(tabs, function (tab) {
        tabNames.push(tab.getAttribute('data-panel'));
    });

    function activate(name, moveFocus) {
        if (tabNames.indexOf(name) === -1) {
            name = tabNames[0];
        }
        each(tabs, function (tab) {
            var active = tab.getAttribute('data-panel') === name;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            tab.setAttribute('tabindex', active ? '0' : '-1');
            if (active && moveFocus) {
                tab.focus();
            }
        });
        each(panels, function (panel) {
            panel.hidden = panel.getAttribute('data-panel') !== name;
        });
    }

    each(tabs, function (tab, index) {
        tab.addEventListener('click', function () {
            var name = tab.getAttribute('data-panel');
            activate(name, false);
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', '#' + name);
            }
        });

        tab.addEventListener('keydown', function (event) {
            var next = null;
            if (event.key === 'ArrowDown' || event.key === 'ArrowRight') {
                next = (index + 1) % tabs.length;
            } else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') {
                next = (index - 1 + tabs.length) % tabs.length;
            } else if (event.key === 'Home') {
                next = 0;
            } else if (event.key === 'End') {
                next = tabs.length - 1;
            }
            if (next !== null) {
                event.preventDefault();
                var name = tabs[next].getAttribute('data-panel');
                activate(name, true);
                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', '#' + name);
                }
            }
        });
    });

    window.addEventListener('hashchange', function () {
        activate(window.location.hash.replace('#', ''), false);
    });
    activate(window.location.hash.replace('#', ''), false);

    /* ------------------------------------------------------------------
       Profile
       ------------------------------------------------------------------ */
    var profileForm = $('stgProfileForm');
    var avatar = $('stgAvatar');
    var photoInput = $('stgPhotoInput');
    var photoLabel = $('stgPhotoLabel');
    var photoNote = $('stgPhotoNote');
    var removeButton = $('stgRemovePhoto');
    var previewUrl = null;

    // What is currently saved, so a cancelled selection can be restored.
    var saved = {
        imageUrl: '',
        initial: ''
    };
    if (avatar) {
        var savedImg = avatar.querySelector('img');
        var savedInitial = avatar.querySelector('.stg-avatar-initial');
        saved.imageUrl = savedImg ? savedImg.getAttribute('src') : '';
        saved.initial = savedInitial ? savedInitial.textContent : '';
    }

    function renderAvatar(target, imageUrl, initial, altText) {
        if (!target) {
            return;
        }
        while (target.firstChild) {
            target.removeChild(target.firstChild);
        }
        if (imageUrl) {
            var img = document.createElement('img');
            img.src = imageUrl;
            img.alt = altText || '';
            target.appendChild(img);
        } else {
            var span = document.createElement('span');
            span.className = 'stg-avatar-initial';
            span.textContent = initial || '';
            target.appendChild(span);
        }
    }

    function releasePreview() {
        if (previewUrl) {
            window.URL.revokeObjectURL(previewUrl);
            previewUrl = null;
        }
    }

    function setPending(pending) {
        if (avatar) {
            avatar.classList.toggle('is-pending', pending);
        }
        if (photoNote) {
            photoNote.hidden = !pending;
        }
    }

    function syncPhotoControls() {
        var hasImage = !!saved.imageUrl;
        if (removeButton) {
            removeButton.hidden = !hasImage;
        }
        if (photoLabel) {
            photoLabel.textContent = hasImage ? 'Change photo' : 'Upload photo';
        }
    }

    function restoreSavedAvatar() {
        releasePreview();
        renderAvatar(avatar, saved.imageUrl, saved.initial, 'Your profile photo');
        setPending(false);
    }

    if (photoInput) {
        photoInput.addEventListener('change', function () {
            setFieldError(profileForm, 'prof_image', '');
            var file = photoInput.files && photoInput.files[0];
            if (!file) {
                restoreSavedAvatar();
                return;
            }
            if (PHOTO_TYPES.indexOf(file.type) === -1) {
                photoInput.value = '';
                restoreSavedAvatar();
                setFieldError(profileForm, 'prof_image', 'Use a JPG, PNG, WEBP or GIF image.');
                return;
            }
            if (file.size > MAX_PHOTO_BYTES) {
                photoInput.value = '';
                restoreSavedAvatar();
                setFieldError(profileForm, 'prof_image', 'The photo must be 5 MB or smaller.');
                return;
            }
            releasePreview();
            previewUrl = window.URL.createObjectURL(file);
            renderAvatar(avatar, previewUrl, '', 'Preview of your new profile photo');
            setPending(true);
        });
    }

    /* Applies saved values from the server to the page and the sidebar. */
    function applyProfile(data) {
        if (!data) {
            return;
        }
        saved.imageUrl = data.image_url || '';
        saved.initial = data.initial || saved.initial;

        if (photoInput) {
            photoInput.value = '';
        }
        releasePreview();
        renderAvatar(avatar, saved.imageUrl, saved.initial, 'Your profile photo');
        setPending(false);
        syncPhotoControls();

        if (profileForm) {
            if (typeof data.fullname === 'string' && profileForm.elements.fullname) {
                profileForm.elements.fullname.value = data.fullname;
            }
            if (typeof data.email === 'string' && profileForm.elements.email) {
                profileForm.elements.email.value = data.email;
            }
            if (typeof data.contact === 'string' && profileForm.elements.contact) {
                profileForm.elements.contact.value = data.contact;
            }
        }

        // Keep the sidebar and the greeting in step without a reload.
        renderAvatar($('sidebarAvatar'), saved.imageUrl, saved.initial, '');
        if (typeof data.fullname === 'string' && data.fullname !== '') {
            var sidebarName = $('sidebarName');
            var headerName = $('headerName');
            if (sidebarName) {
                sidebarName.textContent = data.fullname;
            }
            if (headerName) {
                headerName.textContent = data.fullname;
            }
        }
    }

    function validateProfile(form) {
        var errors = {};
        var fullname = form.elements.fullname.value.replace(/\s+/g, ' ').trim();
        var email = form.elements.email.value.trim();
        var contact = form.elements.contact.value.replace(/[\s\-().]+/g, '');

        if (fullname === '') {
            errors.fullname = 'Enter your full name.';
        } else if (!/^[\p{L}\p{M}][\p{L}\p{M} .'\u2019-]{1,99}$/u.test(fullname)) {
            errors.fullname = 'Use 2 to 100 letters. Spaces, dots, hyphens and apostrophes are allowed.';
        }
        if (email === '') {
            errors.email = 'Enter your email address.';
        } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            errors.email = 'Enter a valid email address, like name@example.com.';
        }
        if (contact === '') {
            errors.contact = 'Enter your contact number.';
        } else if (!/^\+?[0-9]{10,12}$/.test(contact)) {
            errors.contact = 'Enter 10 to 12 digits. You can start with +.';
        }
        return errors;
    }

    if (profileForm) {
        each(profileForm.querySelectorAll('.stg-input'), function (input) {
            input.addEventListener('input', function () {
                setFieldError(profileForm, input.name, '');
            });
        });

        profileForm.addEventListener('submit', function (event) {
            event.preventDefault();
            clearErrors(profileForm);

            var errors = validateProfile(profileForm);
            if (hasErrors(errors)) {
                applyErrors(profileForm, errors);
                showToast('Check the highlighted fields and try again.', 'error');
                return;
            }

            var button = profileForm.querySelector('button[type="submit"]');
            send(new window.FormData(profileForm), button, 'Saving').then(function (result) {
                if (result.success) {
                    applyProfile(result.data);
                    showToast(result.message || 'Changes saved.', 'success');
                } else {
                    if (result.errors) {
                        applyErrors(profileForm, result.errors);
                    }
                    showToast(result.message || 'We could not save your changes.', 'error');
                }
            });
        });
    }

    if (removeButton) {
        removeButton.addEventListener('click', function () {
            if (!window.confirm('Remove your profile photo?')) {
                return;
            }
            var data = new window.FormData();
            data.set('action', 'remove_photo');
            send(data, removeButton, 'Removing').then(function (result) {
                if (result.success) {
                    applyProfile({
                        image_url: '',
                        initial: result.data && result.data.initial ? result.data.initial : saved.initial
                    });
                    showToast(result.message || 'Photo removed.', 'success');
                } else {
                    showToast(result.message || 'We could not remove your photo.', 'error');
                }
            });
        });
    }

    /* ------------------------------------------------------------------
       Password
       ------------------------------------------------------------------ */
    var passwordForm = $('stgPasswordForm');

    if (passwordForm) {
        var newPassword = passwordForm.elements.new_password;
        var confirmPassword = passwordForm.elements.confirm_password;
        var strength = $('stgStrength');
        var strengthLabel = $('stgStrengthLabel');
        var reqs = passwordForm.querySelectorAll('#stgReqs li');
        var LEVELS = ['Weak', 'Fair', 'Good', 'Strong'];
        var EMPTY_TEXT = 'Enter a password to see its strength.';

        each(passwordForm.querySelectorAll('.stg-reveal'), function (button) {
            button.addEventListener('click', function () {
                var input = $(button.getAttribute('data-reveal'));
                if (!input) {
                    return;
                }
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                button.textContent = show ? 'Hide' : 'Show';
                button.setAttribute('aria-pressed', show ? 'true' : 'false');
                button.setAttribute('aria-label', button.getAttribute('aria-label').replace(show ? 'Show' : 'Hide', show ? 'Hide' : 'Show'));
            });
        });

        function scorePassword(value) {
            if (value === '') {
                return 0;
            }
            var points = 0;
            if (value.length >= 8) { points += 1; }
            if (value.length >= 12) { points += 1; }
            if (/[a-z]/.test(value) && /[A-Z]/.test(value)) { points += 1; }
            if (/[0-9]/.test(value) && /[A-Za-z]/.test(value)) { points += 1; }
            if (/[^A-Za-z0-9]/.test(value)) { points += 1; }
            // 1 = Weak, 2 = Fair, 3 = Good, 4 or more = Strong.
            return Math.min(4, Math.max(1, points));
        }

        function updateStrength() {
            var value = newPassword.value;
            var level = scorePassword(value);
            if (strength) {
                strength.setAttribute('data-level', String(level));
            }
            if (strengthLabel) {
                strengthLabel.textContent = level === 0 ? EMPTY_TEXT : 'Strength: ' + LEVELS[level - 1];
            }
            var met = {
                length: value.length >= 8,
                letter: /[A-Za-z]/.test(value),
                number: /[0-9]/.test(value)
            };
            each(reqs, function (item) {
                item.classList.toggle('is-met', !!met[item.getAttribute('data-req')]);
            });
        }

        function updateMatch() {
            if (confirmPassword.value === '') {
                setFieldError(passwordForm, 'confirm_password', '');
            } else if (confirmPassword.value !== newPassword.value) {
                setFieldError(passwordForm, 'confirm_password', 'The passwords do not match.');
            } else {
                setFieldError(passwordForm, 'confirm_password', '');
            }
        }

        newPassword.addEventListener('input', function () {
            setFieldError(passwordForm, 'new_password', '');
            updateStrength();
            if (confirmPassword.value !== '') {
                updateMatch();
            }
        });
        confirmPassword.addEventListener('input', updateMatch);
        passwordForm.elements.current_password.addEventListener('input', function () {
            setFieldError(passwordForm, 'current_password', '');
        });

        function validatePassword(form) {
            var errors = {};
            var current = form.elements.current_password.value;
            var next = form.elements.new_password.value;
            var again = form.elements.confirm_password.value;

            if (current === '') {
                errors.current_password = 'Enter your current password.';
            }
            if (next === '') {
                errors.new_password = 'Enter a new password.';
            } else if (next.length < 8 || next.length > 72) {
                errors.new_password = 'Use 8 to 72 characters.';
            } else if (!/[A-Za-z]/.test(next) || !/[0-9]/.test(next)) {
                errors.new_password = 'Include at least one letter and one number.';
            } else if (current !== '' && next === current) {
                errors.new_password = 'Choose a password that is different from your current one.';
            }
            if (again === '') {
                errors.confirm_password = 'Re-enter the new password.';
            } else if (again !== next) {
                errors.confirm_password = 'The passwords do not match.';
            }
            return errors;
        }

        passwordForm.addEventListener('submit', function (event) {
            event.preventDefault();
            clearErrors(passwordForm);

            var errors = validatePassword(passwordForm);
            if (hasErrors(errors)) {
                applyErrors(passwordForm, errors);
                showToast('Check the highlighted fields and try again.', 'error');
                return;
            }

            var button = passwordForm.querySelector('button[type="submit"]');
            send(new window.FormData(passwordForm), button, 'Updating').then(function (result) {
                if (result.success) {
                    passwordForm.reset();
                    // Make sure every field is hidden again after a reset.
                    each(passwordForm.querySelectorAll('.stg-reveal'), function (reveal) {
                        var input = $(reveal.getAttribute('data-reveal'));
                        if (input) {
                            input.type = 'password';
                        }
                        reveal.textContent = 'Show';
                        reveal.setAttribute('aria-pressed', 'false');
                        reveal.setAttribute('aria-label', reveal.getAttribute('aria-label').replace('Hide', 'Show'));
                    });
                    updateStrength();
                    showToast(result.message || 'Password updated.', 'success');
                } else {
                    if (result.errors) {
                        applyErrors(passwordForm, result.errors);
                    }
                    showToast(result.message || 'We could not update your password.', 'error');
                }
            });
        });

        updateStrength();
    }
})();
