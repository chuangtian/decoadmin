(() => {
  const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
  const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
  const STATUS_LABELS = {
    UNUSED: 'Status: Unused',
    PARTIALLY_USED: 'Status: Partially used',
    USED_UP: 'Status: Used up',
    EXPIRED: 'Status: Expired',
  };

  function initialize(app) {
    if (app.dataset.initialized === 'true') return;
    app.dataset.initialized = 'true';

    const trigger = app.querySelector('[data-student-discount-trigger]');
    const dialog = app.querySelector('[data-student-discount-dialog]');
    const proxyPath = normalizeProxyPath(app.dataset.proxyPath);
    if (!trigger || !dialog || !proxyPath) return;

    const panel = dialog.querySelector('.student-discount-dialog__panel');
    const views = [...dialog.querySelectorAll('[data-sd-view]')];
    const emailForm = dialog.querySelector('[data-sd-email-form]');
    const emailNameInput = emailForm.querySelector('input[name="fullName"]');
    const emailInput = emailForm.querySelector('input[name="email"]');
    const emailConsentInput = emailForm.querySelector('input[name="privacyConsent"]');
    const emailSubmit = emailForm.querySelector('button[type="submit"]');
    const emailError = dialog.querySelector('[data-sd-email-error]');
    const alternative = dialog.querySelector('[data-sd-alternative]');
    const openIdButton = dialog.querySelector('[data-sd-open-id]');
    const selectEmailButton = dialog.querySelector('[data-sd-select-email]');
    const selectIdButton = dialog.querySelector('[data-sd-select-id]');
    const studentIdForm = dialog.querySelector('[data-sd-id-form]');
    const studentIdInput = studentIdForm.querySelector('input[name="studentId"]');
    const preview = dialog.querySelector('[data-sd-id-preview]');
    const uploadPlaceholder = dialog.querySelector('[data-sd-upload-placeholder]');
    const fileName = dialog.querySelector('[data-sd-file-name]');
    const clearFileButton = dialog.querySelector('[data-sd-clear-file]');
    const studentIdError = dialog.querySelector('[data-sd-id-error]');
    const successTitle = dialog.querySelector('[data-sd-success-title]');
    const successMessage = dialog.querySelector('[data-sd-success-message]');
    const codeElement = dialog.querySelector('[data-sd-code]');
    const codeStatus = dialog.querySelector('[data-sd-code-status]');
    const codeExpiry = dialog.querySelector('[data-sd-code-expiry]');
    const codeTerms = dialog.querySelector('[data-sd-code-terms]');
    const codeMessage = dialog.querySelector('[data-sd-code-message]');
    const copyButton = dialog.querySelector('[data-sd-copy]');
    const copyFeedback = dialog.querySelector('[data-sd-copy-feedback]');

    let campaign = null;
    let activeElement = null;
    let previewUrl = '';
    let emailSubmitting = false;
    let studentIdSubmitting = false;

    const showView = (name) => {
      views.forEach((view) => {
        view.hidden = view.dataset.sdView !== name;
      });
      if (panel) panel.scrollTop = 0;
    };

    const clearSelectedFile = () => {
      if (previewUrl) URL.revokeObjectURL(previewUrl);
      previewUrl = '';
      preview.removeAttribute('src');
      preview.hidden = true;
      fileName.textContent = '';
      fileName.hidden = true;
      clearFileButton.hidden = true;
      uploadPlaceholder.hidden = false;
      studentIdError.hidden = true;
    };

    const validateEmail = () => {
      const name = emailNameInput.value.trim();
      const value = emailInput.value.trim().toLowerCase();
      const emailValid = EMAIL_PATTERN.test(value) && !value.includes('..');
      const nameValid = name.length >= 2 && name.length <= 120;
      const valid = nameValid && emailValid && emailConsentInput.checked;
      emailNameInput.classList.toggle('is-invalid', Boolean(name) && !nameValid);
      emailInput.classList.toggle('is-invalid', Boolean(value) && !emailValid);
      if (name && !nameValid) emailNameInput.setAttribute('aria-invalid', 'true');
      else emailNameInput.removeAttribute('aria-invalid');
      if (value && !emailValid) emailInput.setAttribute('aria-invalid', 'true');
      else emailInput.removeAttribute('aria-invalid');
      emailSubmit.disabled = !valid;
      emailError.hidden = true;
      alternative.hidden = true;
      return valid;
    };

    const openStudentId = () => {
      studentIdForm.querySelector('input[name="fullName"]').value = emailNameInput.value.trim();
      studentIdForm.querySelector('input[name="email"]').value = emailInput.value.trim();
      showView('student-id');
      window.setTimeout(() => studentIdForm.querySelector('input[name="fullName"]').focus(), 0);
    };

    const revealStudentIdAlternative = () => {
      emailError.hidden = true;
      alternative.hidden = false;
      window.setTimeout(() => {
        if (alternative.hidden) return;
        alternative.scrollIntoView({behavior: 'smooth', block: 'nearest'});
        openIdButton.focus({preventScroll: true});
      }, 0);
    };

    const showCode = (payload) => {
      const code = String(payload.code || '').trim();
      codeElement.textContent = code;
      copyButton.dataset.code = code;
      copyButton.textContent = 'Copy code';
      copyFeedback.textContent = '';

      const status = String(payload.status || 'UNUSED').toUpperCase();
      codeStatus.textContent = STATUS_LABELS[status] || `Status: ${status}`;
      codeStatus.hidden = false;

      const expiresAt = payload.expiresAt || payload.expires_at;
      const formattedExpiry = formatDateTime(expiresAt);
      codeExpiry.textContent = formattedExpiry ? `Expires: ${formattedExpiry}` : '';
      codeExpiry.hidden = !formattedExpiry;

      const terms = String(payload.terms || '').trim();
      codeTerms.textContent = terms;
      codeTerms.hidden = !terms;

      codeMessage.classList.toggle('is-warning', payload.sent === false);
      codeMessage.textContent = payload.sent === false
        ? publicMessage(payload.message, 'Your discount was approved, but the email could not be delivered. Your code is available above.')
        : 'We also emailed this discount code to you.';

      showView('code');
      window.setTimeout(() => copyButton.focus(), 0);
    };

    const showSubmission = (title, message) => {
      successTitle.textContent = title;
      successMessage.textContent = publicMessage(
        message,
        'Your request has been submitted. We will email you when the review is complete.',
      );
      showView('success');
    };

    const closeDialog = () => {
      clearSelectedFile();
      dialog.hidden = true;
      document.documentElement.style.overflow = '';
      if (activeElement instanceof HTMLElement) activeElement.focus();
    };

    const copyCode = async () => {
      const code = copyButton.dataset.code || '';
      if (!code) return;

      try {
        await navigator.clipboard.writeText(code);
      } catch {
        const textarea = document.createElement('textarea');
        textarea.value = code;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        textarea.remove();
      }

      copyButton.textContent = 'Copied!';
      copyFeedback.textContent = 'Discount code copied to your clipboard.';
      window.setTimeout(() => {
        copyButton.textContent = 'Copy code';
      }, 2000);
    };

    trigger.addEventListener('click', async () => {
      activeElement = document.activeElement;
      emailForm.reset();
      studentIdForm.reset();
      clearSelectedFile();
      emailNameInput.classList.remove('is-invalid');
      emailNameInput.removeAttribute('aria-invalid');
      emailInput.classList.remove('is-invalid');
      emailInput.removeAttribute('aria-invalid');
      emailSubmit.disabled = true;
      emailError.hidden = true;
      alternative.hidden = true;
      dialog.hidden = false;
      document.documentElement.style.overflow = 'hidden';
      showView('method');

      if (campaign === null) {
        try {
          const payload = await fetchJson(proxyPath, {method: 'GET'});
          if (payload.campaign_enabled !== true) {
            throw new Error(payload.message || 'Student discount is currently unavailable.');
          }
          campaign = payload;
        } catch (error) {
          showSubmission(
            'Student discount unavailable',
            publicMessage(error?.message, 'Please try again later.'),
          );
          return;
        }
      }

      window.setTimeout(() => selectEmailButton.focus(), 0);
    });

    dialog.querySelectorAll('[data-sd-close]').forEach((button) => {
      button.addEventListener('click', closeDialog);
    });
    dialog.querySelector('[data-sd-done]').addEventListener('click', closeDialog);
    dialog.querySelector('[data-sd-code-done]').addEventListener('click', closeDialog);
    copyButton.addEventListener('click', copyCode);

    selectEmailButton.addEventListener('click', () => {
      showView('email');
      window.setTimeout(() => emailInput.focus(), 0);
    });
    selectIdButton.addEventListener('click', openStudentId);
    openIdButton.addEventListener('click', openStudentId);
    dialog.querySelectorAll('[data-sd-back-method]').forEach((button) => {
      button.addEventListener('click', () => {
        showView('method');
        window.setTimeout(() => selectEmailButton.focus(), 0);
      });
    });

    clearFileButton.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      studentIdInput.value = '';
      clearSelectedFile();
      studentIdInput.focus();
    });

    emailNameInput.addEventListener('input', validateEmail);
    emailNameInput.addEventListener('blur', validateEmail);
    emailInput.addEventListener('input', validateEmail);
    emailInput.addEventListener('blur', validateEmail);
    emailConsentInput.addEventListener('change', validateEmail);

    studentIdInput.addEventListener('change', () => {
      clearSelectedFile();
      const file = studentIdInput.files?.[0];
      if (!file) return;

      if (!ALLOWED_IMAGE_TYPES.includes(file.type)) {
        studentIdInput.value = '';
        studentIdError.textContent = 'Please upload a JPG, PNG or WebP image.';
        studentIdError.hidden = false;
        return;
      }

      if (file.size > MAX_IMAGE_BYTES) {
        studentIdInput.value = '';
        studentIdError.textContent = 'The student ID photo must be 5MB or smaller.';
        studentIdError.hidden = false;
        return;
      }

      previewUrl = URL.createObjectURL(file);
      preview.src = previewUrl;
      preview.hidden = false;
      fileName.textContent = file.name;
      fileName.hidden = false;
      clearFileButton.hidden = false;
      uploadPlaceholder.hidden = true;
    });

    preview.addEventListener('error', () => {
      clearSelectedFile();
      studentIdInput.value = '';
      studentIdError.textContent = 'This image could not be previewed. Please choose another file.';
      studentIdError.hidden = false;
    });

    emailForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (emailSubmitting || !validateEmail()) return;

      const originalText = emailSubmit.textContent;
      const controls = [emailNameInput, emailInput, emailConsentInput];
      emailSubmitting = true;
      emailSubmit.disabled = true;
      controls.forEach((control) => {
        control.disabled = true;
      });
      emailSubmit.textContent = 'Submitting…';

      try {
        const payload = await fetchJson(`${proxyPath}/claims`, {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({
            name: emailNameInput.value.trim(),
            email: emailInput.value.trim(),
            privacy_consent: emailConsentInput.checked,
            idempotency_key: createIdempotencyKey(),
          }),
        });

        if (payload.discount?.code) {
          showCode(toCodePayload(payload.discount));
          return;
        }

        showSubmission('Request in progress', 'Your request is already being reviewed. We will email you when it is complete.');
      } catch (error) {
        if (error?.code === 'EVIDENCE_REQUIRED') {
          revealStudentIdAlternative();
        } else {
          emailError.textContent = publicMessage(error?.message, 'Unable to submit your request. Please try again later.');
          emailError.hidden = false;
          alternative.hidden = true;
        }
      } finally {
        emailSubmitting = false;
        controls.forEach((control) => {
          control.disabled = false;
        });
        emailSubmit.textContent = originalText;
        emailSubmit.disabled = !(emailNameInput.value.trim().length >= 2
          && EMAIL_PATTERN.test(emailInput.value.trim())
          && !emailInput.value.trim().includes('..')
          && emailConsentInput.checked);
      }
    });

    studentIdForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (studentIdSubmitting || !studentIdForm.reportValidity()) return;

      const submitButton = studentIdForm.querySelector('button[type="submit"]');
      const originalText = submitButton.textContent;
      const controls = [...studentIdForm.querySelectorAll('input, button')];
      const formData = new FormData(studentIdForm);
      const fullNameInput = studentIdForm.querySelector('input[name="fullName"]');
      const privacyConsentInput = studentIdForm.querySelector('input[name="privacyConsent"]');
      formData.set('name', fullNameInput.value.trim());
      formData.set('privacy_consent', privacyConsentInput.checked ? 'true' : 'false');
      formData.set('evidence', studentIdInput.files[0]);
      formData.set('idempotency_key', createIdempotencyKey());
      formData.delete('studentId');
      formData.delete('fullName');
      formData.delete('privacyConsent');
      formData.delete('website');
      studentIdSubmitting = true;
      studentIdError.hidden = true;
      controls.forEach((control) => {
        control.disabled = true;
      });
      submitButton.textContent = 'Submitting…';

      try {
        const payload = await fetchJson(`${proxyPath}/claims`, {method: 'POST', body: formData});
        if (payload.discount?.code) {
          showCode(toCodePayload(payload.discount));
          return;
        }
        showSubmission(
          payload.status === 'pending' ? 'Submitted for review' : 'Submitted',
          'Your request has been submitted. We will email you when the review is complete.',
        );
      } catch (error) {
        studentIdError.textContent = publicMessage(error?.message, 'Unable to submit your request. Please try again later.');
        studentIdError.hidden = false;
      } finally {
        studentIdSubmitting = false;
        controls.forEach((control) => {
          control.disabled = false;
        });
        submitButton.textContent = originalText;
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !dialog.hidden) closeDialog();
    });
  }

  async function fetchJson(url, options) {
    const response = await fetch(url, {
      ...options,
      headers: {Accept: 'application/json', ...(options.headers || {})},
    });
    const responseText = await response.text();
    let payload = null;
    if (responseText.trim() !== '') {
      try {
        payload = JSON.parse(responseText);
      } catch {
        payload = null;
      }
    }

    const validEnvelope = payload !== null
      && typeof payload === 'object'
      && !Array.isArray(payload)
      && (Object.hasOwn(payload, 'data') || Object.hasOwn(payload, 'error'));

    if (!validEnvelope) {
      const error = new Error('The student discount service returned an invalid response. Please try again later.');
      error.code = 'INVALID_API_RESPONSE';
      error.status = response.status;
      throw error;
    }

    if (!response.ok) {
      const error = new Error(payload.error?.message || 'Request failed.');
      error.code = String(payload.error?.code || 'REQUEST_FAILED');
      error.fields = payload.error?.fields || {};
      error.status = response.status;
      throw error;
    }

    if (payload.data === null || typeof payload.data !== 'object' || Array.isArray(payload.data)) {
      const error = new Error('The student discount service returned an invalid response. Please try again later.');
      error.code = 'INVALID_API_RESPONSE';
      error.status = response.status;
      throw error;
    }

    return payload.data;
  }

  function normalizeProxyPath(value) {
    const path = String(value || '').trim();
    if (/^http:\/\/(?:localhost|127\.0\.0\.1)(?::\d+)?\//.test(path)) {
      return path.replace(/\/$/, '');
    }
    if (!path.startsWith('/') || path.startsWith('//')) return '';
    return path.replace(/\/$/, '');
  }

  function createIdempotencyKey() {
    if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
    return `${Date.now()}-${Math.random().toString(36).slice(2)}`;
  }

  function formatDateTime(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    return new Intl.DateTimeFormat(undefined, {
      dateStyle: 'medium',
      timeStyle: 'short',
    }).format(date);
  }

  function toCodePayload(discount) {
    const usageLimit = Number(discount.usage_limit || 1);
    return {
      ...discount,
      expiresAt: discount.expires_at,
      terms: `Valid for ${usageLimit} use${usageLimit === 1 ? '' : 's'} before the expiry time.`,
    };
  }

  function publicMessage(message, fallback) {
    const value = String(message || '').trim();
    return value && value.length <= 240 ? value : fallback;
  }

  function initializeAll(root = document) {
    root.querySelectorAll('[data-student-discount-app]').forEach(initialize);
  }

  initializeAll();
  document.addEventListener('shopify:section:load', (event) => initializeAll(event.target));
})();
