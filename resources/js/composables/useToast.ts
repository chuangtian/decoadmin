import { readonly, ref } from 'vue';
import type { ToastType } from '../types';

export interface ToastMessage {
    id: number;
    type: ToastType;
    title: string;
    message: string;
}

export interface ToastOptions {
    type: ToastType;
    title?: string;
    message: string;
    duration?: number;
}

const toasts = ref<ToastMessage[]>([]);
let nextToastId = 1;

const defaultTitles: Record<ToastType, string> = {
    success: '操作成功',
    error: '操作失败',
    warning: '请注意',
    info: '系统通知',
};

const dismiss = (id: number) => {
    toasts.value = toasts.value.filter((toast) => toast.id !== id);
};

const add = ({ type, title, message, duration = 2000 }: ToastOptions) => {
    const toast: ToastMessage = {
        id: nextToastId++,
        type,
        title: title ?? defaultTitles[type],
        message,
    };

    toasts.value.push(toast);

    if (typeof window !== 'undefined' && duration > 0) {
        window.setTimeout(() => dismiss(toast.id), duration);
    }

    return toast.id;
};

export const useToast = () => ({
    toasts: readonly(toasts),
    add,
    dismiss,
    success: (message: string, title?: string) => add({ type: 'success', title, message }),
    error: (message: string, title?: string) => add({ type: 'error', title, message }),
    warning: (message: string, title?: string) => add({ type: 'warning', title, message }),
    info: (message: string, title?: string) => add({ type: 'info', title, message }),
});
