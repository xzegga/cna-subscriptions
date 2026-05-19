import React, { useEffect, useRef } from 'react';
import type { SubscriptionActionCopy } from './subscriptionActions';

interface SubscriptionConfirmModalProps {
  open: boolean;
  copy: SubscriptionActionCopy | null;
  loading?: boolean;
  onConfirm: () => void;
  onCancel: () => void;
}

const SubscriptionConfirmModal: React.FC<SubscriptionConfirmModalProps> = ({
  open,
  copy,
  loading = false,
  onConfirm,
  onCancel,
}) => {
  const confirmRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (!open) {
      return;
    }
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && !loading) {
        onCancel();
      }
    };
    document.addEventListener('keydown', onKeyDown);
    confirmRef.current?.focus();
    return () => document.removeEventListener('keydown', onKeyDown);
  }, [open, loading, onCancel]);

  if (!open || !copy) {
    return null;
  }

  return (
    <div
      className="cna-modal-overlay"
      role="presentation"
      onClick={loading ? undefined : onCancel}
    >
      <div
        className="cna-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="cna-modal-title"
        onClick={(event) => event.stopPropagation()}
      >
        <h3 id="cna-modal-title" className="cna-modal__title">
          {copy.title}
        </h3>
        <p className="cna-modal__message">{copy.message}</p>
        <div className="cna-modal__actions">
          <button
            type="button"
            className="cna-modal__btn cna-modal__btn--secondary"
            onClick={onCancel}
            disabled={loading}
          >
            Cancelar
          </button>
          <button
            ref={confirmRef}
            type="button"
            className={`cna-modal__btn ${
              copy.variant === 'danger'
                ? 'cna-modal__btn--danger'
                : 'cna-modal__btn--primary'
            }`}
            onClick={onConfirm}
            disabled={loading}
          >
            {loading ? 'Procesando...' : copy.confirmLabel}
          </button>
        </div>
      </div>
    </div>
  );
};

export default SubscriptionConfirmModal;
