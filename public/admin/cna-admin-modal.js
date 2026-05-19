/**
 * Modales de confirmación y alerta para el admin de CNA Subscriptions.
 */
(function (window, document) {
  'use strict';

  var l10n = window.cnaAdminModalL10n || {};
  var activeOverlay = null;

  function defaultLabels() {
    return {
      cancel: l10n.cancel || 'Cancelar',
      confirm: l10n.confirm || 'Confirmar',
      accept: l10n.accept || 'Aceptar',
    };
  }

  function closeModal() {
    if (activeOverlay && activeOverlay.parentNode) {
      activeOverlay.parentNode.removeChild(activeOverlay);
    }
    activeOverlay = null;
    document.body.style.overflow = '';
  }

  function buildModal(options) {
    var labels = defaultLabels();
    var isAlert = options.type === 'alert';
    var variant = options.variant || 'default';

    var overlay = document.createElement('div');
    overlay.className = 'cna-modal-overlay';
    overlay.setAttribute('role', 'presentation');

    var modal = document.createElement('div');
    modal.className = 'cna-modal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');

    var title = document.createElement('h2');
    title.className = 'cna-modal__title';
    title.id = 'cna-admin-modal-title';
    title.textContent =
      options.title ||
      (isAlert ? l10n.noticeTitle || 'Aviso' : l10n.confirmTitle || 'Confirmar');
    modal.setAttribute('aria-labelledby', title.id);

    var message = document.createElement('p');
    message.className = 'cna-modal__message';
    message.textContent = options.message || '';

    var actions = document.createElement('div');
    actions.className = 'cna-modal__actions';

    var primaryBtn = document.createElement('button');
    primaryBtn.type = 'button';
    primaryBtn.className =
      'cna-modal__btn ' +
      (variant === 'danger' ? 'cna-modal__btn--danger' : 'cna-modal__btn--primary');
    primaryBtn.textContent =
      options.confirmLabel || (isAlert ? labels.accept : labels.confirm);

    if (!isAlert) {
      var cancelBtn = document.createElement('button');
      cancelBtn.type = 'button';
      cancelBtn.className = 'cna-modal__btn cna-modal__btn--secondary';
      cancelBtn.textContent = options.cancelLabel || labels.cancel;
      cancelBtn.addEventListener('click', function () {
        closeModal();
        if (typeof options.onCancel === 'function') {
          options.onCancel();
        }
      });
      actions.appendChild(cancelBtn);
    }

    primaryBtn.addEventListener('click', function () {
      if (options.loading) {
        return;
      }
      closeModal();
      if (typeof options.onConfirm === 'function') {
        options.onConfirm();
      }
    });

    actions.appendChild(primaryBtn);
    modal.appendChild(title);
    modal.appendChild(message);
    modal.appendChild(actions);
    overlay.appendChild(modal);

    overlay.addEventListener('click', function (event) {
      if (event.target === overlay && !options.loading) {
        closeModal();
        if (!isAlert && typeof options.onCancel === 'function') {
          options.onCancel();
        } else if (isAlert && typeof options.onClose === 'function') {
          options.onClose();
        }
      }
    });

    document.addEventListener('keydown', function onKeyDown(event) {
      if (event.key === 'Escape' && activeOverlay === overlay && !options.loading) {
        document.removeEventListener('keydown', onKeyDown);
        closeModal();
        if (!isAlert && typeof options.onCancel === 'function') {
          options.onCancel();
        } else if (isAlert && typeof options.onClose === 'function') {
          options.onClose();
        }
      }
    });

    return { overlay: overlay, primaryBtn: primaryBtn };
  }

  function openModal(options) {
    closeModal();
    var built = buildModal(options);
    activeOverlay = built.overlay;
    document.body.style.overflow = 'hidden';
    document.body.appendChild(activeOverlay);
    built.primaryBtn.focus();
  }

  window.CNAAdminModal = {
    confirm: function (options) {
      openModal(Object.assign({}, options, { type: 'confirm' }));
    },
    alert: function (options) {
      openModal(
        Object.assign({}, options, {
          type: 'alert',
          variant: options.variant === 'error' ? 'danger' : 'primary',
          onConfirm: function () {
            if (typeof options.onClose === 'function') {
              options.onClose();
            }
          },
        })
      );
    },
    success: function (message, onClose) {
      this.alert({
        title: l10n.successTitle || 'Éxito',
        message: message,
        onClose: onClose,
      });
    },
    error: function (message, onClose) {
      this.alert({
        title: l10n.errorTitle || 'Error',
        message: message,
        variant: 'error',
        onClose: onClose,
      });
    },
    initConfirmForms: function () {
      if (!window.jQuery) {
        return;
      }
      var $ = window.jQuery;
      $(document).on('submit', 'form.cna-confirm-form', function (event) {
        var form = this;
        if (form.dataset.cnaConfirmed === '1') {
          form.dataset.cnaConfirmed = '0';
          return true;
        }
        event.preventDefault();
        CNAAdminModal.confirm({
          title: form.dataset.confirmTitle || l10n.confirmTitle || 'Confirmar',
          message: form.dataset.confirmMessage || l10n.confirmDefault || '¿Deseas continuar?',
          variant: form.dataset.confirmVariant || 'default',
          confirmLabel: form.dataset.confirmLabel,
          onConfirm: function () {
            form.dataset.cnaConfirmed = '1';
            form.submit();
          },
        });
        return false;
      });
    },
  };

  document.addEventListener('DOMContentLoaded', function () {
    CNAAdminModal.initConfirmForms();
  });
})(window, document);
