import { useRef } from 'react';
import Button from './Button.jsx';
import Modal from './Modal.jsx';
import { UI_TEXT } from './uiText.js';

// The page owns the async work and passes `loading`; while loading the dialog cannot be dismissed.
// For the destructive tone the initial focus goes to the cancel button.
export default function ConfirmDialog({
  open,
  onClose,
  onConfirm,
  title,
  message,
  children,
  confirmLabel,
  cancelLabel,
  tone = 'danger',
  loading = false,
}) {
  const cancelRef = useRef(null);
  const danger = tone === 'danger';

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={title || UI_TEXT.confirm}
      description={message}
      size="sm"
      compact
      role="alertdialog"
      dismissible={!loading}
      initialFocusRef={danger ? cancelRef : undefined}
      footer={
        <>
          <Button variant={danger ? 'danger' : 'primary'} loading={loading} onClick={onConfirm}>
            {confirmLabel || UI_TEXT.confirm}
          </Button>
          <Button ref={cancelRef} variant="outline" disabled={loading} onClick={onClose}>
            {cancelLabel || UI_TEXT.cancel}
          </Button>
        </>
      }
    >
      {children}
    </Modal>
  );
}

export { ConfirmDialog };
